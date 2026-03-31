<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Plugin\QueueWorker;

use Drupal\ai_claude_agent_sdk\Service\AgentSkillScheduler;
use Drupal\ai_claude_agent_sdk\Service\ClaudeAgentSdkProcessLimiter;
use Drupal\ai_claude_agent_sdk\Service\ClaudeBridgeService;
use Drupal\ai_claude_agent_sdk\Service\ExecutionEnvelopeService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes scheduled task queue items.
 *
 * @QueueWorker(
 *   id = "agent_skill_schedule",
 *   title = @Translation("Scheduled Task Processor"),
 *   cron = {"time" = 60}
 * )
 */
class AgentSkillScheduleWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly ClaudeBridgeService $bridge,
    protected readonly ExecutionEnvelopeService $envelopeService,
    protected readonly ClaudeAgentSdkProcessLimiter $processLimiter,
    protected readonly AgentSkillScheduler $scheduler,
    protected readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('ai_claude_agent_sdk.bridge'),
      $container->get('ai_claude_agent_sdk.execution_envelope'),
      $container->get('ai_claude_agent_sdk.process_limiter'),
      $container->get('ai_claude_agent_sdk.scheduler'),
      $container->get('logger.factory')->get('ai_claude_agent_sdk'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $taskId = $data['task_id'] ?? '';
    if (!$taskId) {
      return;
    }

    /** @var \Drupal\ai_claude_agent_sdk\Entity\ScheduledTaskInterface|null $task */
    $task = $this->entityTypeManager->getStorage('scheduled_task')->load($taskId);

    // Task deleted or disabled while queued.
    if (!$task || !$task->status()) {
      return;
    }

    // Check process limiter — defer all items if full.
    if (!$this->processLimiter->canStart()) {
      throw new SuspendQueueException('Process limit reached, deferring scheduled tasks.');
    }

    // Check overlap — skip if still running.
    if ($this->scheduler->isTaskRunning($taskId)) {
      $this->logger->notice('Scheduled task @id skipped: previous run still active.', [
        '@id' => $taskId,
      ]);
      return;
    }

    // Load profile.
    $profileId = $task->getProfileId();
    /** @var \Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface|null $profile */
    $profile = $this->entityTypeManager->getStorage('agent_profile')->load($profileId);
    if (!$profile) {
      $this->logger->error('Scheduled task @id: profile @profile not found.', [
        '@id' => $taskId,
        '@profile' => $profileId,
      ]);
      return;
    }

    // Build prompt.
    $prompt = '';
    $skillId = '';

    if ($task->getSourceType() === 'skill') {
      $skillId = $task->getSkillId();
      /** @var \Drupal\ai_claude_agent_sdk\Entity\AgentSkillInterface|null $skill */
      $skill = $this->entityTypeManager->getStorage('agent_skill')->load($skillId);
      if (!$skill) {
        $this->logger->error('Scheduled task @id: skill @skill not found.', [
          '@id' => $taskId,
          '@skill' => $skillId,
        ]);
        return;
      }
      $prompt = $skill->toSkillMd();
      $arguments = $task->getArguments();
      if ($arguments) {
        $prompt = str_replace('$ARGUMENTS', $arguments, $prompt);
      }
    }
    else {
      $prompt = $task->getPrompt();
    }

    if (!$prompt) {
      $this->logger->error('Scheduled task @id: empty prompt.', ['@id' => $taskId]);
      return;
    }

    // Create execution envelope.
    $initiatorUid = $profile->getExecutorUid() ?: 1;
    $envelope = $this->envelopeService->create($profile, $initiatorUid);
    $mcpHeaders = $this->envelopeService->buildMcpHeaders($envelope);
    $profileData = $profile->toSidecarFormat();

    try {
      $queryId = $this->bridge->fireAndForget(
        $profileData,
        $prompt,
        null,
        $mcpHeaders,
        [
          'skillId' => $skillId,
          'taskId' => $taskId,
          'initiatorUid' => $envelope['initiator_uid'],
        ],
      );

      $this->logger->info('Scheduled task @id started (query: @query).', [
        '@id' => $taskId,
        '@query' => $queryId,
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Scheduled task @id failed: @error', [
        '@id' => $taskId,
        '@error' => $e->getMessage(),
      ]);
    }
  }

}
