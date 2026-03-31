<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Service;

/**
 * Immutable value object representing a policy evaluation decision.
 */
final class PolicyDecision {

  public function __construct(
    public readonly string $decision,
    public readonly string $reason,
    public readonly bool $wasAsk = FALSE,
  ) {}

  /**
   * Creates an "allow" decision.
   */
  public static function allow(string $reason, bool $wasAsk = FALSE): self {
    return new self('allow', $reason, $wasAsk);
  }

  /**
   * Creates a "deny" decision.
   */
  public static function deny(string $reason, bool $wasAsk = FALSE): self {
    return new self('deny', $reason, $wasAsk);
  }

  /**
   * Creates an "ask" decision (requires user approval).
   */
  public static function ask(string $reason): self {
    return new self('ask', $reason);
  }

}
