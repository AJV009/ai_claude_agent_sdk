<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Form;

use Drupal\ai_claude_agent_sdk\Entity\AgentSkillInterface;
use Drupal\ai_claude_agent_sdk\Service\ClaudeAgentSdkProcessLimiter;
use Drupal\ai_claude_agent_sdk\Service\ClaudeBridgeService;
use Drupal\ai_claude_agent_sdk\Service\ExecutionEnvelopeService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form to run a skill in background.
 */
class AgentSkillRunForm extends ConfirmFormBase {

  protected ?AgentSkillInterface $skill = NULL;

  public function __construct(
    protected readonly ClaudeBridgeService $bridge,
    protected readonly ExecutionEnvelopeService $envelopeService,
    protected readonly ClaudeAgentSdkProcessLimiter $processLimiter,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_claude_agent_sdk.bridge'),
      $container->get('ai_claude_agent_sdk.execution_envelope'),
      $container->get('ai_claude_agent_sdk.process_limiter'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'agent_skill_run_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Run skill %name in background?', [
      '%name' => $this->skill ? $this->skill->label() : '',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return new Url('entity.agent_skill.collection');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Run');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $agent_skill = NULL): array {
    $this->skill = $agent_skill;

    $form = parent::buildForm($form, $form_state);

    // Profile selector.
    $profiles = $this->entityTypeManager->getStorage('agent_profile')->loadMultiple();
    $options = [];
    foreach ($profiles as $profile) {
      $options[$profile->id()] = $profile->label();
    }
    $form['profile'] = [
      '#type' => 'select',
      '#title' => $this->t('Agent Profile'),
      '#options' => $options,
      '#default_value' => isset($options['default']) ? 'default' : key($options),
      '#required' => TRUE,
      '#weight' => -10,
    ];

    // Arguments field if the skill accepts arguments.
    $hasArguments = FALSE;
    if ($this->skill) {
      $hint = $this->skill->getArgumentHint();
      $body = $this->skill->getSkillBody();
      if ($hint || str_contains($body, '$ARGUMENTS')) {
        $hasArguments = TRUE;
      }
    }

    if ($hasArguments) {
      $form['arguments'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Arguments'),
        '#description' => $this->skill->getArgumentHint() ?: $this->t('Arguments to pass to the skill.'),
        '#maxlength' => 1024,
        '#weight' => -5,
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->skill) {
      $this->messenger()->addError($this->t('Skill not found.'));
      return;
    }

    // Check process limiter.
    if (!$this->processLimiter->canStart()) {
      $status = $this->processLimiter->getStatus();
      $this->messenger()->addError($this->t('Cannot start: concurrent limit reached (@running/@limit).', [
        '@running' => $status['running'],
        '@limit' => $status['limit'],
      ]));
      return;
    }

    $profileId = $form_state->getValue('profile');
    /** @var \Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface|null $profile */
    $profile = $this->entityTypeManager->getStorage('agent_profile')->load($profileId);
    if (!$profile) {
      $this->messenger()->addError($this->t('Profile not found.'));
      return;
    }

    // Build the prompt from skill body.
    $prompt = $this->skill->toSkillMd();
    $arguments = $form_state->getValue('arguments', '');
    if ($arguments) {
      $prompt = str_replace('$ARGUMENTS', $arguments, $prompt);
    }

    // Create execution envelope and build MCP headers.
    $envelope = $this->envelopeService->create($profile);
    $mcpHeaders = $this->envelopeService->buildMcpHeaders($envelope);

    $profileData = $profile->toSidecarFormat();

    try {
      $queryId = $this->bridge->fireAndForget(
        $profileData,
        $prompt,
        null,
        $mcpHeaders,
        [
          'skillId' => $this->skill->id(),
          'initiatorUid' => $envelope['initiator_uid'],
        ],
      );

      $sessionsUrl = Url::fromRoute('ai_claude_agent_sdk.sessions')->toString();
      $this->messenger()->addStatus($this->t('Skill %name started in background (query: @id). <a href="@url">View sessions</a>.', [
        '%name' => $this->skill->label(),
        '@id' => substr($queryId, 0, 8),
        '@url' => $sessionsUrl,
      ]));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to start skill: @error', [
        '@error' => $e->getMessage(),
      ]));
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
