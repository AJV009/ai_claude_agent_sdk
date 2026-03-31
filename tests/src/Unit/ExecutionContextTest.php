<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_claude_agent_sdk\Unit;

use Drupal\ai_claude_agent_sdk\Execution\ExecutionContext;
use Drupal\Core\Session\AccountInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests the ExecutionContext value object.
 *
 * @group ai_claude_agent_sdk
 */
class ExecutionContextTest extends TestCase {

  public function testAccessors(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(47);
    $account->method('getRoles')->willReturn(['authenticated', 'editor']);
    $account->method('getAccountName')->willReturn('service_agent');

    $context = new ExecutionContext(
      account: $account,
      previousUid: 1,
      profileId: 'test_profile',
      modality: 'background',
      switchedAt: 1700000000,
    );

    $this->assertSame(47, $context->getUid());
    $this->assertSame(['authenticated', 'editor'], $context->getRoles());
    $this->assertSame('service_agent', $context->getAccountName());
    $this->assertSame(1, $context->previousUid);
    $this->assertSame('test_profile', $context->profileId);
    $this->assertSame('background', $context->modality);
    $this->assertSame(1700000000, $context->switchedAt);
  }

  public function testReadonlyProperties(): void {
    $account = $this->createMock(AccountInterface::class);
    $context = new ExecutionContext(
      account: $account,
      previousUid: 1,
      profileId: 'test',
      modality: 'interactive',
      switchedAt: 1700000000,
    );

    $this->assertSame($account, $context->account);
    $this->assertSame('interactive', $context->modality);
  }

}
