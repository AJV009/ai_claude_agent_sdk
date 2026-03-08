<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk;

use Drupal\ai_claude_agent_sdk\Service\AgentSkillScheduler;
use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * List builder for Scheduled Task config entities.
 */
final class ScheduledTaskListBuilder extends ConfigEntityListBuilder {

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    protected readonly AgentSkillScheduler $scheduler,
  ) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('ai_claude_agent_sdk.scheduler'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['status'] = $this->t('Status');
    $header['label'] = $this->t('Label');
    $header['source'] = $this->t('Source');
    $header['schedule'] = $this->t('Schedule');
    $header['next_run'] = $this->t('Next Run');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\ai_claude_agent_sdk\Entity\ScheduledTaskInterface $entity */
    $row['status'] = $entity->status() ? $this->t('Enabled') : $this->t('Disabled');
    $row['label'] = $entity->label();
    $row['source'] = $this->formatSource($entity);
    $row['schedule'] = $this->formatSchedule($entity);
    $row['next_run'] = $this->formatNextRun($entity->id());
    return $row + parent::buildRow($entity);
  }

  /**
   * Format the source column.
   */
  private function formatSource(EntityInterface $entity): string {
    /** @var \Drupal\ai_claude_agent_sdk\Entity\ScheduledTaskInterface $entity */
    if ($entity->getSourceType() === 'skill') {
      $skillStorage = \Drupal::entityTypeManager()->getStorage('agent_skill');
      $skill = $skillStorage->load($entity->getSkillId());
      $skillLabel = $skill ? $skill->label() : $entity->getSkillId();
      return (string) $this->t('Skill: @name', ['@name' => $skillLabel]);
    }
    $prompt = $entity->getPrompt();
    $truncated = mb_strlen($prompt) > 40 ? mb_substr($prompt, 0, 40) . '...' : $prompt;
    return (string) $this->t('Prompt: @text', ['@text' => $truncated]);
  }

  /**
   * Format the schedule column.
   */
  private function formatSchedule(EntityInterface $entity): string {
    /** @var \Drupal\ai_claude_agent_sdk\Entity\ScheduledTaskInterface $entity */
    $date = $entity->getScheduleDate();
    $type = $entity->getScheduleType();

    $time = '';
    if ($date) {
      try {
        $dt = new \DateTimeImmutable($date);
        $time = $dt->format('g:i A');
      }
      catch (\Exception) {
        $time = '?';
      }
    }

    return match ($type) {
      'once' => (string) $this->t('Once: @date', ['@date' => $date ? (new \DateTimeImmutable($date))->format('M j') . ' at ' . $time : '—']),
      'daily' => (string) $this->t('Daily at @time', ['@time' => $time]),
      'weekly' => (string) $this->t('Weekly (@day) at @time', [
        '@day' => $date ? (new \DateTimeImmutable($date))->format('l') : '?',
        '@time' => $time,
      ]),
      'monthly' => (string) $this->t('Monthly (@day) at @time', [
        '@day' => $date ? $this->ordinal((int) (new \DateTimeImmutable($date))->format('j')) : '?',
        '@time' => $time,
      ]),
      'yearly' => (string) $this->t('Yearly (@date) at @time', [
        '@date' => $date ? (new \DateTimeImmutable($date))->format('M j') : '?',
        '@time' => $time,
      ]),
      'weekday' => (string) $this->t('Weekdays at @time', ['@time' => $time]),
      'custom' => $this->formatCustomInterval($entity->getScheduleCustomInterval()),
      default => $type,
    };
  }

  /**
   * Format a custom interval in human-readable form.
   */
  private function formatCustomInterval(int $seconds): string {
    if ($seconds < 3600) {
      $minutes = (int) ($seconds / 60);
      return (string) $this->t('Every @n min', ['@n' => $minutes]);
    }
    $hours = (int) ($seconds / 3600);
    return (string) $this->t('Every @n hr', ['@n' => $hours]);
  }

  /**
   * Format the next run column.
   */
  private function formatNextRun(string $taskId): string {
    $nextRun = $this->scheduler->getNextRun($taskId);
    if ($nextRun === 0) {
      return '—';
    }
    $now = \Drupal::time()->getRequestTime();
    $diff = $nextRun - $now;
    if ($diff < 0) {
      return (string) $this->t('overdue');
    }
    if ($diff < 60) {
      return (string) $this->t('in < 1 min');
    }
    if ($diff < 3600) {
      $minutes = (int) ($diff / 60);
      return (string) $this->t('in @n min', ['@n' => $minutes]);
    }
    if ($diff < 86400) {
      $hours = (int) ($diff / 3600);
      return (string) $this->t('in @n hours', ['@n' => $hours]);
    }
    $days = (int) ($diff / 86400);
    return (string) $this->t('in @n days', ['@n' => $days]);
  }

  /**
   * Get ordinal suffix for a day number.
   */
  private function ordinal(int $day): string {
    $suffixes = ['th', 'st', 'nd', 'rd'];
    $mod100 = $day % 100;
    $suffix = ($mod100 >= 11 && $mod100 <= 13) ? 'th' : ($suffixes[$day % 10] ?? 'th');
    return $day . $suffix;
  }

}
