<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Dispatched after every security tier policy evaluation.
 *
 * Carries the full context of a tool evaluation decision so that
 * subscribers (e.g. the audit log sub-module) can record it.
 */
final class PolicyDecisionEvent extends Event {

  public function __construct(
    public readonly string $profileId,
    public readonly string $queryId,
    public readonly string $runId,
    public readonly int $executorUid,
    public readonly string $toolName,
    public readonly string|array $toolInput,
    public readonly string $decision,
    public readonly string $reason,
    public readonly string $tier,
  ) {}

  public function getProfileId(): string {
    return $this->profileId;
  }

  public function getQueryId(): string {
    return $this->queryId;
  }

  public function getRunId(): string {
    return $this->runId;
  }

  public function getExecutorUid(): int {
    return $this->executorUid;
  }

  public function getToolName(): string {
    return $this->toolName;
  }

  public function getToolInput(): string|array {
    return $this->toolInput;
  }

  public function getDecision(): string {
    return $this->decision;
  }

  public function getReason(): string {
    return $this->reason;
  }

  public function getTier(): string {
    return $this->tier;
  }

}
