<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_claude_agent_sdk\Unit;

use Drupal\ai_claude_agent_sdk\Entity\AgentProfileInterface;
use Drupal\ai_claude_agent_sdk\Exception\ExecutionPrincipalException;
use Drupal\ai_claude_agent_sdk\Execution\ValidationResult;
use Drupal\ai_claude_agent_sdk\Service\ExecutionPrincipalResolver;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\user\UserInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests the ExecutionPrincipalResolver service.
 *
 * @group ai_claude_agent_sdk
 */
class ExecutionPrincipalResolverTest extends TestCase {

  private AccountSwitcherInterface $accountSwitcher;
  private EntityTypeManagerInterface $entityTypeManager;
  private EntityStorageInterface $userStorage;
  private AccountProxyInterface $currentUser;
  private LoggerChannelFactoryInterface $loggerFactory;
  private LoggerChannelInterface $logger;
  private ExecutionPrincipalResolver $resolver;
  private \Drupal\Core\Config\ConfigFactoryInterface $configFactory;
  private \Drupal\Core\Config\ImmutableConfig $config;

  protected function setUp(): void {
    parent::setUp();

    $this->accountSwitcher = $this->createMock(AccountSwitcherInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->userStorage = $this->createMock(EntityStorageInterface::class);
    $this->currentUser = $this->createMock(AccountProxyInterface::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);

    $this->entityTypeManager->method('getStorage')
      ->with('user')
      ->willReturn($this->userStorage);

    $this->loggerFactory->method('get')
      ->with('ai_claude_agent_sdk')
      ->willReturn($this->logger);

    $this->config = $this->createMock(\Drupal\Core\Config\ImmutableConfig::class);
    $this->config->method('get')
      ->with('restrict_executor_by_permission')
      ->willReturn(FALSE);

    $this->configFactory = $this->createMock(\Drupal\Core\Config\ConfigFactoryInterface::class);
    $this->configFactory->method('get')
      ->with('ai_claude_agent_sdk.settings')
      ->willReturn($this->config);

    $this->resolver = new ExecutionPrincipalResolver(
      $this->accountSwitcher,
      $this->entityTypeManager,
      $this->currentUser,
      $this->loggerFactory,
      $this->configFactory,
    );
  }

  /**
   * Helper: creates a mock UserInterface with given uid, name, and status.
   */
  private function createMockUser(int $uid, string $name = 'testuser', bool $blocked = FALSE): UserInterface {
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn($uid);
    $user->method('getAccountName')->willReturn($name);
    $user->method('isBlocked')->willReturn($blocked);
    $user->method('getRoles')->willReturn(['authenticated', 'editor']);
    return $user;
  }

  /**
   * Helper: creates a mock AgentProfileInterface.
   */
  private function createMockProfile(int $executorUid = 0, string $id = 'test_profile'): AgentProfileInterface {
    $profile = $this->createMock(AgentProfileInterface::class);
    $profile->method('getExecutorUid')->willReturn($executorUid);
    $profile->method('id')->willReturn($id);
    return $profile;
  }

  // =========================================================================
  // resolve() tests
  // =========================================================================

  public function testResolveInteractiveReturnsCurrentUser(): void {
    $user = $this->createMockUser(42, 'admin');
    $this->currentUser->method('id')->willReturn(42);
    $this->userStorage->method('load')->with(42)->willReturn($user);

    $profile = $this->createMockProfile();

    $result = $this->resolver->resolve($profile, 'interactive');

    $this->assertSame(42, $result->id());
    $this->assertSame('admin', $result->getAccountName());
  }

  public function testResolveInteractiveThrowsForAnonymous(): void {
    $this->currentUser->method('id')->willReturn(0);

    $profile = $this->createMockProfile();

    $this->expectException(ExecutionPrincipalException::class);
    $this->expectExceptionMessage('Interactive execution requires an authenticated user.');

    $this->resolver->resolve($profile, 'interactive');
  }

  public function testResolveBackgroundUsesProfileExecutor(): void {
    $user = $this->createMockUser(47, 'service_account');
    $this->userStorage->method('load')->with(47)->willReturn($user);

    $profile = $this->createMockProfile(47);

    $result = $this->resolver->resolve($profile, 'background');

    $this->assertSame(47, $result->id());
    $this->assertSame('service_account', $result->getAccountName());
  }

  public function testResolveOutsideInUsesProfileExecutor(): void {
    $user = $this->createMockUser(47, 'service_account');
    $this->userStorage->method('load')->with(47)->willReturn($user);

    $profile = $this->createMockProfile(47);

    $result = $this->resolver->resolve($profile, 'outside_in');

    $this->assertSame(47, $result->id());
  }

  public function testResolveBackgroundThrowsWhenNoExecutor(): void {
    $profile = $this->createMockProfile(0, 'my_profile');

    $this->expectException(ExecutionPrincipalException::class);
    $this->expectExceptionMessage("Profile 'my_profile' has no executor configured for background execution.");

    $this->resolver->resolve($profile, 'background');
  }

  public function testResolveBackgroundThrowsWhenUserDeleted(): void {
    $this->userStorage->method('load')->with(999)->willReturn(NULL);

    $profile = $this->createMockProfile(999);

    $this->expectException(ExecutionPrincipalException::class);
    $this->expectExceptionMessage('Executor user 999 does not exist.');

    $this->resolver->resolve($profile, 'background');
  }

  public function testResolveBackgroundThrowsWhenUserBlocked(): void {
    $user = $this->createMockUser(50, 'blocked_user', TRUE);
    $this->userStorage->method('load')->with(50)->willReturn($user);

    $profile = $this->createMockProfile(50);

    $this->expectException(ExecutionPrincipalException::class);
    $this->expectExceptionMessage('Executor user 50 (blocked_user) is blocked.');

    $this->resolver->resolve($profile, 'background');
  }

  // =========================================================================
  // validate() tests
  // =========================================================================

  public function testValidateWithValidExecutor(): void {
    $user = $this->createMockUser(47, 'service_account');
    $this->userStorage->method('load')->with(47)->willReturn($user);

    $profile = $this->createMockProfile(47);

    $result = $this->resolver->validate($profile);

    $this->assertTrue($result->isValid());
    $this->assertSame('valid', $result->status);
    $this->assertSame(47, $result->account->id());
  }

  public function testValidateWithNoExecutorReturnsWarning(): void {
    $profile = $this->createMockProfile(0);

    $result = $this->resolver->validate($profile);

    $this->assertFalse($result->isValid());
    $this->assertSame('warning', $result->status);
    $this->assertSame('No executor configured. Background executions will fail.', $result->message);
  }

  public function testValidateWithBlockedUserReturnsInvalid(): void {
    $user = $this->createMockUser(50, 'blocked_user', TRUE);
    $this->userStorage->method('load')->with(50)->willReturn($user);

    $profile = $this->createMockProfile(50);

    $result = $this->resolver->validate($profile);

    $this->assertFalse($result->isValid());
    $this->assertSame('invalid', $result->status);
    $this->assertStringContainsString('blocked', $result->message);
  }

  public function testValidateWithDeletedUserReturnsInvalid(): void {
    $this->userStorage->method('load')->with(999)->willReturn(NULL);

    $profile = $this->createMockProfile(999);

    $result = $this->resolver->validate($profile);

    $this->assertFalse($result->isValid());
    $this->assertSame('invalid', $result->status);
    $this->assertStringContainsString('does not exist', $result->message);
  }

  // =========================================================================
  // executeAs() tests
  // =========================================================================

  public function testExecuteAsSwitchesAccountBeforeCallback(): void {
    $user = $this->createMockUser(47, 'service_account');
    $this->userStorage->method('load')->with(47)->willReturn($user);
    $this->currentUser->method('id')->willReturn(1);

    $profile = $this->createMockProfile(47, 'my_profile');

    $this->accountSwitcher->expects($this->once())
      ->method('switchTo')
      ->with($user);

    $this->resolver->executeAs($profile, 'background', function ($ctx) {
      $this->assertSame(47, $ctx->getUid());
      $this->assertSame(1, $ctx->previousUid);
      $this->assertSame('my_profile', $ctx->profileId);
      $this->assertSame('background', $ctx->modality);
      $this->assertSame('service_account', $ctx->getAccountName());
      $this->assertContains('editor', $ctx->getRoles());
    });
  }

  public function testExecuteAsCallsSwitchBackAfterCallback(): void {
    $user = $this->createMockUser(47);
    $this->userStorage->method('load')->with(47)->willReturn($user);
    $this->currentUser->method('id')->willReturn(1);

    $profile = $this->createMockProfile(47);

    $this->accountSwitcher->expects($this->once())
      ->method('switchBack');

    $this->resolver->executeAs($profile, 'background', function () {
      // Callback body — switchBack happens after this returns.
    });
  }

  public function testExecuteAsCallsSwitchBackOnException(): void {
    $user = $this->createMockUser(47);
    $this->userStorage->method('load')->with(47)->willReturn($user);
    $this->currentUser->method('id')->willReturn(1);

    $profile = $this->createMockProfile(47);

    $this->accountSwitcher->expects($this->once())
      ->method('switchBack');

    try {
      $this->resolver->executeAs($profile, 'background', function () {
        throw new \RuntimeException('Callback failed');
      });
    }
    catch (\RuntimeException) {
      // Expected — switchBack should still have been called.
    }
  }

  public function testExecuteAsReturnsCallbackValue(): void {
    $user = $this->createMockUser(47);
    $this->userStorage->method('load')->with(47)->willReturn($user);
    $this->currentUser->method('id')->willReturn(1);

    $profile = $this->createMockProfile(47);

    $result = $this->resolver->executeAs($profile, 'background', function () {
      return 'execution_result';
    });

    $this->assertSame('execution_result', $result);
  }

  /**
   * Helper: creates a resolver with a specific restriction toggle value.
   */
  private function createResolverWithRestriction(bool $restricted): ExecutionPrincipalResolver {
    $config = $this->createMock(\Drupal\Core\Config\ImmutableConfig::class);
    $config->method('get')
      ->with('restrict_executor_by_permission')
      ->willReturn($restricted);

    $configFactory = $this->createMock(\Drupal\Core\Config\ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ai_claude_agent_sdk.settings')
      ->willReturn($config);

    return new ExecutionPrincipalResolver(
      $this->accountSwitcher,
      $this->entityTypeManager,
      $this->currentUser,
      $this->loggerFactory,
      $configFactory,
    );
  }

  public function testValidateReturnsInvalidWhenRestrictionEnabledAndUserLacksPermission(): void {
    $user = $this->createMockUser(42, 'jdoe');
    $user->method('hasPermission')
      ->with('act as ai executor')
      ->willReturn(FALSE);
    $this->userStorage->method('load')->with(42)->willReturn($user);

    $profile = $this->createMockProfile(42);
    $resolver = $this->createResolverWithRestriction(TRUE);

    $result = $resolver->validate($profile);

    $this->assertFalse($result->isValid());
    $this->assertSame('invalid', $result->status);
    $this->assertStringContainsString('Act as AI executor', $result->message);
  }

  public function testValidateReturnsValidWhenRestrictionEnabledAndUserHasPermission(): void {
    $user = $this->createMockUser(42, 'jdoe');
    $user->method('hasPermission')
      ->with('act as ai executor')
      ->willReturn(TRUE);
    $this->userStorage->method('load')->with(42)->willReturn($user);

    $profile = $this->createMockProfile(42);
    $resolver = $this->createResolverWithRestriction(TRUE);

    $result = $resolver->validate($profile);

    $this->assertTrue($result->isValid());
    $this->assertSame('valid', $result->status);
  }

  public function testValidateSkipsPermissionCheckWhenRestrictionDisabled(): void {
    $user = $this->createMockUser(42, 'jdoe');
    // User lacks permission, but restriction is OFF — should still be valid.
    $user->method('hasPermission')
      ->with('act as ai executor')
      ->willReturn(FALSE);
    $this->userStorage->method('load')->with(42)->willReturn($user);

    $profile = $this->createMockProfile(42);
    $resolver = $this->createResolverWithRestriction(FALSE);

    $result = $resolver->validate($profile);

    $this->assertTrue($result->isValid());
    $this->assertSame('valid', $result->status);
  }

  public function testExecuteAsLogsTheSwitch(): void {
    $user = $this->createMockUser(47);
    $this->userStorage->method('load')->with(47)->willReturn($user);
    $this->currentUser->method('id')->willReturn(1);

    $profile = $this->createMockProfile(47, 'my_profile');

    $this->logger->expects($this->once())
      ->method('info')
      ->with(
        $this->stringContains('Execution principal switched'),
        $this->callback(function (array $context) {
          return $context['@prev'] === 1
            && $context['@new'] === 47
            && $context['@profile'] === 'my_profile'
            && $context['@modality'] === 'background';
        })
      );

    $this->resolver->executeAs($profile, 'background', function () {});
  }

}
