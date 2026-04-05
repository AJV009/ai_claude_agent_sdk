<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_claude_agent_sdk_runner\Unit;

use Drupal\ai_claude_agent_sdk_runner\Controller\ClaudeRunnerWebhookController;
use Drupal\ai_claude_agent_sdk_runner\Service\ExecutionStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the ClaudeRunnerWebhookController.
 *
 * @group ai_claude_agent_sdk_runner
 */
class WebhookControllerTest extends TestCase {

  /**
   * Tests that empty token returns 403.
   */
  public function testEmptyTokenReturns403(): void {
    $store = $this->createMock(ExecutionStore::class);
    $controller = new ClaudeRunnerWebhookController($store);

    $request = new Request([], [], [], [], [], [
      'HTTP_X_WEBHOOK_TOKEN' => '',
      'HTTP_X_EVENT_TYPE' => 'result',
    ], json_encode(['queryId' => 'q1']));

    $response = $controller->receive($request);
    $this->assertSame(403, $response->getStatusCode());
  }

  /**
   * Tests that invalid token returns 403.
   */
  public function testInvalidTokenReturns403(): void {
    $store = $this->createMock(ExecutionStore::class);
    $store->method('getCallbackToken')
      ->with('q1')
      ->willReturn('correct-token');

    $controller = new ClaudeRunnerWebhookController($store);

    $request = new Request([], [], [], [], [], [
      'HTTP_X_WEBHOOK_TOKEN' => 'wrong-token',
      'HTTP_X_EVENT_TYPE' => 'result',
    ], json_encode(['queryId' => 'q1']));

    $response = $controller->receive($request);
    $this->assertSame(403, $response->getStatusCode());
  }

  /**
   * Tests that valid token with unknown event type returns 400.
   */
  public function testUnknownEventTypeReturns400(): void {
    $store = $this->createMock(ExecutionStore::class);
    $store->method('getCallbackToken')
      ->with('q1')
      ->willReturn('valid-token');

    $controller = new ClaudeRunnerWebhookController($store);

    $request = new Request([], [], [], [], [], [
      'HTTP_X_WEBHOOK_TOKEN' => 'valid-token',
      'HTTP_X_EVENT_TYPE' => 'nonexistent_event',
    ], json_encode(['queryId' => 'q1']));

    $response = $controller->receive($request);
    $this->assertSame(400, $response->getStatusCode());
  }

  /**
   * Tests that valid result event returns 200.
   */
  public function testValidResultEventReturns200(): void {
    $store = $this->createMock(ExecutionStore::class);
    $store->method('getCallbackToken')
      ->with('q1')
      ->willReturn('valid-token');
    $store->expects($this->once())
      ->method('updateStatus')
      ->with('q1', 'completed', $this->anything());

    $controller = new ClaudeRunnerWebhookController($store);

    $request = new Request([], [], [], [], [], [
      'HTTP_X_WEBHOOK_TOKEN' => 'valid-token',
      'HTTP_X_EVENT_TYPE' => 'result',
    ], json_encode([
      'queryId' => 'q1',
      'response' => 'Done.',
      'sessionId' => 's1',
      'totalTurns' => 5,
    ]));

    $response = $controller->receive($request);
    $this->assertSame(200, $response->getStatusCode());
  }

  /**
   * Tests that assistant_text event is stored via addEvent.
   */
  public function testAssistantTextEventCallsAddEvent(): void {
    $store = $this->createMock(ExecutionStore::class);
    $store->method('getCallbackToken')
      ->with('q1')
      ->willReturn('valid-token');
    $store->expects($this->once())
      ->method('addEvent')
      ->with('q1', $this->callback(function ($payload) {
          return $payload['type'] === 'assistant_text'
              && $payload['text'] === 'Let me look at that file...';
      }));

    $controller = new ClaudeRunnerWebhookController($store);

    $request = new Request([], [], [], [], [], [
      'HTTP_X_WEBHOOK_TOKEN' => 'valid-token',
      'HTTP_X_EVENT_TYPE' => 'assistant_text',
    ], json_encode([
      'queryId' => 'q1',
      'type' => 'assistant_text',
      'text' => 'Let me look at that file...',
    ]));

    $response = $controller->receive($request);
    $this->assertSame(200, $response->getStatusCode());
  }

}
