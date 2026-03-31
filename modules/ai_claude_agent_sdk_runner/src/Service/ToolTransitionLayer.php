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
 */
class ToolTransitionLayer {

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

    foreach ($configuredTools as $toolId) {
      $definition = $this->getToolDefinition($toolId);
      if (!$definition) {
        continue;
      }

      $skillId = $this->buildSkillId($agentId, $toolId);
      $skill = $storage->load($skillId);

      if (!$skill) {
        $skill = $storage->create(['id' => $skillId]);
      }

      // Only regenerate if auto_generated is TRUE (user hasn't customized).
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
    }

    $this->pruneOrphanedSkills($agentId, $configuredTools);
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
