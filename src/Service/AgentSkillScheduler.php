<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Service;

use Drupal\ai_claude_agent_sdk\Entity\ScheduledTaskInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;

/**
 * Service that enqueues due scheduled tasks during cron.
 */
final class AgentSkillScheduler {

  public const QUEUE_NAME = 'agent_skill_schedule';

  private const STATE_PREFIX = 'ai_claude_agent_sdk.task_next_run.';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StateInterface $state,
    private readonly QueueFactory $queueFactory,
    private readonly ClaudeBridgeService $bridge,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function runCron(): void {
    $count = $this->enqueueScheduledTasks();
    if ($count > 0) {
      \Drupal::logger('ai_claude_agent_sdk')->info('Scheduled tasks: @count task(s) enqueued.', [
        '@count' => $count,
      ]);
    }
  }

  /**
   * Load enabled tasks, check next_run, enqueue due items.
   *
   * @return int
   *   Number of tasks enqueued.
   */
  public function enqueueScheduledTasks(): int {
    $now = $this->time->getRequestTime();
    $queue = $this->queueFactory->get(self::QUEUE_NAME);

    /** @var \Drupal\ai_claude_agent_sdk\Entity\ScheduledTaskInterface[] $tasks */
    $tasks = $this->entityTypeManager->getStorage('scheduled_task')->loadMultiple();

    $enqueued = 0;
    foreach ($tasks as $task) {
      if (!$task->status()) {
        continue;
      }

      $nextRun = $this->getNextRun($task->id());

      // 0 means exhausted (one-time task already run).
      if ($nextRun === 0) {
        continue;
      }

      // Not yet due.
      if ($nextRun > $now) {
        continue;
      }

      // Check overlap — skip if previous run is still active.
      if ($this->isTaskRunning($task->id())) {
        continue;
      }

      $queue->createItem([
        'task_id' => $task->id(),
        'enqueued_at' => $now,
      ]);

      // Calculate and store the next run.
      $scheduleType = $task->getScheduleType();
      if ($scheduleType === 'once') {
        $this->setNextRun($task->id(), 0);
      }
      else {
        $newNextRun = $this->calculateNextRun(
          $scheduleType,
          $task->getScheduleDate(),
          $now,
          $task->getScheduleCustomInterval(),
        );
        $this->setNextRun($task->id(), $newNextRun ?? 0);
      }

      $enqueued++;
    }

    return $enqueued;
  }

