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
    private readonly ToolTransitionLayer $toolTransitionLayer,
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

        // 8b. Build SDK agents map for Mode B sub-agents.
        // Sub-agents with Claude runner enabled become SDK native sub-agents
        // instead of drush delegate-agent skills.
        $tools = $agentEntity->get('tools') ?? [];
        $modeBSubAgentIds = $this->toolTransitionLayer->getModeBSubAgentIds($tools);
        if (!empty($modeBSubAgentIds)) {
          $agents = $this->buildSubAgentsMap($modeBSubAgentIds, $profile);
          if (!empty($agents)) {
            $profileData['agents'] = $agents;
            // Add 'Task' to allowed tools to enable sub-agent spawning.
            $allowedTools = $profileData['allowed_tools'] ?? [];
            if (is_array($allowedTools) && !in_array('Task', $allowedTools, TRUE)) {
              $allowedTools[] = 'Task';
              $profileData['allowed_tools'] = $allowedTools;
            }
          }
        }

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
   * Builds the SDK agents map for Mode B sub-agents.
   *
   * @param string[] $subAgentIds
   *   Array of AiAgent entity IDs with Claude runner enabled.
   * @param \Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface $parentProfile
   *   The parent agent's profile (used for model fallback).
   *
   * @return array
   *   SDK-format agents map: agentId => {description, prompt, tools, maxTurns, model?}.
   */
  private function buildSubAgentsMap(array $subAgentIds, AgentProfileInterface $parentProfile): array {
    $agents = [];
    $agentStorage = $this->entityTypeManager->getStorage('ai_agent');

    foreach ($subAgentIds as $subAgentId) {
      $subAgent = $agentStorage->load($subAgentId);
      if (!$subAgent) {
        continue;
      }

      $subConfig = $subAgent->getThirdPartySetting('ai_claude_agent_sdk_runner', 'config', []);
      $subProfileId = $subConfig['profile_id'] ?? '';

      // Load the sub-agent's profile for model info.
      $subProfile = $subProfileId
        ? $this->entityTypeManager->getStorage('agent_profile')->load($subProfileId)
        : NULL;

      $agentDef = [
        'description' => $subAgent->get('description') ?: $subAgent->label(),
        'prompt' => $this->buildSubAgentPrompt($subAgent, $subProfile),
        'tools' => ['Bash', 'Read', 'Grep', 'Glob'],
      ];

      $maxLoops = (int) ($subAgent->get('max_loops') ?: 0);
      if ($maxLoops > 0) {
        $agentDef['maxTurns'] = $maxLoops;
      }

      // Use the sub-agent's profile model if available, otherwise inherit.
      if ($subProfile && $subProfile->get('model')) {
        $agentDef['model'] = $subProfile->get('model');
      }

      $agents[$subAgentId] = $agentDef;
    }

    return $agents;
  }

  /**
   * Builds the combined prompt for an SDK sub-agent.
   *
   * Includes: system_prompt (with token replacement), tool-call instructions,
   * and translatable settings from the agent configuration.
   *
   * @param object $subAgent
   *   The AiAgent config entity.
   * @param \Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface|null $subProfile
   *   The sub-agent's profile, if any.
   *
   * @return string
   *   The combined sub-agent prompt.
   */
  private function buildSubAgentPrompt(object $subAgent, ?AgentProfileInterface $subProfile): string {
    $parts = [];

    // 1. Profile system prompt (if any).
    if ($subProfile) {
      $profilePrompt = $subProfile->getSystemPrompt();
      if (!empty($profilePrompt)) {
        $parts[] = $profilePrompt;
      }
    }

    // 2. Agent system prompt.
    $entityData = $subAgent->toArray();
    $agentPrompt = $entityData['system_prompt'] ?? '';
    if (!empty($agentPrompt)) {
      // Apply token replacement.
      if (\Drupal::hasService('token')) {
        $agentPrompt = \Drupal::token()->replace($agentPrompt, []);
      }
      $parts[] = "## Agent Instructions\n\n" . $agentPrompt;
    }

    // 3. Tool-call instructions for the agent's configured tools.
    $tools = $subAgent->get('tools') ?? [];
    $enabledTools = array_keys(array_filter($tools));
    if (!empty($enabledTools)) {
      $toolInstructions = $this->buildToolCallInstructions($enabledTools);
      if (!empty($toolInstructions)) {
        $parts[] = $toolInstructions;
      }
    }

    // 4. Translatable settings from agent configuration.
    $settingsInstructions = $this->buildTranslatableSettings($subAgent);
    if (!empty($settingsInstructions)) {
      $parts[] = $settingsInstructions;
    }

    return implode("\n\n", $parts);
  }

  /**
   * Builds drush tool-call instructions for a set of tool IDs.
   */
  private function buildToolCallInstructions(array $toolIds): string {
    $nativeTools = [];
    foreach ($toolIds as $toolId) {
      // Skip sub-agent tools — those are handled by the SDK agent system.
      if (str_starts_with($toolId, 'ai_agents::ai_agent::')) {
        continue;
      }
      $nativeTools[] = $toolId;
    }

    if (empty($nativeTools)) {
      return '';
    }

    $lines = ["## Available Drupal Tools\n"];
    $lines[] = "You can execute Drupal tools using the following drush commands:\n";

    foreach ($nativeTools as $toolId) {
      $lines[] = "- `drush ai-claude-agent-sdk:tool-run {$toolId} --args='<JSON>'`";
    }

    $lines[] = "\nTool results are returned as JSON with `success`, `message`, and `context_values` fields.";
    return implode("\n", $lines);
  }

  /**
   * Builds prompt instructions from translatable agent settings.
   */
  private function buildTranslatableSettings(object $subAgent): string {
    $entityData = $subAgent->toArray();
    $parts = [];

    // require_usage: tools that must be used before responding.
    $tools = $entityData['tools'] ?? [];
    $requiredTools = [];
    foreach ($tools as $toolId => $config) {
      if (is_array($config) && !empty($config['require_usage'])) {
        $requiredTools[] = $toolId;
      }
    }
    if (!empty($requiredTools)) {
      $parts[] = "**Required tools:** You MUST use the following tools before giving your final answer: " . implode(', ', $requiredTools);
    }

    // structured_output: output format constraints.
    if (!empty($entityData['structured_output_enabled']) && !empty($entityData['structured_output_schema'])) {
      $parts[] = "**Output format:** Return your response in this JSON format:\n```json\n"
        . (is_string($entityData['structured_output_schema'])
          ? $entityData['structured_output_schema']
          : json_encode($entityData['structured_output_schema'], JSON_PRETTY_PRINT))
        . "\n```";
    }

    // description_override: custom tool descriptions.
    foreach ($tools as $toolId => $config) {
      if (is_array($config) && !empty($config['description_override'])) {
        $parts[] = "**Tool `{$toolId}` note:** " . $config['description_override'];
      }
    }

    if (empty($parts)) {
      return '';
    }

    return "## Agent Settings\n\n" . implode("\n\n", $parts);
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
