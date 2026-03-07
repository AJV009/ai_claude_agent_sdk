<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Interface for Agent Skill config entities.
 */
interface AgentSkillInterface extends ConfigEntityInterface {

  /**
   * Gets the skill description.
   */
  public function getDescription(): string;

  /**
   * Gets the SKILL.md body content (markdown instructions).
   */
  public function getSkillBody(): string;

  /**
   * Whether model invocation is disabled.
   */
  public function getDisableModelInvocation(): bool;

  /**
   * Whether the skill is user-invocable (shows in / menu).
   */
  public function getUserInvocable(): bool;

  /**
   * Gets the argument hint (e.g., "[issue-number]").
   */
  public function getArgumentHint(): string;

  /**
   * Gets the allowed tools list.
   *
   * @return string[]
   */
  public function getAllowedTools(): array;

  /**
   * Gets the context mode (inline or fork).
   */
  public function getContextMode(): string;

  /**
   * Gets the subagent type.
   */
  public function getAgentType(): string;

  /**
   * Generates the complete SKILL.md content (YAML frontmatter + body).
   */
  public function toSkillMd(): string;

}
