<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Service;

use Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface;
use Drupal\ai_claude_agent_sdk\Exception\ExecutionPrincipalException;
use Drupal\ai_claude_agent_sdk\Execution\ExecutionContext;
use Drupal\ai_claude_agent_sdk\Execution\ValidationResult;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountSwitcherInterface;

/**
 * Resolves which Drupal account should execute for a profile + modality.
 *
 * Priority:
 * 1. Interactive modality -> current authenticated user
 * 2. Background / outside_in -> profile's executor_uid
 * 3. No valid executor -> throw ExecutionPrincipalException
 */
class ExecutionPrincipalResolver {

  public function __construct(
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Resolves which Drupal account should execute for this profile + modality.
   *
   * @param \Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface $profile
   *   The agent profile.
   * @param string $modality
   *   One of 'interactive', 'background', 'outside_in'.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The resolved executor account.
   *
   * @throws \Drupal\ai_claude_agent_sdk\Exception\ExecutionPrincipalException
   *   If no valid executor can be resolved.
   */
  public function resolve(AgentProfileInterface $profile, string $modality): AccountInterface {
    if ($modality === 'interactive') {
      $uid = (int) $this->currentUser->id();
      if ($uid === 0) {
        throw new ExecutionPrincipalException(
          'Interactive execution requires an authenticated user.'
        );
      }
      return $this->loadAndValidateAccount($uid);
    }

    // Background / outside_in: use profile's executor.
    $executorUid = $profile->getExecutorUid();
    if ($executorUid === 0) {
      throw new ExecutionPrincipalException(
        "Profile '{$profile->id()}' has no executor configured for background execution."
      );
    }

    return $this->loadAndValidateAccount($executorUid);
  }

  /**
   * Validates executor is usable BEFORE any execution begins.
   *
   * Call this at profile save time (warning) and execution start (hard fail).
   *
   * @param \Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface $profile
   *   The agent profile to validate.
   *
   * @return \Drupal\ai_claude_agent_sdk\Execution\ValidationResult
   *   The validation result.
   */
  public function validate(AgentProfileInterface $profile): ValidationResult {
    $executorUid = $profile->getExecutorUid();

    if ($executorUid === 0) {
      return ValidationResult::warning(
        'No executor configured. Background executions will fail.'
      );
    }

    try {
      $account = $this->loadAndValidateAccount($executorUid);

      // Check executor eligibility when restriction is enabled.
      $restrictByPermission = $this->configFactory
        ->get('ai_claude_agent_sdk.settings')
        ->get('restrict_executor_by_permission');

      if ($restrictByPermission && !$account->hasPermission('act as ai executor')) {
        return ValidationResult::invalid(
          "User '" . $account->getAccountName() . "' does not have the 'Act as AI executor' permission. Assign it via People > Permissions or add a role that includes it."
        );
      }

      return ValidationResult::valid($account);
    }
    catch (ExecutionPrincipalException $e) {
      return ValidationResult::invalid($e->getMessage());
    }
  }

  /**
   * Executes a callback under the resolved executor's identity.
   *
   * Account switching and cleanup are handled internally — the callback
   * cannot forget to restore the previous user context.
   *
   * @template T
   *
   * @param \Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface $profile
   *   The agent profile.
   * @param string $modality
   *   One of 'interactive', 'background', 'outside_in'.
   * @param callable(\Drupal\ai_claude_agent_sdk\Execution\ExecutionContext): T $callback
   *   The callback to execute under the executor's identity. Receives the
   *   execution context as its sole argument.
   *
   * @return T
   *   The return value of the callback.
   *
   * @throws \Drupal\ai_claude_agent_sdk\Exception\ExecutionPrincipalException
   *   If no valid executor can be resolved.
   */
  public function executeAs(AgentProfileInterface $profile, string $modality, callable $callback): mixed {
    $account = $this->resolve($profile, $modality);
    $previousUid = (int) $this->currentUser->id();

    $this->accountSwitcher->switchTo($account);

    $this->loggerFactory->get('ai_claude_agent_sdk')->info(
      'Execution principal switched: @prev -> @new (profile: @profile, modality: @modality)',
      [
        '@prev' => $previousUid,
        '@new' => $account->id(),
        '@profile' => $profile->id(),
        '@modality' => $modality,
      ]
    );

    $ctx = new ExecutionContext(
      account: $account,
      previousUid: $previousUid,
      profileId: $profile->id(),
      modality: $modality,
      switchedAt: time(),
    );

    try {
      return $callback($ctx);
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
  }

  /**
   * Loads a user account and validates it is usable for execution.
   *
   * @param int $uid
   *   The user ID to load.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The validated user account.
   *
   * @throws \Drupal\ai_claude_agent_sdk\Exception\ExecutionPrincipalException
   *   If the user does not exist or is blocked.
   */
  private function loadAndValidateAccount(int $uid): AccountInterface {
    $user = $this->entityTypeManager->getStorage('user')->load($uid);

    if (!$user) {
      throw new ExecutionPrincipalException("Executor user {$uid} does not exist.");
    }
    if ($user->isBlocked()) {
      throw new ExecutionPrincipalException(
        "Executor user {$uid} ({$user->getAccountName()}) is blocked."
      );
    }

    return $user;
  }

}
