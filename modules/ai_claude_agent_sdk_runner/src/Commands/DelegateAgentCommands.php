<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk_runner\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drupal\ai_agents\Task\Task;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Throwable;

/**
 * Drush command to delegate a task to an AI Agent's full ReACT loop.
 *
 * This runs the agent synchronously — Claude Code calls this and waits for the
 * result. The agent uses its own AI provider/model and all PHP-enforced
 * settings (force_value, hide_property, return_directly, role masquerading,
 * max_loops, etc.) are fully respected.
 *
 * This is "Mode A" of the sub-agent delegation architecture: the sub-agent
 * runs its complete ReACT loop in PHP, not through Claude Code.
 */
final class DelegateAgentCommands extends DrushCommands {

  public function __construct(
    private readonly AiAgentManager $aiAgentManager,
    private readonly AiProviderPluginManager $aiProviderPluginManager,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Delegate a task to an AI Agent, running its full ReACT loop synchronously.
   */
  #[CLI\Command(name: 'ai-claude-agent-sdk:delegate-agent')]
  #[CLI\Argument(name: 'agent_id', description: 'AI Agent config entity ID (e.g. content_type_agent_triage)')]
  #[CLI\Option(name: 'task', description: 'The task/prompt to delegate to the agent')]
  #[CLI\Option(name: 'uid', description: 'Drupal user ID context for execution. If omitted, uses AI_CLAUDE_AGENT_SDK_BRIDGE_UID env var when set.')]
  #[CLI\Option(name: 'provider', description: 'AI provider plugin ID override (e.g. anthropic). If omitted, uses system default for chat_with_tools.')]
  #[CLI\Option(name: 'model', description: 'Model ID override (e.g. claude-sonnet-4-20250514). If omitted, uses system default.')]
  #[CLI\Usage(name: 'drush ai-claude-agent-sdk:delegate-agent content_type_agent --task="Create an article content type"', description: 'Run a ReACT agent synchronously.')]
  #[CLI\Usage(name: 'drush ai-claude-agent-sdk:delegate-agent field_agent --task="Add a body field to article" --uid=1', description: 'Delegate with user context.')]
  public function delegateAgent(string $agent_id, array $options = ['task' => '', 'uid' => NULL, 'provider' => NULL, 'model' => NULL]): void {
    $task = trim((string) ($options['task'] ?? ''));
    if ($task === '') {
      $this->writeFailurePayload($agent_id, 'The --task option is required.');
      return;
    }

    $executionUid = $this->resolveExecutionUid($options);
    $switchedAccount = FALSE;

    if ($executionUid > 0) {
      $account = $this->entityTypeManager->getStorage('user')->load($executionUid);
      if ($account) {
        $this->accountSwitcher->switchTo($account);
        $switchedAccount = TRUE;
      }
    }

    try {
      $this->runAgent($agent_id, $task, $options);
    }
    finally {
      if ($switchedAccount) {
        $this->accountSwitcher->switchBack();
      }
    }
  }

  /**
   * Runs the agent's ReACT loop and outputs JSON result.
   */
  private function runAgent(string $agent_id, string $task, array $options): void {
    // 1. Create agent plugin instance.
    try {
      $agent = $this->aiAgentManager->createInstance($agent_id);
    }
    catch (Throwable $e) {
      $this->writeFailurePayload($agent_id, 'Could not create agent instance: ' . $e->getMessage());
      return;
    }

    if (!$agent instanceof ConfigAiAgentInterface) {
      $this->writeFailurePayload($agent_id, 'Agent must be a config entity agent (ConfigAiAgentInterface).');
      return;
    }

    // 2. Set up the task.
    $agentTask = new Task($task);
    $agent->setTask($agentTask);
    $agent->setCreateDirectly(TRUE);

    // 3. Set AI provider — use overrides or system defaults.
    try {
      $this->configureAiProvider($agent, $options);
    }
    catch (Throwable $e) {
      $this->writeFailurePayload($agent_id, 'Could not configure AI provider: ' . $e->getMessage());
      return;
    }

    // 4. Run the ReACT loop.
    try {
      $solvability = $agent->determineSolvability();
    }
    catch (Throwable $e) {
      $this->writeFailurePayload($agent_id, 'Agent execution error: ' . $e->getMessage());
      return;
    }

    // 5. Get the result based on solvability.
    $response = '';
    $success = FALSE;

    switch ($solvability) {
      case AiAgentInterface::JOB_SOLVABLE:
        $response = $agent->solve();
        $success = TRUE;
        break;

      case AiAgentInterface::JOB_SHOULD_ANSWER_QUESTION:
        $response = $agent->answerQuestion();
        $success = TRUE;
        break;

      case AiAgentInterface::JOB_NOT_SOLVABLE:
        $response = 'Agent determined the task is not solvable.';
        break;

      case AiAgentInterface::JOB_NEEDS_ANSWERS:
        $questions = $agent->askQuestion();
        $response = 'Agent needs more information: ' . (is_array($questions) ? json_encode($questions) : (string) $questions);
        break;

      case AiAgentInterface::JOB_INFORMS:
        $response = $agent->inform();
        $success = TRUE;
        break;

      default:
        $response = 'Unknown solvability status: ' . $solvability;
        break;
    }

    // 6. Build the output payload.
    $payload = [
      'success' => $success,
      'agent_id' => $agent_id,
      'solvability' => $solvability,
      'response' => (string) $response,
    ];

    // Include tool results if available.
    $toolResults = $agent->getToolResults(TRUE);
    if (!empty($toolResults)) {
      $payload['tool_results'] = $this->normalizeToolResults($toolResults);
    }

    // Include structured output if available.
    try {
      $structured = $agent->getStructuredOutput();
      if ($structured && method_exists($structured, 'toArray')) {
        $structuredData = $structured->toArray();
        if (!empty($structuredData)) {
          $payload['structured_output'] = $structuredData;
        }
      }
    }
    catch (Throwable) {
      // Structured output not available — ignore.
    }

    if (!$success) {
      $payload['error'] = $response;
    }

    $this->output()->writeln((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  }

  /**
   * Configures the AI provider on the agent.
   */
  private function configureAiProvider(AiAgentInterface $agent, array $options): void {
    $providerId = $options['provider'] ?? NULL;
    $modelId = $options['model'] ?? NULL;

    if ($providerId && $modelId) {
      $provider = $this->aiProviderPluginManager->createInstance($providerId);
      $agent->setAiProvider($provider);
      $agent->setModelName($modelId);
    }
    elseif ($agent->getAiProvider() === NULL) {
      // Try chat_with_tools first, then chat.
      $defaults = $this->aiProviderPluginManager->getDefaultProviderForOperationType('chat_with_tools');
      if (empty($defaults['provider_id'])) {
        $defaults = $this->aiProviderPluginManager->getDefaultProviderForOperationType('chat');
      }
      if (empty($defaults['provider_id'])) {
        throw new \RuntimeException(
          'No default AI provider configured for chat_with_tools or chat. '
          . 'Either configure a default provider in AI settings or pass --provider and --model options.'
        );
      }
      $provider = $this->aiProviderPluginManager->createInstance($defaults['provider_id']);
      $agent->setAiProvider($provider);
      $agent->setModelName($defaults['model_id']);
    }

    $agent->setAiConfiguration([]);
  }

  /**
   * Resolves the execution UID from options or environment.
   */
  private function resolveExecutionUid(array $options): int {
    $optionUidRaw = $options['uid'] ?? NULL;
    if ($optionUidRaw !== NULL && $optionUidRaw !== '') {
      return max(0, (int) $optionUidRaw);
    }

    $envUidRaw = getenv('AI_CLAUDE_AGENT_SDK_BRIDGE_UID');
    if (is_string($envUidRaw) && trim($envUidRaw) !== '') {
      return max(0, (int) $envUidRaw);
    }

    return 0;
  }

  /**
   * Normalizes tool results for JSON output.
   */
  private function normalizeToolResults(array $toolResults): array {
    $normalized = [];
    foreach ($toolResults as $result) {
      $entry = [];
      if (isset($result['plugin_id'])) {
        $entry['plugin_id'] = (string) $result['plugin_id'];
      }
      if (isset($result['agent_id'])) {
        $entry['agent_id'] = (string) $result['agent_id'];
      }
      if (isset($result['output'])) {
        $entry['output'] = is_string($result['output']) ? $result['output'] : json_encode($result['output']);
      }
      if (isset($result['dump']) && is_array($result['dump'])) {
        $entry['sub_agent'] = TRUE;
      }
      $normalized[] = $entry;
    }
    return $normalized;
  }

  /**
   * Writes a JSON failure payload to stdout.
   */
  private function writeFailurePayload(string $agentId, string $message): void {
    $payload = [
      'success' => FALSE,
      'agent_id' => $agentId,
      'error' => $message,
    ];
    $this->output()->writeln((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  }

}
