<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_claude_agent_sdk_runner\Unit;

use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai_claude_agent_sdk_runner\Controller\ClaudeRunnerPollController;
use Drupal\ai_claude_agent_sdk_runner\Service\ExecutionStore;
use Drupal\ai_claude_agent_sdk_runner\Service\ResultMapper;
use Drupal\ai_claude_agent_sdk_runner\Value\ExecutionResult;
use Drupal\Core\Session\AccountProxyInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the ClaudeRunnerPollController.
 *
 * @group ai_claude_agent_sdk_runner
 */
class ClaudeRunnerPollControllerTest extends TestCase {

  /**
   * Builds a controller with given mock behaviours.
   */
  private function buildController(
    ExecutionStore $store,
    ResultMapper $mapper,
    int $currentUserId,
  ): ClaudeRunnerPollController {
    $currentUser = $this->createMock(AccountProxyInterface::class);
    $currentUser->method('id')->willReturn((string) $currentUserId);
    return new ClaudeRunnerPollController($store, $mapper, $currentUser);
  }

  /**
   * Tests that a mismatched initiator UID returns 403.
   */
  public function testPollReturns403WhenInitiatorMismatch(): void {
    $store = $this->createMock(ExecutionStore::class);
    $store->method('getInitiatorUid')->with('q1')->willReturn(42);

    $mapper = $this->createMock(ResultMapper::class);
    $controller = $this->buildController($store, $mapper, 99);

    $response = $controller->poll(new Request(), 'q1');

    $this->assertSame(403, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('Access denied', $data['error']);
  }

  /**
   * Tests that a missing execution returns 404.
   */
  public function testPollReturns404WhenExecutionNotFound(): void {
    $store = $this->createMock(ExecutionStore::class);
    $store->method('getInitiatorUid')->with('q-missing')->willReturn(NULL);

    $mapper = $this->createMock(ResultMapper::class);
    $controller = $this->buildController($store, $mapper, 1);

    $response = $controller->poll(new Request(), 'q-missing');

    $this->assertSame(404, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('Execution not found', $data['error']);
  }

  /**
   * Tests that a running execution returns status=running with mapped events.
   */
  public function testPollReturnsRunningWithEvents(): void {
    $store = $this->createMock(ExecutionStore::class);
    $store->method('getInitiatorUid')->with('q2')->willReturn(5);
    $store->method('getResult')->with('q2')->willReturn(NULL);
    $store->method('getEvents')->with('q2')->willReturn([
      [
        'id' => '1',
        'event_type' => 'tool_use',
        'event_data' => json_encode(['tool' => 'read_file', 'input' => 'foo.php']),
        'timestamp' => '1700000000',
      ],
      [
        'id' => '2',
        'event_type' => 'tool_use',
        'event_data' => json_encode(['name' => 'bash', 'input' => 'ls -la']),
        'timestamp' => '1700000001',
      ],
    ]);

    $mapper = $this->createMock(ResultMapper::class);
    $controller = $this->buildController($store, $mapper, 5);

    $response = $controller->poll(new Request(), 'q2');

    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('running', $data['status']);
    $this->assertCount(2, $data['events']);
    $this->assertSame('tool_use', $data['events'][0]['type']);
    $this->assertSame('read_file', $data['events'][0]['tool']);
    $this->assertSame('foo.php', $data['events'][0]['input']);
    $this->assertSame('bash', $data['events'][1]['tool']);
  }

  /**
   * Tests that a completed execution returns status=completed with html.
   *
   * Also verifies storeSessionId, markPolled, and dispatchAgentEvents are
   * called exactly once.
   */
  public function testPollReturnsCompletedWithHtml(): void {
    // ExecutionResult is final, so we instantiate it directly.
    $result = new ExecutionResult(
      queryId: 'q3',
      agentId: 'content_creator',
      profileId: 'dev',
      jobId: 'job-1',
      status: 'completed',
      data: ['response' => 'Hello **world**', 'session_id' => 'sess-abc'],
    );

    $store = $this->createMock(ExecutionStore::class);
    $store->method('getInitiatorUid')->with('q3')->willReturn(7);
    $store->method('getResult')->with('q3')->willReturn($result);
    $store->method('isPolled')->with('q3')->willReturn(FALSE);
    $store->expects($this->once())->method('storeSessionId')->with('job-1', 'sess-abc');
    $store->expects($this->once())->method('markPolled')->with('q3');

    $chatMessage = new ChatMessage('assistant', 'Hello **world**');
    $chatOutput = new ChatOutput($chatMessage, ['Hello **world**'], []);

    $mapper = $this->createMock(ResultMapper::class);
    $mapper->expects($this->once())->method('mapToChatOutput')->with($result)->willReturn($chatOutput);
    $mapper->expects($this->once())->method('dispatchAgentEvents')
      ->with($result, 'content_creator', [], $chatOutput);

    $controller = $this->buildController($store, $mapper, 7);

    $response = $controller->poll(new Request(), 'q3');

    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('completed', $data['status']);
    $this->assertArrayHasKey('html', $data);
    // The response text should have passed through Xss::filter at minimum.
    $this->assertIsString($data['html']);
  }

  /**
   * Tests that an errored execution returns status=error with a message.
   */
  public function testPollReturnsErrorStatus(): void {
    // ExecutionResult is final, so we instantiate it directly.
    $result = new ExecutionResult(
      queryId: 'q4',
      agentId: 'content_creator',
      profileId: 'dev',
      jobId: 'job-2',
      status: 'error',
      data: ['error_message' => 'Model overloaded'],
    );

    $store = $this->createMock(ExecutionStore::class);
    $store->method('getInitiatorUid')->with('q4')->willReturn(3);
    $store->method('getResult')->with('q4')->willReturn($result);
    $store->method('isPolled')->with('q4')->willReturn(FALSE);
    $store->expects($this->never())->method('storeSessionId');
    $store->expects($this->once())->method('markPolled')->with('q4');

    $chatMessage = new ChatMessage('assistant', 'Claude Code execution failed: Model overloaded');
    $chatOutput = new ChatOutput($chatMessage, [], []);

    $mapper = $this->createMock(ResultMapper::class);
    $mapper->method('mapToChatOutput')->willReturn($chatOutput);
    $mapper->expects($this->once())->method('dispatchAgentEvents');

    $controller = $this->buildController($store, $mapper, 3);

    $response = $controller->poll(new Request(), 'q4');

    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('error', $data['status']);
    $this->assertSame('Model overloaded', $data['message']);
  }

  /**
   * Tests that ?since= filters events to only those with id > since value.
   */
  public function testPollWithSinceFiltersEvents(): void {
    $store = $this->createMock(ExecutionStore::class);
    $store->method('getInitiatorUid')->with('q5')->willReturn(1);
    $store->method('getResult')->with('q5')->willReturn(NULL);
    $store->method('getEvents')->with('q5')->willReturn([
      ['id' => '1', 'event_type' => 'tool_use', 'event_data' => '{}', 'timestamp' => '100'],
      ['id' => '2', 'event_type' => 'tool_use', 'event_data' => '{}', 'timestamp' => '101'],
      ['id' => '3', 'event_type' => 'tool_use', 'event_data' => '{}', 'timestamp' => '102'],
    ]);

    $mapper = $this->createMock(ResultMapper::class);
    $controller = $this->buildController($store, $mapper, 1);

    $request = new Request(['since' => '1']);
    $response = $controller->poll($request, 'q5');

    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('running', $data['status']);
    $this->assertCount(2, $data['events']);
    $this->assertSame(2, $data['events'][0]['id']);
    $this->assertSame(3, $data['events'][1]['id']);
  }

  /**
   * Tests that a second poll after completion does not re-dispatch events.
   */
  public function testPollSkipsDuplicateEventDispatch(): void {
    // ExecutionResult is final, so we instantiate it directly.
    $result = new ExecutionResult(
      queryId: 'q6',
      agentId: 'content_creator',
      profileId: 'dev',
      jobId: 'job-3',
      status: 'completed',
      data: ['response' => 'Done'],
    );

    $store = $this->createMock(ExecutionStore::class);
    $store->method('getInitiatorUid')->with('q6')->willReturn(2);
    $store->method('getResult')->with('q6')->willReturn($result);
    // Already polled — simulate second poll.
    $store->method('isPolled')->with('q6')->willReturn(TRUE);
    $store->expects($this->never())->method('markPolled');
    $store->expects($this->never())->method('storeSessionId');

    $mapper = $this->createMock(ResultMapper::class);
    $mapper->expects($this->never())->method('mapToChatOutput');
    $mapper->expects($this->never())->method('dispatchAgentEvents');

    $controller = $this->buildController($store, $mapper, 2);

    $response = $controller->poll(new Request(), 'q6');

    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('completed', $data['status']);
  }

  /**
   * Tests that assistant_text events include sanitized text and tool events have empty text.
   */
  public function testPollReturnsTextEventsAlongsideToolEvents(): void {
    $store = $this->createMock(ExecutionStore::class);
    $store->method('getInitiatorUid')->with('q7')->willReturn(10);
    $store->method('getResult')->with('q7')->willReturn(NULL);
    $store->method('getEvents')->with('q7')->willReturn([
      [
        'id' => '1',
        'event_type' => 'assistant_text',
        'event_data' => json_encode(['text' => 'Let me **look** at that.']),
        'timestamp' => '1700000000',
      ],
      [
        'id' => '2',
        'event_type' => 'tool_use',
        'event_data' => json_encode(['toolName' => 'Read', 'input' => ['file_path' => 'foo.php']]),
        'timestamp' => '1700000001',
      ],
      [
        'id' => '3',
        'event_type' => 'assistant_text',
        'event_data' => json_encode(['text' => 'I see the issue.']),
        'timestamp' => '1700000002',
      ],
    ]);

    $mapper = $this->createMock(ResultMapper::class);
    $controller = $this->buildController($store, $mapper, 10);

    $response = $controller->poll(new Request(), 'q7');

    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('running', $data['status']);
    $this->assertCount(3, $data['events']);

    // First event: assistant_text with text field populated.
    $this->assertSame('assistant_text', $data['events'][0]['type']);
    $this->assertNotEmpty($data['events'][0]['text']);
    $this->assertSame('', $data['events'][0]['tool']);

    // Second event: tool_use with empty text field.
    $this->assertSame('tool_use', $data['events'][1]['type']);
    $this->assertSame('Read', $data['events'][1]['tool']);
    $this->assertSame('foo.php', $data['events'][1]['input']);
    $this->assertSame('', $data['events'][1]['text']);

    // Third event: assistant_text.
    $this->assertSame('assistant_text', $data['events'][2]['type']);
    $this->assertNotEmpty($data['events'][2]['text']);
  }

}
