<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk_debug\Controller;

use Drupal\ai_claude_agent_sdk\Service\ClaudeBridgeService;
use Drupal\ai_claude_agent_sdk_debug\Session\SessionTracker;
use Drupal\ai_claude_agent_sdk_debug\Support\PermissionPresetHelper;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ClaudeAgentSdkDebugStreamController extends ControllerBase {

  public function __construct(
    private readonly ClaudeBridgeService $bridge,
    private readonly SessionTracker $sessionTracker,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('ai_claude_agent_sdk.bridge'),
      $container->get('ai_claude_agent_sdk_debug.session_tracker'),
    );
  }

  public function stream(Request $request): StreamedResponse {
    if ($request->getContent() === '' || $request->getContent() === null) {
      return new StreamedResponse(function () {
        echo "data: " . Json::encode(['type' => 'error', 'message' => 'POST JSON body required.']) . "\n\n";
      }, 400);
    }

    $payload = Json::decode($request->getContent() ?? '');
    if (!is_array($payload)) {
      return new StreamedResponse(function () {
        echo "data: " . Json::encode(['type' => 'error', 'message' => 'Invalid JSON body.']) . "\n\n";
      }, 400);
    }

    // Extract prompt from messages array or direct prompt field.
    $prompt = '';
    $messages = $payload['messages'] ?? [];
    if (is_array($messages) && !empty($messages)) {
      foreach ($messages as $message) {
        if (is_array($message) && ($message['type'] ?? '') === 'user') {
          $content = $message['message']['content'] ?? '';
          if (is_string($content) && $content !== '') {
            $prompt = $content;
            break;
          }
        }
      }
    }
    if ($prompt === '' && is_string($payload['prompt'] ?? null)) {
      $prompt = trim((string) $payload['prompt']);
    }

    $sdkOptions = is_array($payload['options'] ?? null) ? $payload['options'] : [];
    $sessionId = is_string($payload['session_id'] ?? null) ? $payload['session_id'] : null;
    $debugCallbacks = is_array($payload['debug_callbacks'] ?? null) ? $payload['debug_callbacks'] : [];

    // Apply permission preset.
    $permissionPresetResult = PermissionPresetHelper::apply($sdkOptions);
    $sdkOptions = is_array($permissionPresetResult['options'] ?? null) ? $permissionPresetResult['options'] : $sdkOptions;

    // Handle session resume.
    if ($sessionId !== null && $sessionId !== '' && $sessionId !== 'default') {
      $sdkOptions['resume'] = $sessionId;
      $sdkOptions['continueConversation'] = true;
    }

    // Handle interactive permissions via sidecar.
    $canUseTool = $debugCallbacks['can_use_tool'] ?? 'none';
    if ($canUseTool === 'interactive') {
      $sdkOptions['interactivePermissions'] = true;
      $sdkOptions['permissionTimeoutMs'] = 120000;
    }

    $sessionMeta = [
      'mode' => is_string($payload['mode'] ?? null) ? (string) $payload['mode'] : 'terminal',
      'source' => 'stream',
      'resume' => $sdkOptions['resume'] ?? null,
      'uid' => $this->currentUser()->isAuthenticated() ? (int) $this->currentUser()->id() : null,
    ];

    $response = new StreamedResponse();
    $response->headers->set('Content-Type', 'text/event-stream');
    $response->headers->set('Cache-Control', 'no-cache');
    $response->headers->set('Connection', 'keep-alive');
    $response->headers->set('X-Accel-Buffering', 'no');

    $response->setCallback(function () use ($prompt, $sdkOptions, $sessionMeta) {
      if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
      }

      try {
        $this->bridge->streamDirect($prompt, $sdkOptions, function (string $chunk) use ($sessionMeta) {
          // Parse SSE lines for session tracking, then pass through raw.
          $lines = explode("\n", $chunk);
          foreach ($lines as $line) {
            if (!str_starts_with($line, 'data: ')) {
              continue;
            }
            $jsonStr = substr($line, 6);
            if ($jsonStr === '[DONE]') {
              continue;
            }
            $decoded = json_decode($jsonStr, TRUE);
            if (!is_array($decoded)) {
              continue;
            }
            // Track session ID from result events.
            $sid = $decoded['session_id'] ?? null;
            if (is_string($sid) && $sid !== '' && $sid !== 'default') {
              $this->sessionTracker->record($sid, $sessionMeta);
            }
          }
          // Pass raw chunk straight through to browser.
          echo $chunk;
          if (function_exists('ob_flush')) {
            @ob_flush();
          }
          flush();
        });
      }
      catch (\Throwable $e) {
        echo "data: " . Json::encode(['type' => 'error', 'message' => $e->getMessage()]) . "\n\n";
        if (function_exists('ob_flush')) {
          @ob_flush();
        }
        flush();
      }
    });

    return $response;
  }

  public function permissionResponse(Request $request): JsonResponse {
    $payload = Json::decode($request->getContent() ?? '');
    if (!is_array($payload)) {
      return new JsonResponse(['ok' => false, 'error' => 'Invalid JSON body.'], 400);
    }

    $queryId = trim((string) ($payload['queryId'] ?? ''));
    $requestId = trim((string) ($payload['requestId'] ?? ''));
    $behavior = trim((string) ($payload['behavior'] ?? ''));

    if ($queryId === '' || $requestId === '' || $behavior === '') {
      return new JsonResponse(['ok' => false, 'error' => 'queryId, requestId, and behavior are required.'], 400);
    }

    if (!in_array($behavior, ['allow', 'deny'], true)) {
      return new JsonResponse(['ok' => false, 'error' => 'Unsupported behavior value.'], 400);
    }

    $result = $this->bridge->sendPermissionResponse($queryId, $requestId, $behavior);
    return new JsonResponse(['ok' => $result['success']]);
  }

}
