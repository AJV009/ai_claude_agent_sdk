<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk_runner\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * Converts Drupal Tool API tools into AgentRunnerSkill config entities.
 *
 * Called on AiAgent entity save (via hook_entity_presave from the .module file)
 * to sync the agent's configured tools as SKILL.md files.
 *
 * Handles three tool categories:
 * - Native tools (ai_agent:*, etc.) → skill with drush tool-run instructions.
 * - Sub-agent tools (ai_agents::ai_agent::X) without Claude runner → skill
 *   with drush delegate-agent instructions (Mode A: full ReACT loop).
 * - Sub-agent tools (ai_agents::ai_agent::X) with Claude runner → skip skill
 *   creation; handled by SDK native sub-agents (Mode B) in the decorator.
 */
class ToolTransitionLayer {

  /**
   * Prefix for sub-agent tool IDs in the ai_agents plugin system.
   */
  private const SUB_AGENT_PREFIX = 'ai_agents::ai_agent::';

  public function __construct(
    protected readonly DefaultPluginManager $toolManager,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Syncs all tools from an AiAgent config into AgentRunnerSkill entities.
   *
   * @param string $agentId
   *   The AiAgent config entity ID.
   * @param array $tools
   *   The agent's tools array (associative: tool_id => TRUE).
   */
  public function syncToolsForAgent(string $agentId, array $tools): void {
    $configuredTools = array_keys(array_filter($tools));
    $storage = $this->entityTypeManager->getStorage('agent_runner_skill');
    // Track which tool IDs we actually create skills for (pruning uses this).
    $activeToolIds = [];

    foreach ($configuredTools as $toolId) {
      // Route sub-agent tools separately from native tools.
      if ($this->isSubAgentTool($toolId)) {
        $subAgentId = $this->extractSubAgentId($toolId);
        if (!$subAgentId) {
          continue;
        }

        // Mode B: sub-agent has Claude runner → skip skill, decorator handles.
        if ($this->subAgentHasClaudeRunner($subAgentId)) {
          continue;
        }

        // Mode A: sub-agent without Claude runner → delegate-agent skill.
        $this->syncSubAgentSkill($agentId, $toolId, $subAgentId, $storage);
        $activeToolIds[] = $toolId;
        continue;
      }

      // Native tools: original behavior (drush tool-run skill).
      $definition = $this->getToolDefinition($toolId);
      if (!$definition) {
        continue;
      }

      $skillId = $this->buildSkillId($agentId, $toolId);
      $skill = $storage->load($skillId);

      if (!$skill) {
        $skill = $storage->create(['id' => $skillId]);
      }

      if ($skill->isNew() || $skill->get('auto_generated')) {
        $skill->set('label', $definition['label'] ?? $toolId);
        $skill->set('agent_id', $agentId);
        $skill->set('source_tool_id', $toolId);
        $skill->set('description', $definition['description'] ?? '');
        $skill->set('skill_body', $this->generateSkillBody($toolId, $definition));
        $skill->set('input_schema', $definition['input_schema'] ?? []);
        $skill->set('auto_generated', TRUE);
      }

      $skill->save();
      $activeToolIds[] = $toolId;
    }

    $this->pruneOrphanedSkills($agentId, $activeToolIds);
  }

  /**
   * Removes all runner skills for an agent.
   */
  public function removeSkillsForAgent(string $agentId): void {
    $skills = $this->loadSkillsByAgent($agentId);
    foreach ($skills as $skill) {
      $skill->delete();
    }
  }

  /**
   * Loads all runner skills for a given agent.
   *
   * @return \Drupal\ai_claude_agent_sdk_runner\Entity\AgentRunnerSkillInterface[]
   */
  public function loadSkillsByAgent(string $agentId): array {
    $storage = $this->entityTypeManager->getStorage('agent_runner_skill');
    $ids = $storage->getQuery()
      ->condition('agent_id', $agentId)
      ->accessCheck(FALSE)
      ->execute();
    return $ids ? $storage->loadMultiple($ids) : [];
  }

  /**
   * Gets a tool's definition from the plugin manager.
   *
   * @return array|null
   *   The tool definition array, or NULL if not found.
   */
  protected function getToolDefinition(string $toolId): ?array {
    try {
      return $this->toolManager->getDefinition($toolId);
    }
    catch (\Exception) {
      return NULL;
    }
  }

  /**
   * Checks if a tool ID is a sub-agent reference.
   */
  protected function isSubAgentTool(string $toolId): bool {
    return str_starts_with($toolId, self::SUB_AGENT_PREFIX);
  }

  /**
   * Extracts the AiAgent config entity ID from a sub-agent tool ID.
   */
  protected function extractSubAgentId(string $toolId): ?string {
    if (!$this->isSubAgentTool($toolId)) {
      return NULL;
    }
    $id = substr($toolId, strlen(self::SUB_AGENT_PREFIX));
    return $id !== '' ? $id : NULL;
  }

  /**
   * Checks if a sub-agent has Claude Code runner enabled.
   */
  protected function subAgentHasClaudeRunner(string $subAgentId): bool {
    $agentStorage = $this->entityTypeManager->getStorage('ai_agent');
    $subAgent = $agentStorage->load($subAgentId);
    if (!$subAgent) {
      return FALSE;
    }
    $config = $subAgent->getThirdPartySetting('ai_claude_agent_sdk_runner', 'config', []);
    return !empty($config['enabled']) && !empty($config['profile_id']);
  }

  /**
   * Creates or updates a delegate-agent skill for a Mode A sub-agent.
   */
  protected function syncSubAgentSkill(string $parentAgentId, string $toolId, string $subAgentId, $storage): void {
    $subAgent = $this->entityTypeManager->getStorage('ai_agent')->load($subAgentId);
    if (!$subAgent) {
      return;
    }

    $skillId = $this->buildSkillId($parentAgentId, $toolId);
    $skill = $storage->load($skillId);

    if (!$skill) {
      $skill = $storage->create(['id' => $skillId]);
    }

    if ($skill->isNew() || $skill->get('auto_generated')) {
      $label = $subAgent->label() ?: $subAgentId;
      $description = $subAgent->get('description') ?: 'Delegated AI Agent: ' . $label;

      $skill->set('label', $label);
      $skill->set('agent_id', $parentAgentId);
      $skill->set('source_tool_id', $toolId);
      $skill->set('description', $description);
      $skill->set('skill_body', $this->generateDelegateSkillBody($subAgentId, $label, $description));
      $skill->set('input_schema', [
        'type' => 'object',
        'properties' => [
          'task' => [
            'type' => 'string',
            'description' => 'The task or prompt to delegate to this agent.',
          ],
        ],
        'required' => ['task'],
      ]);
      $skill->set('auto_generated', TRUE);
    }

    $skill->save();
  }

  /**
   * Returns IDs of sub-agents that have Claude runner enabled (Mode B).
   *
   * Used by the decorator to build the SDK agents map.
   *
   * @param array $tools
   *   The agent's tools array (tool_id => TRUE).
   *
   * @return array
   *   Array of sub-agent entity IDs that should use Mode B.
   */
  public function getModeBSubAgentIds(array $tools): array {
    $modeBIds = [];
    foreach (array_keys(array_filter($tools)) as $toolId) {
      if (!$this->isSubAgentTool($toolId)) {
        continue;
      }
      $subAgentId = $this->extractSubAgentId($toolId);
      if ($subAgentId && $this->subAgentHasClaudeRunner($subAgentId)) {
        $modeBIds[] = $subAgentId;
      }
    }
    return $modeBIds;
  }

  /**
   * Generates SKILL.md body for a delegate-agent (Mode A) sub-agent.
   */
  protected function generateDelegateSkillBody(string $subAgentId, string $label, string $description): string {
    return <<<MD
## Sub-Agent: {$label}

{$description}

## Usage

Delegate a task to this agent. It runs a full ReACT loop with its own AI
provider, tools, and configuration. All agent settings (tool constraints,
role masquerading, max loops, etc.) are fully enforced.

```bash
drush ai-claude-agent-sdk:delegate-agent {$subAgentId} --task='<TASK_DESCRIPTION>'
```

## How it works

- The agent receives your task and reasons about how to solve it
- It has its own set of Drupal tools and AI provider configuration
- It executes tools, checks results, and loops until the task is complete
- The result is returned as JSON with `success`, `response`, and optional `tool_results`

## Important

- Describe the task clearly and specifically in the --task parameter
- The agent runs synchronously — wait for the JSON response before proceeding
- The response JSON `success` field indicates whether the task was completed
- If `success` is false, check the `error` field for details
MD;
  }

  /**
   * Generates SKILL.md body content from a tool definition.
   */
  protected function generateSkillBody(string $toolId, array $definition): string {
    $label = $definition['label'] ?? $toolId;
    $description = $definition['description'] ?? '';
    $schema = $definition['input_schema'] ?? [];

    $paramDocs = $this->formatParamDocs($schema);
    $requiredParams = $this->formatRequiredParams($schema);

    return <<<MD
## Tool: {$label}

{$description}

## Usage

Execute this tool via the Drupal tool bridge:

```bash
drush ai-claude-agent-sdk:tool-run {$toolId} --args='<JSON>'
```

## Parameters

{$paramDocs}

**Required:** {$requiredParams}

## Important

- Always provide valid JSON for the --args parameter
- The JSON must match the parameter schema above
- Tool results are returned as JSON with `success`, `tool_id`, `message`, and `context_values` fields
MD;
  }

  /**
   * Builds a config entity ID from agent and tool IDs.
   */
  protected function buildSkillId(string $agentId, string $toolId): string {
    return '_agent_' . $agentId . '__' . str_replace([':', '.', '-'], '_', $toolId);
  }

  /**
   * Removes skills for tools no longer in the agent's config.
   */
  protected function pruneOrphanedSkills(string $agentId, array $currentToolIds): void {
    $existingSkills = $this->loadSkillsByAgent($agentId);
    foreach ($existingSkills as $skill) {
      if (!in_array($skill->getSourceToolId(), $currentToolIds, TRUE)) {
        $skill->delete();
      }
    }
  }

  /**
   * Formats parameter documentation from JSON Schema.
   */
  protected function formatParamDocs(array $schema): string {
    $properties = $schema['properties'] ?? [];
    if (empty($properties)) {
      return 'No parameters.';
    }

    $lines = [];
    foreach ($properties as $name => $prop) {
      $type = $prop['type'] ?? 'mixed';
      $desc = $prop['description'] ?? '';
      $lines[] = "- `{$name}` ({$type}): {$desc}";
    }
    return implode("\n", $lines);
  }

  /**
   * Formats required parameters list.
   */
  protected function formatRequiredParams(array $schema): string {
    $required = $schema['required'] ?? [];
    if (empty($required)) {
      return 'None';
    }
    return implode(', ', array_map(fn($p) => "`{$p}`", $required));
  }

}
