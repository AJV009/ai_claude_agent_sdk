<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk_audit\Service;

use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Writes and queries the security audit log table.
 *
 * Records every tool evaluation decision made by the HTTP policy endpoint,
 * providing a complete audit trail for Claude Code agent activity.
 */
final class AuditLogger {

  /**
   * The database table name.
   */
  private const TABLE = 'ai_claude_agent_sdk_audit_log';

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Logs a tool evaluation decision.
   *
   * @param array $data
   *   Audit data with keys: profile_id, query_id, run_id, executor_uid,
   *   tool_name, tool_input, decision, reason, tier.
   */
  public function log(array $data): void {
    $this->database->insert(self::TABLE)
      ->fields([
        'profile_id' => $data['profile_id'] ?? '',
        'query_id' => $data['query_id'] ?? '',
        'run_id' => $data['run_id'] ?? '',
        'executor_uid' => (int) ($data['executor_uid'] ?? 0),
        'tool_name' => $data['tool_name'] ?? '',
        'tool_input' => is_string($data['tool_input'] ?? NULL)
          ? $data['tool_input']
          : json_encode($data['tool_input'] ?? [], JSON_THROW_ON_ERROR),
        'decision' => $data['decision'] ?? 'deny',
        'reason' => $data['reason'] ?? '',
        'tier' => $data['tier'] ?? '',
        'timestamp' => $this->time->getRequestTime(),
      ])
      ->execute();
  }

  /**
   * Loads a single audit log entry by ID.
   *
   * @param int $id
   *   The entry primary key.
   *
   * @return array|null
   *   The row as an associative array, or NULL if not found.
   */
  public function load(int $id): ?array {
    $row = $this->database->select(self::TABLE, 'a')
      ->fields('a')
      ->condition('id', $id)
      ->execute()
      ->fetchAssoc();
    return $row ?: NULL;
  }

  /**
   * Queries the audit log with optional filters.
   *
   * @param array $filters
   *   Optional filters: profile_id, decision, tool_name, tier, since, until.
   * @param int $limit
   *   Maximum rows to return.
   * @param int $offset
   *   Offset for pagination.
   *
   * @return array
   *   Array of audit log rows as associative arrays.
   */
  public function query(array $filters = [], int $limit = 50, int $offset = 0): array {
    $query = $this->database->select(self::TABLE, 'a')
      ->fields('a')
      ->orderBy('timestamp', 'DESC')
      ->orderBy('id', 'DESC')
      ->range($offset, $limit);

    $this->applyFilters($query, $filters);

    return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Returns the total count of records matching optional filters.
   *
   * @param array $filters
   *   Same filters as query().
   *
   * @return int
   *   Total matching row count.
   */
  public function count(array $filters = []): int {
    $query = $this->database->select(self::TABLE, 'a');
    $query->addExpression('COUNT(*)', 'total');

    $this->applyFilters($query, $filters);

    return (int) $query->execute()->fetchField();
  }

  /**
   * Deletes all audit log entries.
   *
   * @return int
   *   Number of rows deleted.
   */
  public function clearAll(): int {
    return (int) $this->database->delete(self::TABLE)->execute();
  }

  /**
   * Purges audit log records older than a given timestamp.
   *
   * @param int $olderThanTimestamp
   *   Unix timestamp. Records with timestamp < this value will be deleted.
   *
   * @return int
   *   Number of rows deleted.
   */
  public function purge(int $olderThanTimestamp): int {
    return (int) $this->database->delete(self::TABLE)
      ->condition('timestamp', $olderThanTimestamp, '<')
      ->execute();
  }

  /**
   * Applies filter conditions to a query.
   */
  private function applyFilters(object $query, array $filters): void {
    if (!empty($filters['profile_id'])) {
      $query->condition('profile_id', $filters['profile_id']);
    }
    if (!empty($filters['decision'])) {
      $query->condition('decision', $filters['decision']);
    }
    if (!empty($filters['tool_name'])) {
      $query->condition('tool_name', $filters['tool_name']);
    }
    if (!empty($filters['tier'])) {
      $query->condition('tier', $filters['tier']);
    }
    if (!empty($filters['since'])) {
      $query->condition('timestamp', (int) $filters['since'], '>=');
    }
    if (!empty($filters['until'])) {
      $query->condition('timestamp', (int) $filters['until'], '<=');
    }
  }

}
