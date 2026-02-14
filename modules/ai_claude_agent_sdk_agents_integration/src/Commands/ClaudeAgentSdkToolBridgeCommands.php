<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk_agents_integration\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\tool\Tool\ToolManager;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Throwable;

/**
 * Drush bridge commands for running Tool API tools from Claude debug flows.
 */
final class ClaudeAgentSdkToolBridgeCommands extends DrushCommands {

  public function __construct(
    private readonly ToolManager $toolManager,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * List Tool API tools and input schemas as JSON.
   */
  #[CLI\Command(name: 'ai-claude-agent-sdk:tool-list')]
  #[CLI\Usage(name: 'drush ai-claude-agent-sdk:tool-list', description: 'List available Tool API tools for Claude bridge mode.')]
  public function toolList(): void {
    $definitions = $this->toolManager->getDefinitions();
    ksort($definitions);

    $tools = [];
    foreach ($definitions as $toolId => $definition) {
      $inputs = [];
      foreach ($definition->getInputDefinitions() as $name => $inputDefinition) {
        $inputs[$name] = [
          'type' => (string) $inputDefinition->getDataType(),
          'label' => (string) $inputDefinition->getLabel(),
          'description' => (string) $inputDefinition->getDescription(),
          'required' => (bool) $inputDefinition->isRequired(),
          'multiple' => (bool) $inputDefinition->isMultiple(),
        ];
      }

      $tools[] = [
        'tool_id' => (string) $toolId,
        'aliases' => [
          'tool:' . (string) $toolId,
          'tool_api:' . (string) $toolId,
        ],
        'label' => (string) $definition->getLabel(),
        'description' => (string) $definition->getDescription(),
        'inputs' => $inputs,
      ];
    }

    $payload = [
      'success' => TRUE,
      'count' => count($tools),
      'tools' => $tools,
    ];

    $this->output()->writeln((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  }

  /**
   * Run a Tool API tool with optional JSON arguments.
   */
  #[CLI\Command(name: 'ai-claude-agent-sdk:tool-run')]
  #[CLI\Argument(name: 'tool_id', description: 'Tool API plugin ID, for example tool_api:entity_type_list')]
  #[CLI\Option(name: 'args', description: 'JSON object of tool input values, for example {"entity_type_id":"node","entity_id":1}')]
  #[CLI\Option(name: 'uid', description: 'Drupal user ID context for access checks and execution. If omitted, uses AI_CLAUDE_AGENT_SDK_BRIDGE_UID env var when set.')]
  #[CLI\Usage(name: 'drush ai-claude-agent-sdk:tool-run tool_api:entity_type_list', description: 'Execute a no-input tool.')]
  #[CLI\Usage(name: 'drush ai-claude-agent-sdk:tool-run tool_api:entity_load_by_id --args=\'{"entity_type_id":"node","entity_id":1}\'', description: 'Execute a tool with JSON args.')]
  public function toolRun(string $tool_id, array $options = ['args' => '{}', 'uid' => NULL]): void {
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
    $argsRaw = (string) ($options['args'] ?? '{}');
    $decoded = json_decode($argsRaw, TRUE);

    if (!is_array($decoded)) {
      $this->writeFailurePayload($tool_id, 'Invalid --args JSON. Expected an object.');
      return;
    }

    $resolvedToolId = $this->resolveToolId($tool_id);
    if ($resolvedToolId === null) {
      $this->writeFailurePayload($tool_id, 'Unknown tool ID.');
      return;
    }

    if (!$this->isToolAllowed($tool_id, $resolvedToolId)) {
      $this->writeFailurePayload($tool_id, 'Tool is not in the bridge allowlist.');
      return;
    }

    try {
      $tool = $this->toolManager->createInstance($resolvedToolId);
    }
    catch (Throwable $e) {
      $this->writeFailurePayload($tool_id, 'Could not create tool instance: ' . $e->getMessage());
      return;
    }

    $inputErrors = [];
    foreach ($decoded as $name => $value) {
      $name = (string) $name;
      if (!$tool->hasInputDefinition($name)) {
        $inputErrors[$name] = 'Unknown input for this tool.';
        continue;
      }
      try {
        $tool->setInputValue($name, $value);
      }
      catch (Throwable $e) {
        $inputErrors[$name] = $e->getMessage();
      }
    }

    if (!empty($inputErrors)) {
      $payload = [
        'success' => FALSE,
        'requested_tool_id' => $tool_id,
        'tool_id' => $resolvedToolId,
        'error' => 'Invalid tool inputs.',
        'input_errors' => $inputErrors,
      ];
      $this->output()->writeln((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
      return;
    }

    try {
      if (!$tool->access()) {
        $this->writeFailurePayload($tool_id, 'Tool access denied for current user.');
        return;
      }

      $tool->execute();
      $result = $tool->getResult();
      $payload = [
        'success' => (bool) $result->isSuccess(),
        'requested_tool_id' => $tool_id,
        'tool_id' => $resolvedToolId,
        'message' => (string) $result->getMessage(),
        'context_values' => $this->normalizeValue($result->getContextValues()),
      ];

      if (!$result->isSuccess()) {
        $payload['error'] = (string) $result->getMessage();
      }

      $this->output()->writeln((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    catch (Throwable $e) {
      $this->writeFailurePayload($tool_id, 'Execution error: ' . $e->getMessage());
    }
    }
    finally {
      if ($switchedAccount) {
        $this->accountSwitcher->switchBack();
      }
    }
  }

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

  private function resolveToolId(string $requestedToolId): ?string {
    if ($this->toolManager->hasDefinition($requestedToolId)) {
      return $requestedToolId;
    }

    $legacyPrefix = 'tool:';
    if (str_starts_with($requestedToolId, $legacyPrefix)) {
      $candidate = substr($requestedToolId, strlen($legacyPrefix));
      if ($candidate !== '' && $this->toolManager->hasDefinition($candidate)) {
        return $candidate;
      }
    }

    $prefix = 'tool_api:';
    if (str_starts_with($requestedToolId, $prefix)) {
      $candidate = substr($requestedToolId, strlen($prefix));
      if ($candidate !== '' && $this->toolManager->hasDefinition($candidate)) {
        return $candidate;
      }
    }

    return null;
  }

  private function writeFailurePayload(string $toolId, string $message): void {
    $payload = [
      'success' => FALSE,
      'tool_id' => $toolId,
      'error' => $message,
    ];
    $this->output()->writeln((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  }

  private function isToolAllowed(string $requestedToolId, string $resolvedToolId): bool {
    $allowlistRaw = trim((string) getenv('AI_CLAUDE_AGENT_SDK_BRIDGE_ALLOWED_TOOLS'));
    if ($allowlistRaw === '') {
      return TRUE;
    }

    $allowed = [];
    $parts = preg_split('/[\s,]+/', $allowlistRaw) ?: [];
    foreach ($parts as $part) {
      $normalized = $this->normalizeToolAlias((string) $part);
      if ($normalized !== null) {
        $allowed[$normalized] = TRUE;
      }
    }

    if (empty($allowed)) {
      return TRUE;
    }

    $requestedNormalized = $this->normalizeToolAlias($requestedToolId);
    if ($requestedNormalized !== null && isset($allowed[$requestedNormalized])) {
      return TRUE;
    }

    return isset($allowed[$resolvedToolId]);
  }

  private function normalizeToolAlias(string $toolId): ?string {
    $toolId = trim($toolId);
    if ($toolId === '') {
      return null;
    }

    if (str_starts_with($toolId, 'tool:')) {
      $toolId = substr($toolId, strlen('tool:'));
    }
    elseif (str_starts_with($toolId, 'tool_api:')) {
      $toolId = substr($toolId, strlen('tool_api:'));
    }

    if ($toolId === '') {
      return null;
    }

    return $toolId;
  }

  private function normalizeValue(mixed $value): mixed {
    if (is_null($value) || is_scalar($value)) {
      return $value;
    }

    if ($value instanceof EntityInterface) {
      return [
        'entity_type' => $value->getEntityTypeId(),
        'id' => $value->id(),
        'uuid' => $value->uuid(),
        'label' => $value->label(),
      ];
    }

    if ($value instanceof \Stringable) {
      return (string) $value;
    }

    if (is_array($value)) {
      $normalized = [];
      foreach ($value as $key => $item) {
        $normalized[$key] = $this->normalizeValue($item);
      }
      return $normalized;
    }

    if ($value instanceof \Traversable) {
      $normalized = [];
      foreach ($value as $key => $item) {
        $normalized[$key] = $this->normalizeValue($item);
      }
      return $normalized;
    }

    if (is_object($value)) {
      return [
        'class' => get_class($value),
      ];
    }

    return (string) $value;
  }

}
