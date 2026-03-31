<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_claude_agent_sdk\Unit;

use Drupal\ai_claude_agent_sdk\Execution\ValidationResult;
use Drupal\Core\Session\AccountInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests the ValidationResult value object.
 *
 * @group ai_claude_agent_sdk
 */
class ValidationResultTest extends TestCase {

  public function testValidResult(): void {
    $account = $this->createMock(AccountInterface::class);
    $result = ValidationResult::valid($account);

    $this->assertTrue($result->isValid());
    $this->assertSame('valid', $result->status);
    $this->assertSame('', $result->message);
    $this->assertSame($account, $result->account);
  }

  public function testInvalidResult(): void {
    $result = ValidationResult::invalid('User is blocked');

    $this->assertFalse($result->isValid());
    $this->assertSame('invalid', $result->status);
    $this->assertSame('User is blocked', $result->message);
    $this->assertNull($result->account);
  }

  public function testWarningResult(): void {
    $result = ValidationResult::warning('No executor configured');

    $this->assertFalse($result->isValid());
    $this->assertSame('warning', $result->status);
    $this->assertSame('No executor configured', $result->message);
    $this->assertNull($result->account);
  }

}
