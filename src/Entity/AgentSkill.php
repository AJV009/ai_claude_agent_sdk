<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines the Agent Skill config entity.
 *
 * @ConfigEntityType(
 *   id = "agent_skill",
 *   label = @Translation("Agent Skill"),
 *   label_collection = @Translation("Agent Skills"),
 *   label_singular = @Translation("agent skill"),
 *   label_plural = @Translation("agent skills"),
 *   handlers = {
 *     "list_builder" = "Drupal\ai_claude_agent_sdk\AgentSkillListBuilder",
 *     "form" = {
 *       "add" = "Drupal\ai_claude_agent_sdk\Form\AgentSkillForm",
 *       "edit" = "Drupal\ai_claude_agent_sdk\Form\AgentSkillForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *     },
 *   },
 *   config_prefix = "agent_skill",
 *   admin_permission = "administer claude agent sdk",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "uuid",
 *     "description",
 *     "skill_body",
 *     "disable_model_invocation",
 *     "user_invocable",
 *     "argument_hint",
 *     "allowed_tools",
 *     "context_mode",
 *     "agent_type",
 *   },
 *   links = {
 *     "collection" = "/admin/config/ai/claude-agent-sdk/skills",
 *     "add-form" = "/admin/config/ai/claude-agent-sdk/skills/add",
 *     "edit-form" = "/admin/config/ai/claude-agent-sdk/skills/{agent_skill}/edit",
 *     "delete-form" = "/admin/config/ai/claude-agent-sdk/skills/{agent_skill}/delete",
 *   },
 * )
 */
class AgentSkill extends ConfigEntityBase implements AgentSkillInterface {

  /**
   * The machine name.
   */
  protected string $id = '';

  /**
   * The human-readable label.
   */
  protected string $label = '';

  /**
   * The skill description.
   */
  protected string $description = '';

  /**
   * The SKILL.md body content (markdown instructions).
   */
  protected string $skill_body = '';

  /**
   * Whether model invocation is disabled.
   */
  protected bool $disable_model_invocation = FALSE;

  /**
   * Whether the skill is user-invocable.
   */
  protected bool $user_invocable = TRUE;

  /**
   * The argument hint.
   */
  protected string $argument_hint = '';

  /**
   * The allowed tools.
   *
   * @var string[]
   */
  protected array $allowed_tools = [];

  /**
   * The context mode (inline or fork).
   */
  protected string $context_mode = 'inline';

  /**
   * The subagent type.
   */
  protected string $agent_type = '';

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return $this->description;
  }

  /**
   * {@inheritdoc}
   */
  public function getSkillBody(): string {
    return $this->skill_body;
  }

  /**
   * {@inheritdoc}
   */
  public function getDisableModelInvocation(): bool {
    return $this->disable_model_invocation;
  }

  /**
   * {@inheritdoc}
   */
  public function getUserInvocable(): bool {
    return $this->user_invocable;
  }

  /**
   * {@inheritdoc}
   */
  public function getArgumentHint(): string {
    return $this->argument_hint;
  }

  /**
   * {@inheritdoc}
   */
  public function getAllowedTools(): array {
    return $this->allowed_tools;
  }

  /**
   * {@inheritdoc}
   */
  public function getContextMode(): string {
    return $this->context_mode;
  }

  /**
   * {@inheritdoc}
   */
  public function getAgentType(): string {
    return $this->agent_type;
  }

  /**
   * {@inheritdoc}
   */
  public function toSkillMd(): string {
    $frontmatter = [];
    $frontmatter['name'] = $this->id();
    if ($this->description) {
      $frontmatter['description'] = $this->description;
    }
    if ($this->disable_model_invocation) {
      $frontmatter['disable-model-invocation'] = TRUE;
    }
    if (!$this->user_invocable) {
      $frontmatter['user-invocable'] = FALSE;
    }
    if ($this->argument_hint) {
      $frontmatter['argument-hint'] = $this->argument_hint;
    }
    if ($this->allowed_tools) {
      $frontmatter['allowed-tools'] = $this->allowed_tools;
    }
    if ($this->context_mode && $this->context_mode !== 'inline') {
      $frontmatter['context-mode'] = $this->context_mode;
    }
    if ($this->agent_type) {
      $frontmatter['agent-type'] = $this->agent_type;
    }

    $yaml = \Symfony\Component\Yaml\Yaml::dump($frontmatter, 2, 2);
    $output = "---\n" . $yaml . "---\n";
    if ($this->skill_body) {
      $output .= "\n" . $this->skill_body . "\n";
    }

    return $output;
  }

  /**
   * {@inheritdoc}
   */
  public function postSave($storage, $update = TRUE): void {
    parent::postSave($storage, $update);
    \Drupal::service('ai_claude_agent_sdk.skill_file_sync')->syncSkillToFilesystem($this);
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete($storage, array $entities): void {
    parent::postDelete($storage, $entities);
    $sync = \Drupal::service('ai_claude_agent_sdk.skill_file_sync');
    foreach ($entities as $entity) {
      $sync->removeSkillFromFilesystem($entity->id());
    }
  }

}
