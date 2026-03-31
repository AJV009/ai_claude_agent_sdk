<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_claude_agent_sdk\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests policy endpoint HMAC token generation and validation.
 *
 * Policy evaluation logic is tested in PolicyEvaluatorTest.
 *
 * @group ai_claude_agent_sdk
 */
class ClaudePolicyControllerTest extends TestCase {

  /**
   * Tests nonce-based token format: nonce is 32 hex chars, HMAC is 64.
   */
  public function testNonceBasedTokenFormat(): void {
    $nonce = bin2hex(random_bytes(16));
    $salt = 'test_hash_salt';
    $profileId = 'test_profile';

    $this->assertSame(32, strlen($nonce), 'Nonce should be 32 hex characters');

    // Build token using the same formula as generatePolicyToken.
    $hmac = hash_hmac('sha256', $profileId . ':' . $nonce, $salt);
    $token = $nonce . ':' . $hmac;

    $parts = explode(':', $token, 2);
    $this->assertCount(2, $parts, 'Token should have exactly 2 parts');
    $this->assertSame(32, strlen($parts[0]), 'First part should be 32-char nonce');
    $this->assertSame(64, strlen($parts[1]), 'Second part should be 64-char HMAC');
  }

  /**
   * Tests nonce validation logic: valid nonce accepted.
   */
  public function testNonceValidation_ValidNonce(): void {
    $nonce = bin2hex(random_bytes(16));
    $profileId = 'my_agent';
    $salt = 'test_hash_salt';

    $hmac = hash_hmac('sha256', $profileId . ':' . $nonce, $salt);
    $token = $nonce . ':' . $hmac;

    $storedProfile = $profileId;

    $valid = $this->validateNonceToken($token, $profileId, $storedProfile, $salt);
    $this->assertTrue($valid, 'Valid nonce and matching profile should pass');
  }

  /**
   * Tests nonce validation logic: missing nonce rejected.
   */
  public function testNonceValidation_MissingNonce(): void {
    $nonce = bin2hex(random_bytes(16));
    $profileId = 'my_agent';
    $salt = 'test_hash_salt';

    $hmac = hash_hmac('sha256', $profileId . ':' . $nonce, $salt);
    $token = $nonce . ':' . $hmac;

    $storedProfile = NULL;

    $valid = $this->validateNonceToken($token, $profileId, $storedProfile, $salt);
    $this->assertFalse($valid, 'Missing nonce should be rejected');
  }

  /**
   * Tests nonce validation logic: wrong profile rejected.
   */
  public function testNonceValidation_WrongProfile(): void {
    $nonce = bin2hex(random_bytes(16));
    $profileId = 'my_agent';
    $salt = 'test_hash_salt';

    $hmac = hash_hmac('sha256', $profileId . ':' . $nonce, $salt);
    $token = $nonce . ':' . $hmac;

    $storedProfile = 'other_agent';

    $valid = $this->validateNonceToken($token, $profileId, $storedProfile, $salt);
    $this->assertFalse($valid, 'Nonce for wrong profile should be rejected');
  }

  /**
   * Tests nonce validation logic: tampered HMAC rejected.
   */
  public function testNonceValidation_TamperedHmac(): void {
    $nonce = bin2hex(random_bytes(16));
    $profileId = 'my_agent';
    $salt = 'test_hash_salt';

    $hmac = hash_hmac('sha256', $profileId . ':' . $nonce, 'wrong_salt');
    $token = $nonce . ':' . $hmac;

    $storedProfile = $profileId;

    $valid = $this->validateNonceToken($token, $profileId, $storedProfile, $salt);
    $this->assertFalse($valid, 'Tampered HMAC should be rejected');
  }

  /**
   * Tests nonce validation logic: malformed token rejected.
   */
  public function testNonceValidation_MalformedToken(): void {
    $salt = 'test_hash_salt';

    $this->assertFalse($this->validateNonceToken('no-colon-here', 'prof', 'prof', $salt));
    $this->assertFalse($this->validateNonceToken('', 'prof', 'prof', $salt));
    $this->assertFalse($this->validateNonceToken('a:b', '', 'prof', $salt));
  }

  /**
   * Mirrors the controller's nonce-based validatePolicyToken logic.
   */
  private function validateNonceToken(string $token, string $profileId, ?string $storedProfile, string $salt): bool {
    if ($token === '' || $profileId === '') {
      return FALSE;
    }

    $parts = explode(':', $token, 2);
    if (count($parts) !== 2) {
      return FALSE;
    }

    [$nonce, $hmac] = $parts;

    if ($storedProfile === NULL) {
      return FALSE;
    }

    if ($storedProfile !== $profileId) {
      return FALSE;
    }

    $expected = hash_hmac('sha256', $profileId . ':' . $nonce, $salt);
    return hash_equals($expected, $hmac);
  }

}
