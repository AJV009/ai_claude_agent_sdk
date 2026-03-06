<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk\Controller;

use Drupal\ai_claude_agent_sdk\Service\ClaudeBridgeService;
use Drupal\ai_claude_agent_sdk\Service\ClaudeAgentSdkProcessLimiter;
use Drupal\ai_claude_agent_sdk\Service\ExecutionEnvelopeService;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SSE streaming endpoint for AI Assistants (DeepChat, CKEditor, ChatForm).
 */
final class ClaudeAssistantController extends ControllerBase {

  public function __construct(
    private readonly ClaudeBridgeService $bridge,
    private readonly ClaudeAgentSdkProcessLimiter $processLimiter,
    private readonly ExecutionEnvelopeService $envelopeService,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('ai_claude_agent_sdk.bridge'),
      $container->get('ai_claude_agent_sdk.process_limiter'),
      $container->get('ai_claude_agent_sdk.execution_envelope'),
    );
  }

  public function stream(Request $request): StreamedResponse {
    $content = $request->getContent();
    if ($content === '' || $content === NULL) {
      return $this->errorResponse('POST JSON body required.', 400);
    }

    $payload = Json::decode($content);
    if (!is_array($payload)) {
      return $this->errorResponse('Invalid JSON body.', 400);
    }

    $prompt = trim((string) ($payload['prompt'] ?? ''));
    $profileId = trim((string) ($payload['profile'] ?? 'default'));
    $session = isset($payload['session']) ? trim((string) $payload['session']) : NULL;

    if ($prompt === '' && $session === NULL) {
      return $this->errorResponse('prompt or session is required.', 400);
    }

    $profile = $this->entityTypeManager()->getStorage('agent_profile')->load($profileId);
    if (!$profile) {
      return $this->errorResponse("Agent profile '$profileId' not found.", 404);
    }

    if (!$this->processLimiter->canStart()) {
      $status = $this->processLimiter->getStatus();
      return $this->errorResponse(
        sprintf('Claude CLI limit reached (%d running, limit %d). Try again later.', $status['running'], $status['limit']),
        429,
      );
    }

    $profileData = $profile->toSidecarFormat();

    // Create execution envelope for identity tracking.
    $envelope = $this->envelopeService->create($profile);
    $mcpHeaders = $this->envelopeService->buildMcpHeaders($envelope);

    // Determine interactive permission mode from profile modality.
    $isInteractive = ($profile->getExecutionModality() === 'interactive');
    $permissionTimeout = (int) ($this->config('ai_claude_agent_sdk.settings')->get('permission_timeout') ?? 120) * 1000;

    $response = new StreamedResponse();
    $response->headers->set('Content-Type', 'text/event-stream');
    $response->headers->set('Cache-Control', 'no-cache');
    $response->headers->set('Connection', 'keep-alive');
    $response->headers->set('X-Accel-Buffering', 'no');

    $response->setCallback(function () use ($profileData, $prompt, $session, $mcpHeaders, $isInteractive, $permissionTimeout) {
      if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
      }
      set_time_limit(0);

      try {
        $this->bridge->stream($profileData, $prompt, $session, function (string $chunk) {
          echo $chunk;
          if (function_exists('ob_flush')) {
            @ob_flush();
          }
          flush();
        }, $mcpHeaders, $isInteractive, $permissionTimeout);
      }
      catch (\Throwable $e) {
        echo 'data: ' . Json::encode(['type' => 'error', 'message' => $e->getMessage()]) . "\n\n";
        echo "data: [DONE]\n\n";
        if (function_exists('ob_flush')) {
          @ob_flush();
        }
        flush();
      }
    });

    return $response;
  }

  /**
   * Handle a permission response from the browser.
   */
  public function permissionResponse(Request $request): JsonResponse {
    $content = $request->getContent();
    if ($content === '' || $content === NULL) {
      return new JsonResponse(['error' => 'POST JSON body required.'], 400);
    }

    $payload = Json::decode($content);
    if (!is_array($payload)) {
      return new JsonResponse(['error' => 'Invalid JSON body.'], 400);
    }

    $queryId = trim((string) ($payload['queryId'] ?? ''));
    $requestId = trim((string) ($payload['requestId'] ?? ''));
    $behavior = trim((string) ($payload['behavior'] ?? ''));
    $message = isset($payload['message']) ? trim((string) $payload['message']) : NULL;

    if ($queryId === '' || $requestId === '' || $behavior === '') {
      return new JsonResponse(['error' => 'queryId, requestId, and behavior are required.'], 400);
    }

    if (!in_array($behavior, ['allow', 'deny'], TRUE)) {
      return new JsonResponse(['error' => "behavior must be 'allow' or 'deny'."], 400);
    }

    $result = $this->bridge->sendPermissionResponse($queryId, $requestId, $behavior, $message);

    if ($result['success']) {
      return new JsonResponse(['ok' => TRUE]);
    }

    return new JsonResponse(
      ['error' => $result['body']['error'] ?? 'Failed to forward permission response.'],
      $result['http_code'] ?: 502,
    );
  }

  private function errorResponse(string $message, int $status): StreamedResponse {
    $response = new StreamedResponse(function () use ($message) {
      echo 'data: ' . Json::encode(['type' => 'error', 'message' => $message]) . "\n\n";
      echo "data: [DONE]\n\n";
    }, $status);
    $response->headers->set('Content-Type', 'text/event-stream');
    $response->headers->set('Cache-Control', 'no-cache');
    return $response;
  }

}
