<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk_runner\Service;

use Drupal\ai_claude_agent_sdk_runner\Value\ExecutionResult;
use Drupal\Core\Database\Connection;

/**
 * Tracks running executions and their webhook events in the database.
 */
class ExecutionStore {

  public function __construct(
    protected readonly Connection $database,
  ) {}

  /**
   * Creates a new execution tracking record.
   */
  public function create(string $queryId, string $agentId, string $profileId, string $jobId, string $callbackToken = '', int $initiatorUid = 0): void {
    $this->database->insert('ai_claude_runner_executions')
      ->fields([
        'query_id' => $queryId,
        'agent_id' => $agentId,
        'profile_id' => $profileId,
        'job_id' => $jobId,
        'status' => 'pending',
        'data' => json_encode(['callback_token' => $callbackToken, 'initiator_uid' => $initiatorUid]),
        'created' => time(),
        'updated' => time(),
      ])
      ->execute();
  }

  /**
   * Updates execution status and merges metadata.
   */
  public function updateStatus(string $queryId, string $status, array $data = []): void {
    $existing = $this->database->select('ai_claude_runner_executions', 'e')
      ->fields('e', ['data'])
      ->condition('query_id', $queryId)
      ->execute()
      ->fetchField();

    $existingData = json_decode($existing ?: '{}', TRUE) ?: [];
    $mergedData = array_merge($existingData, $data);

    $this->database->update('ai_claude_runner_executions')
      ->fields([
        'status' => $status,
        'data' => json_encode($mergedData),
        'updated' => time(),
      ])
      ->condition('query_id', $queryId)
      ->execute();
  }

  /**
   * Adds a webhook event to the event log.
   */
  public function addEvent(string $queryId, array $event): void {
    $this->database->insert('ai_claude_runner_events')
      ->fields([
        'query_id' => $queryId,
        'event_type' => $event['type'] ?? 'unknown',
        'event_data' => json_encode($event),
        'timestamp' => time(),
      ])
      ->execute();
  }

  /**
   * Loads execution result if complete, NULL if still running.
   */
  public function getResult(string $queryId): ?ExecutionResult {
    $row = $this->database->select('ai_claude_runner_executions', 'e')
      ->fields('e')
      ->condition('query_id', $queryId)
      ->execute()
      ->fetchObject();

    if (!$row) {
      return NULL;
    }

    if ($row->status === 'completed' || $row->status === 'error') {
      return ExecutionResult::fromRow($row);
    }

    return NULL;
  }

