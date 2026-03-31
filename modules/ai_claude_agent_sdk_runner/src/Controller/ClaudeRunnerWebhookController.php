<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk_runner\Controller;

use Drupal\ai_claude_agent_sdk_runner\Service\ExecutionStore;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Receives webhook events from the sidecar WebhookEmitter.
 *
 * Events are authenticated via HMAC token (X-Webhook-Token header)
 * and routed to handlers based on X-Event-Type header.
 */
class ClaudeRunnerWebhookController extends ControllerBase {

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
   * Main webhook receiver endpoint.
   */
  public function receive(Request $request): JsonResponse {
    // 1. Validate HMAC token.
    $token = $request->headers->get('X-Webhook-Token', '');
    $payload = json_decode($request->getContent(), TRUE) ?? [];
    $queryId = $payload['queryId'] ?? '';

    if (!$this->validateToken($token, $queryId)) {
      return new JsonResponse(['error' => 'unauthorized'], 403);
    }

    // 2. Route by event type.
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
   * Handles tool_use: stores event for result remapping.
   */
  protected function handleToolUse(array $payload): JsonResponse {
    $this->executionStore->addEvent($payload['queryId'], $payload);
    return new JsonResponse(['ok' => TRUE]);
  }

  /**
   * Handles assistant_text: stores text content event for polling.
   */
  protected function handleAssistantText(array $payload): JsonResponse {
    $this->executionStore->addEvent($payload['queryId'], $payload);
    return new JsonResponse(['ok' => TRUE]);
  }

  /**
   * Handles progress: updates execution metadata.
   */
  protected function handleProgress(array $payload): JsonResponse {
    $this->executionStore->updateStatus($payload['queryId'], 'running', [
      'loop_count' => $payload['loopCount'] ?? 0,
      'last_action' => $payload['lastAction'] ?? '',
    ]);
    return new JsonResponse(['ok' => TRUE]);
  }

  /**
   * Handles result: marks execution as completed with response.
   */
  protected function handleResult(array $payload): JsonResponse {
    $this->executionStore->updateStatus($payload['queryId'], 'completed', [
      'response' => $payload['response'] ?? '',
      'session_id' => $payload['sessionId'] ?? '',
      'total_turns' => $payload['totalTurns'] ?? 0,
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
    $this->executionStore->storePermissionRequest(
      $payload['queryId'],
      $payload['requestId'] ?? '',
      $payload['toolName'] ?? '',
      $payload['input'] ?? [],
      $payload['decisionReason'] ?? '',
      $payload['blockedPath'] ?? '',
    );

    return new JsonResponse(['ok' => TRUE]);
  }

  /**
   * Validates the webhook token against stored callback token.
   */
  protected function validateToken(string $token, string $queryId): bool {
    if (empty($token) || empty($queryId)) {
      return FALSE;
    }
    $storedToken = $this->executionStore->getCallbackToken($queryId);
    return !empty($storedToken) && hash_equals($storedToken, $token);
  }

}