  /**
   * Calculate the next run timestamp based on schedule type.
   *
   * @param string $type
   *   Schedule type (daily, weekly, monthly, yearly, weekday, custom, once).
   * @param string $anchorDate
   *   ISO datetime anchor (e.g., '2026-03-08T09:00:00').
   * @param int $afterTs
   *   Timestamp after which to find the next occurrence.
   * @param int $customInterval
   *   Interval in seconds for 'custom' type.
   *
   * @return int|null
   *   Next run timestamp, or NULL if no more runs.
   */
  public function calculateNextRun(string $type, string $anchorDate, int $afterTs, int $customInterval = 3600): ?int {
    if (!$anchorDate) {
      return NULL;
    }

    try {
      $anchor = new \DateTimeImmutable($anchorDate);
    }
    catch (\Exception) {
      return NULL;
    }

    $after = (new \DateTimeImmutable())->setTimestamp($afterTs);

    switch ($type) {
      case 'once':
        $ts = $anchor->getTimestamp();
        return $ts > $afterTs ? $ts : NULL;

      case 'daily':
        // Next occurrence of anchor's time-of-day.
        $candidate = $after->setTime((int) $anchor->format('H'), (int) $anchor->format('i'), 0);
        if ($candidate->getTimestamp() <= $afterTs) {
          $candidate = $candidate->modify('+1 day');
        }
        return $candidate->getTimestamp();

      case 'weekly':
        // Next occurrence of anchor's day-of-week + time.
        $targetDow = (int) $anchor->format('N'); // 1=Mon, 7=Sun.
        $candidate = $after->setTime((int) $anchor->format('H'), (int) $anchor->format('i'), 0);
        $currentDow = (int) $candidate->format('N');
        $daysAhead = ($targetDow - $currentDow + 7) % 7;
        if ($daysAhead === 0 && $candidate->getTimestamp() <= $afterTs) {
          $daysAhead = 7;
        }
        $candidate = $candidate->modify("+{$daysAhead} days");
        return $candidate->getTimestamp();

      case 'monthly':
        // Next occurrence of anchor's day-of-month + time.
        $targetDay = (int) $anchor->format('j');
        $candidate = $after->setTime((int) $anchor->format('H'), (int) $anchor->format('i'), 0);

        // Try this month first.
        $daysInMonth = (int) $candidate->format('t');
        $day = min($targetDay, $daysInMonth);
        $thisMonth = $candidate->setDate(
          (int) $candidate->format('Y'),
          (int) $candidate->format('n'),
          $day,
        );
        if ($thisMonth->getTimestamp() > $afterTs) {
          return $thisMonth->getTimestamp();
        }

        // Next month.
        $nextMonth = $candidate->modify('first day of next month');
        $daysInNextMonth = (int) $nextMonth->format('t');
        $day = min($targetDay, $daysInNextMonth);
        $nextMonth = $nextMonth->setDate(
          (int) $nextMonth->format('Y'),
          (int) $nextMonth->format('n'),
          $day,
        );
        return $nextMonth->getTimestamp();

      case 'yearly':
        // Next occurrence of anchor's month+day + time.
        $targetMonth = (int) $anchor->format('n');
        $targetDay = (int) $anchor->format('j');
        $candidate = $after->setTime((int) $anchor->format('H'), (int) $anchor->format('i'), 0);

        // Try this year.
        $year = (int) $candidate->format('Y');
        $daysInMonth = (int) (new \DateTimeImmutable("{$year}-{$targetMonth}-01"))->format('t');
        $day = min($targetDay, $daysInMonth);
        $thisYear = $candidate->setDate($year, $targetMonth, $day);
        if ($thisYear->getTimestamp() > $afterTs) {
          return $thisYear->getTimestamp();
        }

        // Next year.
        $year++;
        $daysInMonth = (int) (new \DateTimeImmutable("{$year}-{$targetMonth}-01"))->format('t');
        $day = min($targetDay, $daysInMonth);
        $nextYear = $candidate->setDate($year, $targetMonth, $day);
        return $nextYear->getTimestamp();

      case 'weekday':
        // Next Mon–Fri at anchor's time.
        $candidate = $after->setTime((int) $anchor->format('H'), (int) $anchor->format('i'), 0);
        if ($candidate->getTimestamp() <= $afterTs) {
          $candidate = $candidate->modify('+1 day');
        }
        // Skip weekends.
        $dow = (int) $candidate->format('N');
        if ($dow === 6) {
          $candidate = $candidate->modify('+2 days');
        }
        elseif ($dow === 7) {
          $candidate = $candidate->modify('+1 day');
        }
        return $candidate->getTimestamp();

      case 'custom':
        $interval = max(60, $customInterval);
        return $afterTs + $interval;
    }

    return NULL;
  }

  /**
   * Recalculate and store the initial next_run for a task.
   *
   * Called after entity save.
   */
  public function recalculateAndStore(ScheduledTaskInterface $task): void {
    if (!$task->status()) {
      $this->setNextRun($task->id(), 0);
      return;
    }

    $now = $this->time->getRequestTime();
    $type = $task->getScheduleType();
    $anchorDate = $task->getScheduleDate();

    if ($type === 'once') {
      try {
        $anchor = new \DateTimeImmutable($anchorDate);
        $ts = $anchor->getTimestamp();
        $this->setNextRun($task->id(), $ts > $now ? $ts : 0);
      }
      catch (\Exception) {
        $this->setNextRun($task->id(), 0);
      }
      return;
    }

    $nextRun = $this->calculateNextRun($type, $anchorDate, $now, $task->getScheduleCustomInterval());
    $this->setNextRun($task->id(), $nextRun ?? 0);
  }

  /**
   * Check if a task is currently running in the sidecar.
   */
  public function isTaskRunning(string $taskId): bool {
    try {
      $queries = $this->bridge->fetchQueries();
      foreach ($queries as $query) {
        if (isset($query['taskId']) && $query['taskId'] === $taskId && ($query['status'] ?? '') === 'running') {
          return TRUE;
        }
      }
    }
    catch (\Exception) {
      // Sidecar unreachable — assume not running to allow execution.
      return FALSE;
    }
    return FALSE;
  }

  /**
   * Get the next run timestamp for a task from State API.
   */
  public function getNextRun(string $taskId): int {
    return (int) $this->state->get(self::STATE_PREFIX . $taskId, 0);
  }

  /**
   * Set the next run timestamp for a task in State API.
   */
  public function setNextRun(string $taskId, int $timestamp): void {
    $this->state->set(self::STATE_PREFIX . $taskId, $timestamp);
  }

  /**
   * Clear the next run entry for a task from State API.
   */
  public function clearNextRun(string $taskId): void {
    $this->state->delete(self::STATE_PREFIX . $taskId);
  }

}
