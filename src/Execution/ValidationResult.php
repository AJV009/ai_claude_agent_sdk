<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Execution;

use Drupal\Core\Session\AccountInterface;

/**
 * Result of validating an execution principal.
 *
 * Three possible states:
 * - valid: executor exists, is active, and ready to use
 * - warning: no executor configured (background executions will fail)
 * - invalid: executor exists but is blocked/deleted/unusable
 */
class ValidationResult {

  private function __construct(
    public readonly string $status,
    public readonly string $message,
    public readonly ?AccountInterface $account,
  ) {}

  /**
   * Creates a valid result with the resolved account.
   */
  public static function valid(AccountInterface $account): self {
    return new self('valid', '', $account);
  }

  /**
   * Creates an invalid result with an error reason.
   */
  public static function invalid(string $reason): self {
    return new self('invalid', $reason, NULL);
  }

  /**
   * Creates a warning result (non-blocking) with a reason.
   */
  public static function warning(string $reason): self {
    return new self('warning', $reason, NULL);
  }

  /**
   * Whether the validation passed (executor is usable).
   */
  public function isValid(): bool {
    return $this->status === 'valid';
  }

}
