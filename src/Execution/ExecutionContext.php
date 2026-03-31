<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Execution;

use Drupal\Core\Session\AccountInterface;

/**
 * Read-only value object describing the active execution context.
 *
 * Passed to the callback in ExecutionPrincipalResolver::executeAs(). Account
 * switching and cleanup are handled by the resolver — callers cannot (and need
 * not) trigger switchBack themselves.
 *
 * @code
 *   $resolver->executeAs($profile, 'background', function (ExecutionContext $ctx) {
 *     // $ctx->getUid(), $ctx->getRoles(), etc.
 *   });
 * @endcode
 */
class ExecutionContext {

  public function __construct(
    public readonly AccountInterface $account,
    public readonly int $previousUid,
    public readonly string $profileId,
    public readonly string $modality,
    public readonly int $switchedAt,
  ) {}

  /**
   * Gets the executor's user ID.
   */
  public function getUid(): int {
    return (int) $this->account->id();
  }

  /**
   * Gets the executor's roles.
   *
   * @return string[]
   */
  public function getRoles(): array {
    return $this->account->getRoles();
  }

  /**
   * Gets the executor's account name.
   */
  public function getAccountName(): string {
    return $this->account->getAccountName();
  }

}
