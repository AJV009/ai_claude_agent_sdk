<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines the Scheduled Task config entity.
 *
 * @ConfigEntityType(
 *   id = "scheduled_task",
 *   label = @Translation("Scheduled Task"),
 *   label_collection = @Translation("Scheduled Tasks"),
 *   label_singular = @Translation("scheduled task"),
 *   label_plural = @Translation("scheduled tasks"),
 *   handlers = {
 *     "list_builder" = "Drupal\ai_claude_agent_sdk\ScheduledTaskListBuilder",
 *     "form" = {
 *       "add" = "Drupal\ai_claude_agent_sdk\Form\ScheduledTaskForm",
 *       "edit" = "Drupal\ai_claude_agent_sdk\Form\ScheduledTaskForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *     },
 *   },
 *   config_prefix = "scheduled_task",
 *   admin_permission = "administer claude agent sdk",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *     "status" = "status",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "uuid",
 *     "status",
 *     "source_type",
 *     "skill_id",
 *     "prompt",
 *     "arguments",
 *     "profile_id",
 *     "schedule_date",
 *     "schedule_type",
 *     "schedule_custom_interval",
 *   },
 *   links = {
 *     "collection" = "/admin/config/ai/claude-agent-sdk/scheduled-tasks",
 *     "add-form" = "/admin/config/ai/claude-agent-sdk/scheduled-tasks/add",
 *     "edit-form" = "/admin/config/ai/claude-agent-sdk/scheduled-tasks/{scheduled_task}/edit",
 *     "delete-form" = "/admin/config/ai/claude-agent-sdk/scheduled-tasks/{scheduled_task}/delete",
 *   },
 * )
 */
class ScheduledTask extends ConfigEntityBase implements ScheduledTaskInterface {

  /**
   * The machine name.
   */
  protected string $id = '';

  /**
   * The human-readable label.
   */
  protected string $label = '';

  /**
   * The source type: 'skill' or 'prompt'.
   */
  protected string $source_type = 'skill';

  /**
   * The skill ID (when source_type is 'skill').
   */
  protected string $skill_id = '';

  /**
   * The custom prompt text (when source_type is 'prompt').
   */
  protected string $prompt = '';

  /**
   * The arguments string for skill execution.
   */
  protected string $arguments = '';

  /**
   * The profile ID to use for execution.
   */
  protected string $profile_id = 'default';

  /**
   * The schedule anchor date/time as ISO string.
   */
  protected string $schedule_date = '';

  /**
   * The schedule repeat type.
   */
  protected string $schedule_type = 'once';

  /**
   * The custom interval in seconds (for type 'custom').
   */
  protected int $schedule_custom_interval = 3600;

  /**
   * {@inheritdoc}
   */
  public function getSourceType(): string {
    return $this->source_type;
  }

  /**
   * {@inheritdoc}
   */
  public function getSkillId(): string {
    return $this->skill_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getPrompt(): string {
    return $this->prompt;
  }

  /**
   * {@inheritdoc}
   */
  public function getArguments(): string {
    return $this->arguments;
  }

  /**
   * {@inheritdoc}
   */
  public function getProfileId(): string {
    return $this->profile_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getScheduleDate(): string {
    return $this->schedule_date;
  }

  /**
   * {@inheritdoc}
   */
  public function getScheduleType(): string {
    return $this->schedule_type;
  }

  /**
   * {@inheritdoc}
   */
  public function getScheduleCustomInterval(): int {
    return $this->schedule_custom_interval;
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete($storage, array $entities): void {
    parent::postDelete($storage, $entities);
    $scheduler = \Drupal::service('ai_claude_agent_sdk.scheduler');
    foreach ($entities as $entity) {
      $scheduler->clearNextRun($entity->id());
    }
  }

}
