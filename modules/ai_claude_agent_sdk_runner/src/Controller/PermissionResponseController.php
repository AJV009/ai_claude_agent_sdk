<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk_runner\Controller;

use Drupal\ai_claude_agent_sdk_runner\Service\ExecutionStore;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountProxyInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Receives permission decisions from the frontend and forwards to the sidecar.
 */
class PermissionResponseController extends ControllerBase {

  /**
   * The config factory (promoted name conflicts with ControllerBase parent).
   */
  private ConfigFactoryInterface $config;

  /**
   * The current user (promoted name conflicts with ControllerBase parent).
   */
  private AccountProxyInterface $user;

  public function __construct(
    protected readonly ExecutionStore $executionStore,
    protected readonly ClientInterface $httpClient,
    ConfigFactoryInterface $configFactory,
    AccountProxyInterface $currentUser,
  ) {
    $this->config = $configFactory;
    $this->user = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_claude_agent_sdk_runner.execution_store'),
      $container->get('http_client'),
      $container->get('config.factory'),
      $container->get('current_user'),
    );
  }

  /**
   * Handles a permission response from the user.
   */
  public function respond(Request $request): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE) ?? [];
    $queryId = $payload['queryId'] ?? '';
    $requestId = $payload['requestId'] ?? '';
    $behavior = $payload['behavior'] ?? '';
    $message = $payload['message'] ?? '';

    // Validate required fields.
    if ($queryId === '' || $requestId === '' || !in_array($behavior, ['allow', 'deny'], TRUE)) {
      return new JsonResponse(['error' => 'Missing or invalid fields'], 400);
    }

    // Validate: user must be the initiator of this execution.
    $initiatorUid = $this->executionStore->getInitiatorUid($queryId);
    if ($initiatorUid === NULL) {
      return new JsonResponse(['error' => 'Execution not found'], 404);
    }
    if ($initiatorUid !== (int) $this->user->id()) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }

    // Mark resolved in DB.
    $this->executionStore->resolvePermissionRequest($queryId, $requestId, $behavior, $message);

    // Build sidecar payload.
    $sidecarPayload = [
      'queryId' => $queryId,
      'requestId' => $requestId,
      'behavior' => $behavior,
    ];
    if ($behavior === 'deny' && $message !== '') {
      $sidecarPayload['message'] = $message;
    }

    // Forward to sidecar.
    $sidecarUrl = $this->config->get('ai_claude_agent_sdk.settings')->get('sidecar_url') ?: 'http://localhost:3578';
    try {
      $this->httpClient->post(rtrim($sidecarUrl, '/') . '/api/query/permission-response', [
        'json' => $sidecarPayload,
        'timeout' => 10,
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => 'Failed to forward to sidecar: ' . $e->getMessage()], 502);
    }

    return new JsonResponse(['ok' => TRUE]);
  }

}
