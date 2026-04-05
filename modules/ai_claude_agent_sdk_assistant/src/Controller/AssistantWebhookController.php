<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk_assistant\Controller;

use Drupal\ai_claude_agent_sdk_runner\Service\ExecutionStore;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Receives webhook events from the Claude Code sidecar.
 *
 * Events are validated via HMAC callback token and routed to the
 * ExecutionStore for poll-based tracking by the DeepChat UI.
 */
class AssistantWebhookController extends ControllerBase {

  public function __construct(
    protected readonly ExecutionStore $executionStore,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_claude_agent_sdk_runner.execution_store'),
    );
  }

  /**
   * Receives and routes a sidecar webhook event.
   */
  public function receive(Request $request): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE) ?? [];
    $queryId = $payload['queryId'] ?? '';

    if ($queryId === '') {
      return new JsonResponse(['error' => 'missing queryId'], 400);
    }

    // Validate callback token via X-Callback-Token header.
    $token = $request->headers->get('X-Callback-Token', '');
    $storedToken = $this->executionStore->getCallbackToken($queryId);
    if ($storedToken !== NULL && !hash_equals($storedToken, $token)) {
      return new JsonResponse(['error' => 'invalid token'], 403);
    }

    // Route by event type.
    $eventType = $request->headers->get('X-Event-Type', '');

    return match ($eventType) {
      'query_start' => $this->handleQueryStart($payload),
      'tool_use' => $this->handleToolUse($payload),
      'assistant_text' => $this->handleAssistantText($payload),
      'progress' => $this->handleProgress($payload),
      'result' => $this->handleResult($payload),
      'error' => $this->handleError($payload),
      'permission_request' => $this->handlePermissionRequest($payload),
      default => new JsonResponse(['error' => 'unknown event type'], 400),
    };
  }

  /**
   * Handles query_start: marks execution as running.
   */
  protected function handleQueryStart(array $payload): JsonResponse {
    $this->executionStore->updateStatus($payload['queryId'], 'running', [
      'session_id' => $payload['sessionId'] ?? NULL,
    ]);
    return new JsonResponse(['ok' => TRUE]);
  }

  /**
   * Handles tool_use: stores as event.
   */
  protected function handleToolUse(array $payload): JsonResponse {
    $this->executionStore->addEvent($payload['queryId'], $payload);
    return new JsonResponse(['ok' => TRUE]);
  }

  /**
   * Handles assistant_text: stores as event.
   */
  protected function handleAssistantText(array $payload): JsonResponse {
    $this->executionStore->addEvent($payload['queryId'], $payload);
    return new JsonResponse(['ok' => TRUE]);
  }

  /**
   * Handles progress: stores as event.
   */
  protected function handleProgress(array $payload): JsonResponse {
    $this->executionStore->addEvent($payload['queryId'], $payload);
    return new JsonResponse(['ok' => TRUE]);
  }

  /**
   * Handles result: marks execution as completed with response data.
   */
  protected function handleResult(array $payload): JsonResponse {
    $this->executionStore->updateStatus($payload['queryId'], 'completed', [
      'response' => $payload['response'] ?? '',
      'session_id' => $payload['sessionId'] ?? NULL,
      'total_turns' => $payload['totalTurns'] ?? NULL,
    ]);
    return new JsonResponse(['ok' => TRUE]);
  }

  /**
   * Handles error: marks execution as failed.
   */
  protected function handleError(array $payload): JsonResponse {
    $this->executionStore->updateStatus($payload['queryId'], 'error', [
      'error_type' => $payload['errorType'] ?? 'unknown',
      'error_message' => $payload['message'] ?? '',
    ]);
    return new JsonResponse(['ok' => TRUE]);
  }

  /**
   * Handles permission_request: stores for poll-based approval.
   */
  protected function handlePermissionRequest(array $payload): JsonResponse {
    // Store as event for the poll progress list.
    $this->executionStore->addEvent($payload['queryId'], $payload);

    // Store dedicated permission record for approval tracking.
    $inputData = $payload['input'] ?? [];
    if (!empty($payload['agentId'])) {
      $inputData['_agentId'] = $payload['agentId'];
    }
    $this->executionStore->storePermissionRequest(
      $payload['queryId'],
      $payload['requestId'] ?? '',
      $payload['toolName'] ?? '',
      $inputData,
      $payload['decisionReason'] ?? '',
      $payload['blockedPath'] ?? '',
    );

    return new JsonResponse(['ok' => TRUE]);
  }

}
