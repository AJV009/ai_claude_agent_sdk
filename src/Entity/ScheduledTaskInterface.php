<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Interface for Scheduled Task config entities.
 */
interface ScheduledTaskInterface extends ConfigEntityInterface {

  /**
   * Gets the source type ('skill' or 'prompt').
   */
  public function getSourceType(): string;

  /**
   * Gets the skill ID (when source_type is 'skill').
   */
  public function getSkillId(): string;

  /**
   * Gets the custom prompt text (when source_type is 'prompt').
   */
  public function getPrompt(): string;

  /**
   * Gets the arguments string for skill execution.
   */
  public function getArguments(): string;

  /**
   * Gets the profile ID to use for execution.
   */
  public function getProfileId(): string;

  /**
   * Gets the schedule anchor date/time as ISO string.
   */
  public function getScheduleDate(): string;

  /**
   * Gets the schedule repeat type.
   */
  public function getScheduleType(): string;

  /**
   * Gets the custom interval in seconds (for type 'custom').
   */
  public function getScheduleCustomInterval(): int;

}
