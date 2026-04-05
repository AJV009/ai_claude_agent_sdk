<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk_assistant\Controller;

use Drupal\ai_claude_agent_sdk_runner\Service\ExecutionStore;
use Drupal\ai_claude_agent_sdk_runner\Service\ResultMapper;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Polling endpoint for async Claude Code executions.
 *
 * Provides the DeepChat UI with execution status, tool progress events,
 * and pending permission requests for interactive approval.
 */
class AssistantPollController {

  public function __construct(
    protected readonly ExecutionStore $executionStore,
    protected readonly ResultMapper $resultMapper,
    protected readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Returns execution status and events for a given queryId.
   */
  public function poll(Request $request, string $queryId): JsonResponse {
    // Access check: only the initiator can poll.
    $initiatorUid = $this->executionStore->getInitiatorUid($queryId);
    if ($initiatorUid === NULL) {
      return new JsonResponse(['error' => 'Execution not found'], 404);
    }
    if ($initiatorUid !== (int) $this->currentUser->id()) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }

    // Check if execution has completed or errored.
    $result = $this->executionStore->getResult($queryId);

    if ($result !== NULL) {
      // First-time completion handling.
      if (!$this->executionStore->isPolled($queryId)) {
        // Store session_id for multi-turn resumption.
        $sessionId = $result->getSessionId();
        if ($sessionId) {
          $this->executionStore->storeSessionId($result->getJobId(), $sessionId);
        }

        // Dispatch AgentFinishedExecutionEvent (once only).
        $chatOutput = $this->resultMapper->mapToChatOutput($result);
        $this->resultMapper->dispatchAgentEvents($result, $result->getAgentId(), [], $chatOutput);

        $this->executionStore->markPolled($queryId);
      }

      if ($result->isError()) {
        return new JsonResponse([
          'status' => 'error',
          'message' => $result->getErrorMessage() ?: 'Unknown error',
        ]);
      }

      // Process response through markdown/XSS like DeepChatApi does.
      $html = $this->processResponseHtml($result->getResponse() ?: '');

      return new JsonResponse([
        'status' => 'completed',
        'html' => $html,
      ]);
    }

    // Still running — return events.
    $events = $this->executionStore->getEvents($queryId);

    // Filter by ?since= parameter for incremental fetching.
    $since = (int) $request->query->get('since', 0);
    if ($since > 0) {
      $events = array_values(array_filter($events, fn($e) => ((int) ($e['id'] ?? 0)) > $since));
    }

    // Map events to a client-friendly format.
    $mappedEvents = [];
    foreach ($events as $event) {
      $eventData = json_decode($event['event_data'] ?? '{}', TRUE) ?: [];
      $eventType = $event['event_type'] ?? 'unknown';
      $mapped = [
        'id' => (int) ($event['id'] ?? 0),
        'type' => $eventType,
        'tool' => $eventData['toolName'] ?? $eventData['tool'] ?? $eventData['name'] ?? '',
        'input' => $this->extractInputSummary($eventData['input'] ?? $eventData['file'] ?? ''),
        'text' => $eventType === 'assistant_text'
          ? $this->processResponseHtml($eventData['text'] ?? '')
          : '',
        'time' => (int) ($event['timestamp'] ?? 0),
      ];
      // Pass through was_ask annotation for transparency in the UI.
      if (!empty($eventData['was_ask'])) {
        $mapped['was_ask'] = TRUE;
        $mapped['was_ask_reason'] = $eventData['was_ask_reason'] ?? '';
      }
      $mappedEvents[] = $mapped;
    }

    // Include pending permission requests for interactive approval.
    $pendingPermissions = $this->executionStore->getPendingPermissions($queryId);
    $mappedPermissions = [];
    foreach ($pendingPermissions as $perm) {
      $input = json_decode($perm['input_data'] ?? '{}', TRUE) ?: [];
      $agentId = $input['_agentId'] ?? '';
      unset($input['_agentId']);

      $mapped = [
        'requestId' => $perm['request_id'],
        'toolName' => $perm['tool_name'],
        'input' => $input,
        'decisionReason' => $perm['decision_reason'] ?? '',
        'blockedPath' => $perm['blocked_path'] ?? '',
      ];
      if ($agentId !== '') {
        $mapped['agentId'] = $agentId;
      }
      $mappedPermissions[] = $mapped;
    }

    return new JsonResponse([
      'status' => 'running',
      'events' => $mappedEvents,
      'pendingPermissions' => $mappedPermissions,
    ]);
  }

  /**
   * Aborts a running execution by forwarding to the sidecar.
   */
  public function abort(string $queryId): JsonResponse {
    $initiatorUid = $this->executionStore->getInitiatorUid($queryId);
    if ($initiatorUid === NULL) {
      return new JsonResponse(['error' => 'Execution not found'], 404);
    }
    if ($initiatorUid !== (int) $this->currentUser->id()) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }

    $sidecarUrl = \Drupal::config('ai_claude_agent_sdk.settings')->get('sidecar_url') ?: 'http://localhost:3578';
    try {
      \Drupal::httpClient()->post(rtrim($sidecarUrl, '/') . '/api/queries/' . $queryId . '/abort', [
        'timeout' => 10,
      ]);
    }
    catch (\Exception) {
      // Sidecar may not be running — mark as error locally.
    }

    $this->executionStore->updateStatus($queryId, 'error', [
      'error_type' => 'aborted',
      'error_message' => 'Execution aborted by user.',
    ]);

    return new JsonResponse(['ok' => TRUE]);
  }

  /**
   * Processes response text through markdown conversion and XSS filter.
   */
  protected function processResponseHtml(string $text): string {
    if (class_exists('League\CommonMark\GithubFlavoredMarkdownConverter')) {
      $converter = new \League\CommonMark\GithubFlavoredMarkdownConverter();
      $text = $converter->convert($text)->__toString();
    }
    elseif (class_exists('League\CommonMark\CommonMarkConverter')) {
      $converter = new \League\CommonMark\CommonMarkConverter();
      $text = $converter->convert($text)->__toString();
    }
    $allowedTags = ['a', 'b', 'blockquote', 'br', 'code', 'del', 'em',
      'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'i', 'img',
      'li', 'ol', 'p', 'pre', 'strong', 'ul',
      'table', 'thead', 'tbody', 'tr', 'th', 'td'];
    return \Drupal\Component\Utility\Xss::filter($text, $allowedTags);
  }

  /**
   * Extracts a human-readable summary from tool input data.
   */
  protected function extractInputSummary(mixed $input): string {
    if (is_string($input)) {
      return $input;
    }
    if (is_array($input)) {
      return $input['file_path'] ?? $input['command'] ?? $input['path'] ?? $input['file'] ?? '';
    }
    return '';
  }

}
