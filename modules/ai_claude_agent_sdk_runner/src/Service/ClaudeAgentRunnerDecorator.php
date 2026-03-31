<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk_runner\Service;

use Drupal\ai_assistant_api\Service\AgentRunner;
use Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface;
use Drupal\ai_claude_agent_sdk\Service\ClaudeBridgeServiceInterface;
use Drupal\ai_claude_agent_sdk\Service\ExecutionEnvelopeServiceInterface;
use Drupal\ai_claude_agent_sdk\Exception\ExecutionPrincipalException;
use Drupal\ai_claude_agent_sdk\Execution\ExecutionContext;
use Drupal\ai_claude_agent_sdk\Service\ExecutionPrincipalResolver;
use Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Decorates AgentRunner to route Claude Code-enabled agents through the sidecar.
 *
 * Uses asynchronous fireAndForget() with the full security pipeline:
 * - Security tier enforcement (hooks, sandbox, permission rules via buildRequestPayload)
 * - Execution Principal (account switching via ExecutionPrincipalResolver)
 * - MCP headers with executor UID (via ExecutionEnvelopeService)
 * - Session resumption for multi-turn conversations
 * - Async polling via ExecutionStore + ClaudeRunnerPollController
 */
class ClaudeAgentRunnerDecorator {

  public function __construct(
    private readonly AgentRunner $inner,
    private readonly ClaudeBridgeServiceInterface $bridge,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AiAgentManager $pluginManager,
    private readonly ExecutionStore $executionStore,
    private readonly ResultMapper $resultMapper,
    private readonly ExecutionEnvelopeServiceInterface $envelopeService,
    private readonly ExecutionPrincipalResolver $principalResolver,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Intercepts agent execution and routes to Claude Code when configured.
   */
  public function runAsAgent(
    string $assistant_id,
    array $chat_history,
    array $defaults,
    string $job_id,
    bool $verbose_mode = FALSE,
    array $context = [],
  ): ChatOutput {
    // 1. Create plugin instance.
    try {
      $plugin = $this->pluginManager->createInstance($assistant_id);
    }
    catch (\Exception) {
      return $this->fallthrough($assistant_id, $chat_history, $defaults, $job_id, $verbose_mode, $context);
    }

    // 2. Only config entity agents can have third-party settings.
    if (!$plugin instanceof ConfigAiAgentInterface || !method_exists($plugin, 'getAiAgentEntity')) {
      return $this->fallthrough($assistant_id, $chat_history, $defaults, $job_id, $verbose_mode, $context);
    }

    // 3. Check if Claude Code runner is enabled.
    $agentEntity = $plugin->getAiAgentEntity();
    $config = $agentEntity->getThirdPartySetting('ai_claude_agent_sdk_runner', 'config', []);
    $enabled = !empty($config['enabled']);
    $profileId = $config['profile_id'] ?? '';

    if (!$enabled || !$profileId) {
      return $this->fallthrough($assistant_id, $chat_history, $defaults, $job_id, $verbose_mode, $context);
    }

    // 4. Load the AgentProfile.
    /** @var \Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface|null $profile */
    $profile = $this->entityTypeManager->getStorage('agent_profile')->load($profileId);
    if (!$profile) {
      return $this->fallthrough($assistant_id, $chat_history, $defaults, $job_id, $verbose_mode, $context);
    }

    // 5. Check for pending permission requests first.
    // If the user typed a message while a permission is pending,
    // route it as a deny-with-guidance rather than starting a new execution.
    $pendingPermission = $this->executionStore->getOldestPendingPermission($job_id);
    if ($pendingPermission !== NULL) {
      $userMessage = $this->extractUserPrompt($chat_history);
      $this->resolvePermissionViaMessage(
        $pendingPermission['query_id'],
        $pendingPermission['request_id'],
        $userMessage,
      );

      $truncated = mb_strimwidth($userMessage, 0, 100, '...');
      $markerHtml = '<p class="claude-permission-resolved">Response sent: '
        . htmlspecialchars($truncated) . '</p>';
      $message = new ChatMessage('assistant', $markerHtml);
      return new ChatOutput($message, [$markerHtml], []);
    }

    // 6. Check if there is already a running execution for this job.
    if ($this->executionStore->isRunning($job_id)) {
      $message = new ChatMessage('assistant', 'A Claude Code execution is already in progress for this conversation.');
      return new ChatOutput($message, [$message->getText()], []);
    }

    // 6. Execute under the Execution Principal's identity.
    // executeAs() handles account switching and guaranteed cleanup.
    // This enforces Gate 2 (Drupal permissions) — all tool callbacks
    // will run under the executor's roles and access checks.
    try {
      return $this->principalResolver->executeAs($profile, 'interactive', function (ExecutionContext $ctx) use ($profile, $agentEntity, $plugin, $chat_history, $job_id, $assistant_id, $profileId) {
        // 7. Create execution envelope (populates tempstore + MCP headers).
        $envelope = $this->envelopeService->create($profile);
        $mcpHeaders = $this->envelopeService->buildMcpHeaders($envelope);

        // 8. Build prompts and profile data.
        // toSidecarFormat() returns the profile array that buildRequestPayload()
        // uses to inject security tier hooks, sandbox, permission rules, etc.
        $userPrompt = $this->extractUserPrompt($chat_history);
        $profileData = $profile->toSidecarFormat();
        $profileData['systemPrompt'] = $this->buildSystemPrompt($agentEntity, $profile, $plugin);

        // 9. Check for existing session to resume (multi-turn conversation).
        $resumeSessionId = $this->executionStore->getLastSessionId($job_id, $profileId);

        // 10. Generate callback token and URL for async webhook delivery.
        $callbackToken = bin2hex(random_bytes(16));
        $internalBase = $this->configFactory->get('ai_claude_agent_sdk.settings')->get('site_base_url') ?: 'http://localhost';
        $callbackUrl = rtrim($internalBase, '/') . '/api/claude-runner/webhook';

        // 11. Fire-and-forget to the sidecar.
        // This calls buildRequestPayload() internally which injects:
        // - Security tier hooks (Gate 1: policy endpoint)
        // - Sandbox settings
        // - Permission rules (allow/deny)
        // - Managed settings
        // - Executor UID env var (AI_CLAUDE_AGENT_SDK_BRIDGE_UID)
        $queryId = $this->bridge->fireAndForget(
          $profileData,
          $userPrompt,
          $resumeSessionId,
          $mcpHeaders,
          [
            'initiatorUid' => $ctx->getUid(),
            'callbackUrl' => $callbackUrl,
            'callbackToken' => $callbackToken,
          ],
          $ctx->getUid(),
        );

        // 12. Record the execution in the store for poll-based tracking.
        $this->executionStore->create(
          $queryId,
          $assistant_id,
          $profileId,
          $job_id,
          $callbackToken,
          (int) $ctx->getUid(),
        );

        // 13. Return marker HTML for the frontend to pick up and poll.
        // Uses <p> and <code> tags that survive Xss::filter(). The JS library
        // adds the status text and progress list — no text here to avoid duplication.
        $markerHtml = '<p class="claude-async-execution"><code class="claude-async-query-id">' . htmlspecialchars($queryId) . '</code></p>';
        $message = new ChatMessage('assistant', $markerHtml);
        return new ChatOutput($message, [$markerHtml], [
          'runner' => 'claude_code',
          'query_id' => $queryId,
          'executor_uid' => $ctx->getUid(),
          'async' => TRUE,
        ]);
      });
    }
    catch (ExecutionPrincipalException $e) {
      $message = new ChatMessage('assistant', 'Execution principal error: ' . $e->getMessage());
      return new ChatOutput($message, [$message->getText()], []);
    }
    catch (\Exception $e) {
      $message = new ChatMessage('assistant', 'Claude Code execution failed: ' . $e->getMessage());
      return new ChatOutput($message, [$message->getText()], []);
    }
  }

  /**
   * Falls through to the inner (original) AgentRunner.
   */
  private function fallthrough(
    string $assistant_id,
    array $chat_history,
    array $defaults,
    string $job_id,
    bool $verbose_mode,
    array $context,
  ): ChatOutput {
    return $this->inner->runAsAgent($assistant_id, $chat_history, $defaults, $job_id, $verbose_mode, $context);
  }

  /**
   * Extracts the last user message from chat history.
   */
  private function extractUserPrompt(array $chat_history): string {
    foreach (array_reverse($chat_history) as $entry) {
      if (($entry['role'] ?? '') === 'user') {
        return $entry['message'] ?? '';
      }
    }
    return '';
  }

  /**
   * Combines profile and agent system prompts with token replacement.
   */
  private function buildSystemPrompt(
    object $agentEntity,
    AgentProfileInterface $profile,
    ConfigAiAgentInterface $plugin,
  ): string {
    $profilePrompt = $profile->getSystemPrompt();
    // Get raw system prompt from entity config, not the plugin.
    // The plugin's getSystemPrompt() triggers default information tool
    // execution which has side effects and permission requirements.
    $entityData = $agentEntity->toArray();
    $agentPrompt = $entityData['system_prompt'] ?? '';

    if (empty($agentPrompt)) {
      return $profilePrompt;
    }

    // Apply token replacement from plugin context.
    $tokenContexts = $plugin->getTokenContexts();
    if (!empty($tokenContexts) && \Drupal::hasService('token')) {
      $agentPrompt = \Drupal::token()->replace($agentPrompt, $tokenContexts);
    }

    return $profilePrompt . "\n\n"
      . "## AI Agent Instructions\n\n"
      . "The following instructions come from the AI Agent configuration "
      . "that delegated this task to you. Follow these instructions for "
      . "the specifics of what you need to accomplish.\n\n"
      . $agentPrompt;
  }

  /**
   * Resolves a pending permission request by denying with a user message.
   *
   * Forwards the decision to the sidecar's permission-response endpoint.
   */
  private function resolvePermissionViaMessage(string $queryId, string $requestId, string $userMessage): void {
    // Mark resolved in DB.
    $this->executionStore->resolvePermissionRequest($queryId, $requestId, 'deny', $userMessage);

    // Forward to sidecar.
    $sidecarUrl = $this->configFactory->get('ai_claude_agent_sdk.settings')->get('sidecar_url') ?: 'http://localhost:3578';
    try {
      \Drupal::httpClient()->post(rtrim($sidecarUrl, '/') . '/api/query/permission-response', [
        'json' => [
          'queryId' => $queryId,
          'requestId' => $requestId,
          'behavior' => 'deny',
          'message' => $userMessage,
        ],
        'timeout' => 10,
      ]);
    }
    catch (\Exception) {
      // Sidecar may have restarted — execution is lost. Fail silently.
    }
  }

}