  /**
   * Loads execution result by job ID.
   */
  public function getResultByJobId(string $jobId): ?ExecutionResult {
    $row = $this->database->select('ai_claude_runner_executions', 'e')
      ->fields('e')
      ->condition('job_id', $jobId)
      ->orderBy('created', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchObject();

    if (!$row) {
      return NULL;
    }

    if ($row->status === 'completed' || $row->status === 'error') {
      return ExecutionResult::fromRow($row);
    }

    return NULL;
  }

  /**
   * Checks whether an execution is still running.
   */
  public function isRunning(string $jobId): bool {
    $row = $this->database->select('ai_claude_runner_executions', 'e')
      ->fields('e', ['status', 'created'])
      ->condition('job_id', $jobId)
      ->orderBy('created', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchObject();

    if (!$row) {
      return FALSE;
    }

    if (in_array($row->status, ['pending', 'running'], TRUE) && (time() - (int) $row->created) > 600) {
      return FALSE;
    }

    return in_array($row->status, ['pending', 'running'], TRUE);
  }

  /**
   * Gets all events for an execution, ordered by timestamp.
   */
  public function getEvents(string $queryId): array {
    return $this->database->select('ai_claude_runner_events', 'ev')
      ->fields('ev')
      ->condition('query_id', $queryId)
      ->orderBy('timestamp', 'ASC')
      ->orderBy('id', 'ASC')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Gets the query_id for a job, if any execution exists.
   */
  public function getQueryIdByJobId(string $jobId): ?string {
    $queryId = $this->database->select('ai_claude_runner_executions', 'e')
      ->fields('e', ['query_id'])
      ->condition('job_id', $jobId)
      ->orderBy('created', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    return $queryId ?: NULL;
  }

  /**
   * Gets the stored callback token for a query.
   */
  public function getCallbackToken(string $queryId): string {
    $row = $this->database->select('ai_claude_runner_executions', 'e')
      ->fields('e', ['data'])
      ->condition('query_id', $queryId)
      ->execute()
      ->fetchField();

    if (!$row) {
      return '';
    }

    $data = json_decode($row, TRUE) ?: [];
    return $data['callback_token'] ?? '';
  }

  /**
   * Gets the last Claude Code session_id for a thread.
   *
   * Used for multi-turn conversation resumption. The session_id allows
   * Claude Code to continue a previous conversation.
   */
  public function getLastSessionId(string $jobId, string $profileId): ?string {
    $row = $this->database->select('ai_claude_runner_executions', 'e')
      ->fields('e', ['data'])
      ->condition('job_id', $jobId)
      ->condition('profile_id', $profileId)
      ->condition('status', 'completed')
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if (!$row) {
      return NULL;
    }

    $data = json_decode($row, TRUE) ?: [];
    $sessionId = $data['session_id'] ?? '';
    return $sessionId ?: NULL;
  }

  /**
   * Stores the session_id for a thread for future resumption.
   */
  public function storeSessionId(string $jobId, string $sessionId): void {
    $row = $this->database->select('ai_claude_runner_executions', 'e')
      ->fields('e', ['id', 'data'])
      ->condition('job_id', $jobId)
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchObject();

    if ($row) {
      $existingData = json_decode($row->data ?: '{}', TRUE) ?: [];
      $mergedData = array_merge($existingData, ['session_id' => $sessionId]);

      $this->database->update('ai_claude_runner_executions')
        ->fields([
          'data' => json_encode($mergedData),
          'updated' => time(),
        ])
        ->condition('id', $row->id)
        ->execute();
    }
    else {
      $this->database->insert('ai_claude_runner_executions')
        ->fields([
          'query_id' => 'sync-' . uniqid('', TRUE),
          'agent_id' => '',
          'profile_id' => '',
          'job_id' => $jobId,
          'status' => 'completed',
          'data' => json_encode(['session_id' => $sessionId]),
          'created' => time(),
          'updated' => time(),
        ])
        ->execute();
    }
  }

  /**
   * Gets the initiator UID stored in the execution data for a query.
   */
  public function getInitiatorUid(string $queryId): ?int {
    $row = $this->database->select('ai_claude_runner_executions', 'e')
      ->fields('e', ['data'])
      ->condition('query_id', $queryId)
      ->execute()
      ->fetchField();

    if (!$row) {
      return NULL;
    }

    $data = json_decode($row, TRUE) ?: [];
    return isset($data['initiator_uid']) ? (int) $data['initiator_uid'] : NULL;
  }

  /**
   * Marks an execution as polled (first poll after completion).
   */
  public function markPolled(string $queryId): void {
    $existing = $this->database->select('ai_claude_runner_executions', 'e')
      ->fields('e', ['data'])
      ->condition('query_id', $queryId)
      ->execute()
      ->fetchField();

    $existingData = json_decode($existing ?: '{}', TRUE) ?: [];
    $mergedData = array_merge($existingData, ['polled' => TRUE]);

    $this->database->update('ai_claude_runner_executions')
      ->fields([
        'data' => json_encode($mergedData),
        'updated' => time(),
      ])
      ->condition('query_id', $queryId)
      ->execute();
  }

  /**
   * Returns whether an execution has already been polled.
   */
  public function isPolled(string $queryId): bool {
    $row = $this->database->select('ai_claude_runner_executions', 'e')
      ->fields('e', ['data'])
      ->condition('query_id', $queryId)
      ->execute()
      ->fetchField();

    if (!$row) {
      return FALSE;
    }

    $data = json_decode($row, TRUE) ?: [];
    return !empty($data['polled']);
  }

  /**
   * Stores a permission request from the sidecar webhook.
   */
  public function storePermissionRequest(
    string $queryId,
    string $requestId,
    string $toolName,
    array $input,
    string $decisionReason,
    string $blockedPath,
  ): void {
    $this->database->insert('ai_claude_runner_permissions')
      ->fields([
        'query_id' => $queryId,
        'request_id' => $requestId,
        'tool_name' => $toolName,
        'input_data' => json_encode($input),
        'decision_reason' => $decisionReason,
        'blocked_path' => $blockedPath,
        'status' => 'pending',
        'created' => \Drupal::time()->getRequestTime(),
        'resolved' => 0,
      ])
      ->execute();
  }

  /**
   * Marks a permission request as resolved with a decision.
   */
  public function resolvePermissionRequest(
    string $queryId,
    string $requestId,
    string $decision,
    string $message = '',
  ): void {
    $this->database->update('ai_claude_runner_permissions')
      ->fields([
        'status' => $decision === 'allow' ? 'approved' : 'denied',
        'response_message' => $message,
        'resolved' => \Drupal::time()->getRequestTime(),
      ])
      ->condition('query_id', $queryId)
      ->condition('request_id', $requestId)
      ->condition('status', 'pending')
      ->execute();
  }

  /**
   * Returns all pending permission requests for a query.
   *
   * @return array[]
   *   Array of permission records.
   */
  public function getPendingPermissions(string $queryId): array {
    return $this->database->select('ai_claude_runner_permissions', 'p')
      ->fields('p')
      ->condition('p.query_id', $queryId)
      ->condition('p.status', 'pending')
      ->orderBy('p.created', 'ASC')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC) ?: [];
  }

  /**
   * Returns the oldest pending permission request for a job.
   *
   * @return array|null
   *   The permission record or NULL if no pending permissions.
   */
  public function getOldestPendingPermission(string $jobId): ?array {
    $query = $this->database->select('ai_claude_runner_permissions', 'p');
    $query->fields('p');
    $query->join('ai_claude_runner_executions', 'e', 'p.query_id = e.query_id');
    $query->condition('p.status', 'pending');
    $query->condition('e.job_id', $jobId);
    $query->orderBy('p.created', 'ASC');
    $query->range(0, 1);

    $result = $query->execute()->fetchAssoc();
    return $result ?: NULL;
  }

}
