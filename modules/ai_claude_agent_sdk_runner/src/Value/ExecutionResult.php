<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk_runner\Value;

/**
 * Value object representing a completed execution result.
 */
final class ExecutionResult {

  public function __construct(
    private readonly string $queryId,
    private readonly string $agentId,
    private readonly string $profileId,
    private readonly string $jobId,
    private readonly string $status,
    private readonly array $data,
  ) {}

  public function getQueryId(): string {
    return $this->queryId;
  }

  public function getAgentId(): string {
    return $this->agentId;
  }

  public function getProfileId(): string {
    return $this->profileId;
  }

  public function getJobId(): string {
    return $this->jobId;
  }

  public function getStatus(): string {
    return $this->status;
  }

  public function getResponse(): string {
    return $this->data['response'] ?? '';
  }

  public function getSessionId(): string {
    return $this->data['session_id'] ?? '';
  }

  public function getTotalTurns(): int {
    return (int) ($this->data['total_turns'] ?? 0);
  }

  public function getErrorType(): string {
    return $this->data['error_type'] ?? '';
  }

  public function getErrorMessage(): string {
    return $this->data['error_message'] ?? '';
  }

  public function getData(): array {
    return $this->data;
  }

  public function isCompleted(): bool {
    return $this->status === 'completed';
  }

  public function isError(): bool {
    return $this->status === 'error';
  }

  /**
   * Creates from a database row object.
   */
  public static function fromRow(object $row): self {
    return new self(
      queryId: $row->query_id,
      agentId: $row->agent_id,
      profileId: $row->profile_id,
      jobId: $row->job_id,
      status: $row->status,
      data: json_decode($row->data ?? '{}', TRUE) ?: [],
    );
  }

}
