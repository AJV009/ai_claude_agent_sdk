<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_claude_agent_sdk\Unit;

use Drupal\ai_claude_agent_sdk\Controller\ExecutorAutocompleteController;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\user\UserInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the ExecutorAutocompleteController.
 *
 * @group ai_claude_agent_sdk
 */
class ExecutorAutocompleteControllerTest extends TestCase {

  /**
   * Helper: create a mock user.
   */
  private function createMockUser(int $uid, string $name, bool $hasPermission = TRUE): UserInterface {
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn($uid);
    $user->method('getDisplayName')->willReturn($name);
    $user->method('getAccountName')->willReturn($name);
    $user->method('hasPermission')
      ->with('act as ai executor')
      ->willReturn($hasPermission);
    return $user;
  }

  /**
   * Helper: create controller with config and users.
   */
  private function createController(bool $restricted, array $users): ExecutorAutocompleteController {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('restrict_executor_by_permission')
      ->willReturn($restricted);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ai_claude_agent_sdk.settings')
      ->willReturn($config);

    // Build query mock that returns user IDs.
    $uids = array_map(fn($u) => $u->id(), $users);
    $query = $this->createMock(QueryInterface::class);
    $query->method('condition')->willReturnSelf();
    $query->method('accessCheck')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn(array_combine($uids, $uids));

    $userStorage = $this->createMock(EntityStorageInterface::class);
    $userStorage->method('getQuery')->willReturn($query);
    $userStorage->method('loadMultiple')
      ->with(array_combine($uids, $uids))
      ->willReturn(array_combine($uids, $users));

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('user')
      ->willReturn($userStorage);

    return new ExecutorAutocompleteController($entityTypeManager, $configFactory);
  }

  /**
   * Tests that eligible users get clean labels when restriction is on.
   */
  public function testEligibleUserGetsCleanLabel(): void {
    $user = $this->createMockUser(42, 'jdoe', TRUE);
    $controller = $this->createController(TRUE, [$user]);

    $request = new Request(['q' => 'jdoe']);
    $response = $controller->handleAutocomplete($request);
    $data = json_decode($response->getContent(), TRUE);

    $this->assertCount(1, $data);
    $this->assertEquals('jdoe (42)', $data[0]['value']);
    $this->assertEquals('jdoe', $data[0]['label']);
  }

  /**
   * Tests that ineligible users get decorated labels when restriction is on.
   */
  public function testIneligibleUserGetsDecoratedLabel(): void {
    $user = $this->createMockUser(42, 'jdoe', FALSE);
    $controller = $this->createController(TRUE, [$user]);

    $request = new Request(['q' => 'jdoe']);
    $response = $controller->handleAutocomplete($request);
    $data = json_decode($response->getContent(), TRUE);

    $this->assertCount(1, $data);
    $this->assertEquals('jdoe (42)', $data[0]['value']);
    $this->assertStringContainsString('ineligible', $data[0]['label']);
    $this->assertStringContainsString('Act as AI executor', $data[0]['label']);
  }

  /**
   * Tests that all users get clean labels when restriction is off.
   */
  public function testAllUsersGetCleanLabelsWhenRestrictionOff(): void {
    $user = $this->createMockUser(42, 'jdoe', FALSE);
    $controller = $this->createController(FALSE, [$user]);

    $request = new Request(['q' => 'jdoe']);
    $response = $controller->handleAutocomplete($request);
    $data = json_decode($response->getContent(), TRUE);

    $this->assertCount(1, $data);
    $this->assertEquals('jdoe (42)', $data[0]['value']);
    $this->assertEquals('jdoe', $data[0]['label']);
  }

  /**
   * Tests that multiple matching users are all returned.
   */
  public function testMultipleMatchingUsersReturned(): void {
    $user1 = $this->createMockUser(42, 'jdoe', TRUE);
    $user2 = $this->createMockUser(43, 'jsmith', FALSE);
    $controller = $this->createController(TRUE, [$user1, $user2]);

    $request = new Request(['q' => 'j']);
    $response = $controller->handleAutocomplete($request);
    $data = json_decode($response->getContent(), TRUE);

    // Both users are returned (query filtering is done by the DB query,
    // which the mock simulates as returning all passed users).
    $this->assertCount(2, $data);
    $this->assertEquals('jdoe', $data[0]['label']);
    $this->assertStringContainsString('ineligible', $data[1]['label']);
  }

  /**
   * Tests that empty input returns no results.
   */
  public function testEmptyInputReturnsNoResults(): void {
    $controller = $this->createController(FALSE, []);

    $request = new Request(['q' => '']);
    $response = $controller->handleAutocomplete($request);
    $data = json_decode($response->getContent(), TRUE);

    $this->assertCount(0, $data);
  }

}
