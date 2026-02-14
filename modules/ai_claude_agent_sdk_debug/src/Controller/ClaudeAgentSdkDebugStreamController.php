<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk_debug\Controller;

use Claude\AgentSdk\ClaudeAgentOptions;
use Claude\AgentSdk\Client;
use Claude\AgentSdk\Types\PermissionResultAllow;
use Claude\AgentSdk\Types\PermissionResultDeny;
use Drupal\ai_claude_agent_sdk\Service\ClaudeAgentSdkAuthEnvResolver;
use Drupal\ai_claude_agent_sdk\Service\ClaudeAgentSdkProcessLimiter;
use Drupal\ai_claude_agent_sdk_debug\Session\SessionFileStore;
use Drupal\ai_claude_agent_sdk_debug\Support\PermissionPresetHelper;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\ai_claude_agent_sdk_debug\Session\SessionTracker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ClaudeAgentSdkDebugStreamController extends ControllerBase {

  private KeyValueStoreExpirableInterface $permissionDecisions;

  private KeyValueStoreExpirableInterface $sessionPermissionGrants;

  public function __construct(
    private readonly SessionTracker $sessionTracker,
    private readonly SessionFileStore $sessionFileStore,
    private readonly ClaudeAgentSdkProcessLimiter $processLimiter,
    private readonly ClaudeAgentSdkAuthEnvResolver $authEnvResolver,
    KeyValueExpirableFactoryInterface $keyValueExpirableFactory,
  ) {
    $this->permissionDecisions = $keyValueExpirableFactory->get('ai_claude_agent_sdk_debug.permission_decisions');
    $this->sessionPermissionGrants = $keyValueExpirableFactory->get('ai_claude_agent_sdk_debug.session_permission_grants');
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('ai_claude_agent_sdk_debug.session_tracker'),
      $container->get('ai_claude_agent_sdk_debug.session_file_store'),
      $container->get('ai_claude_agent_sdk.process_limiter'),
      $container->get('ai_claude_agent_sdk.auth_env_resolver'),
      $container->get('keyvalue.expirable'),
    );
  }

  public function stream(Request $request): StreamedResponse {
    if ($request->getContent() === '' || $request->getContent() === null) {
      return new StreamedResponse(function () {
        echo "data: " . Json::encode(['error' => 'POST JSON body required.']) . "\n\n";
      }, 400);
    }

    $payload = Json::decode($request->getContent() ?? '');
    if (!is_array($payload)) {
      return new StreamedResponse(function () {
        echo "data: " . Json::encode(['error' => 'Invalid JSON body.']) . "\n\n";
      }, 400);
    }

    $optionsData = is_array($payload['options'] ?? null) ? $payload['options'] : [];
    $mode = is_string($payload['mode'] ?? null) ? (string) $payload['mode'] : 'client';
    if (($mode === 'client' || $mode === 'terminal') && !isset($optionsData['initializeTimeout'])) {
      $optionsData['initializeTimeout'] = 2.0;
    }
    if (($mode === 'client' || $mode === 'terminal') && !isset($optionsData['skipInitialize'])) {
      $optionsData['skipInitialize'] = TRUE;
    }
    $requestedResume = is_string($optionsData['resume'] ?? null) ? trim((string) $optionsData['resume']) : '';
    if ($requestedResume !== '' && count($this->sessionFileStore->listSessionFiles($requestedResume)) === 0) {
      return new StreamedResponse(function () use ($requestedResume) {
        echo "data: " . Json::encode(['error' => 'Resume session ID not found in local Claude session files: ' . $requestedResume]) . "\n\n";
      }, 400);
    }

    $debugCallbacks = is_array($payload['debug_callbacks'] ?? null) ? $payload['debug_callbacks'] : [];
    $runtimeId = is_string($payload['runtime_id'] ?? null) ? trim((string) $payload['runtime_id']) : '';
    $requestingUid = $this->currentUser()->isAuthenticated() ? (int) $this->currentUser()->id() : 0;
    $messages = $payload['messages'] ?? [];
    if (!is_array($messages)) {
      $messages = [];
    }
    $sessionId = is_string($payload['session_id'] ?? null) ? $payload['session_id'] : null;
    if ($sessionId === 'default') {
      $sessionId = null;
    }
    if ($sessionId !== null) {
      foreach ($messages as $idx => $message) {
        if (is_array($message) && !isset($message['session_id'])) {
          $messages[$idx]['session_id'] = $sessionId;
        }
      }
    }

    $control = $payload['control'] ?? null;
    $sessionMeta = [
      'mode' => $mode,
      'source' => 'stream',
      'resume' => is_string($optionsData['resume'] ?? null) ? (string) $optionsData['resume'] : null,
      'uid' => $this->currentUser()->isAuthenticated() ? (int) $this->currentUser()->id() : null,
    ];

    $response = new StreamedResponse();
    $response->headers->set('Content-Type', 'text/event-stream');
    $response->headers->set('Cache-Control', 'no-cache');
    $response->headers->set('Connection', 'keep-alive');
    $response->headers->set('X-Accel-Buffering', 'no');

    $response->setCallback(function () use ($optionsData, $debugCallbacks, $runtimeId, $requestingUid, $messages, $control, $sessionId, $sessionMeta, $requestedResume, $mode) {
      if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
      }

      $emit = function (array $payload): void {
        echo 'data: ' . Json::encode($payload) . "\n\n";
        if (function_exists('ob_flush')) {
          @ob_flush();
        }
        flush();
      };

      $emit(['status' => 'connected', 'session_id' => $sessionId]);

      if (!$this->processLimiter->canStart()) {
        $status = $this->processLimiter->getStatus();
        $emit([
          'error' => sprintf('Claude CLI limit reached (%d running, limit %d).', $status['running'], $status['limit']),
          'session_id' => $sessionId,
        ]);
        return;
      }

      $options = $this->buildOptions(
        $optionsData,
        $debugCallbacks,
        $emit,
        $runtimeId,
        $requestingUid
      );
      $client = new Client($options);

      $stream = (function () use ($messages): iterable {
        foreach ($messages as $message) {
          if (is_array($message)) {
            yield $message;
          }
        }
      })();

      try {
        $client->connect($stream);
        $emit(['status' => 'client_connected', 'session_id' => $sessionId]);

        if (is_array($control)) {
          $this->applyControl($client, $control);
        }

        foreach ($client->receiveMessages() as $message) {
          $raw = $message->getRaw();
          $sessionIdFromMessage = $raw['session_id'] ?? $sessionId;
          if ($requestedResume !== '' && is_string($sessionIdFromMessage) && $sessionIdFromMessage !== '' && $sessionIdFromMessage !== $requestedResume) {
            throw new \RuntimeException(sprintf('Resume session mismatch: requested %s but Claude returned %s. Response rejected.', $requestedResume, $sessionIdFromMessage));
          }
          if (is_string($sessionIdFromMessage) && $sessionIdFromMessage !== '' && $sessionIdFromMessage !== 'default') {
            $this->sessionTracker->record($sessionIdFromMessage, $sessionMeta);
          }
          $emit([
            'message' => $raw,
            'session_id' => $sessionIdFromMessage,
          ]);
        }
      }
      catch (\Throwable $e) {
        $emit(['error' => $e->getMessage(), 'session_id' => $sessionId]);
      }
      finally {
        $client->close();
      }
    });

    return $response;
  }

  private function buildOptions(
    array $optionsData,
    array $debugCallbacks,
    ?callable $permissionRequestEmitter = null,
    string $runtimeId = '',
    int $requestingUid = 0,
  ): ClaudeAgentOptions {
    $permissionPresetResult = PermissionPresetHelper::apply($optionsData);
    $optionsData = is_array($permissionPresetResult['options'] ?? null) ? $permissionPresetResult['options'] : $optionsData;

    $cliPath = $optionsData['cliPath'] ?? getenv('CLAUDE_CLI_PATH') ?: null;
    $cwd = $optionsData['cwd'] ?? DRUPAL_ROOT;

    $canUseTool = $this->buildCanUseToolCallback($debugCallbacks, $permissionRequestEmitter, $runtimeId, $requestingUid);
    $hooks = $this->buildHookConfig($debugCallbacks);
    $mcpHandler = $this->buildMcpHandler($debugCallbacks);

    return new ClaudeAgentOptions(
      cliPath: $cliPath,
      cwd: $cwd,
      systemPrompt: $optionsData['systemPrompt'] ?? null,
      tools: $optionsData['tools'] ?? null,
      allowedTools: $optionsData['allowedTools'] ?? null,
      disallowedTools: $optionsData['disallowedTools'] ?? null,
      maxTurns: $optionsData['maxTurns'] ?? null,
      maxBudgetUsd: $optionsData['maxBudgetUsd'] ?? null,
      model: $optionsData['model'] ?? null,
      fallbackModel: $optionsData['fallbackModel'] ?? null,
      betas: $optionsData['betas'] ?? null,
      permissionPromptToolName: $optionsData['permissionPromptToolName'] ?? null,
      permissionMode: $optionsData['permissionMode'] ?? null,
      continueConversation: $optionsData['continueConversation'] ?? false,
      resume: $optionsData['resume'] ?? null,
      settings: $optionsData['settings'] ?? null,
      sandbox: $optionsData['sandbox'] ?? null,
      addDirs: $optionsData['addDirs'] ?? null,
      mcpServers: $optionsData['mcpServers'] ?? null,
      includePartialMessages: $optionsData['includePartialMessages'] ?? false,
      forkSession: $optionsData['forkSession'] ?? false,
      agents: $optionsData['agents'] ?? null,
      settingSources: $optionsData['settingSources'] ?? null,
      plugins: $optionsData['plugins'] ?? null,
      maxThinkingTokens: $optionsData['maxThinkingTokens'] ?? null,
      outputFormat: $optionsData['outputFormat'] ?? null,
      maxBufferSize: $optionsData['maxBufferSize'] ?? null,
      enableFileCheckpointing: $optionsData['enableFileCheckpointing'] ?? false,
      canUseTool: $canUseTool,
      hooks: $hooks,
      mcpMessageHandler: $mcpHandler,
      skipInitialize: $optionsData['skipInitialize'] ?? false,
      initializeTimeout: $optionsData['initializeTimeout'] ?? null,
      user: $optionsData['user'] ?? null,
      env: $this->authEnvResolver->buildEnv($optionsData['env'] ?? []),
      extraArgs: $optionsData['extraArgs'] ?? [],
    );
  }

  private function applyControl(Client $client, array $control): void {
    $action = $control['action'] ?? null;
    if (!is_string($action)) {
      return;
    }

    if ($action === 'interrupt') {
      $client->interrupt();
    }
    elseif ($action === 'mcp_status') {
      $client->getMcpStatus();
    }
    elseif ($action === 'set_permission_mode') {
      $mode = PermissionPresetHelper::normalizePermissionMode($control['mode'] ?? null);
      $client->setPermissionMode($mode ?? 'default');
    }
    elseif ($action === 'set_model') {
      $model = $control['model'] ?? null;
      $client->setModel($model ? (string) $model : null);
    }
    elseif ($action === 'rewind_files') {
      $id = $control['user_message_id'] ?? null;
      if ($id) {
        $client->rewindFiles((string) $id);
      }
    }
  }

  private function buildCanUseToolCallback(
    array $debugCallbacks,
    ?callable $permissionRequestEmitter = null,
    string $runtimeId = '',
    int $requestingUid = 0,
  ): ?callable {
    $mode = $debugCallbacks['can_use_tool'] ?? 'none';
    if ($mode === 'none') {
      return null;
    }

    if ($mode === 'allow') {
      return function (string $toolName, array $input, $context) {
        return new PermissionResultAllow();
      };
    }

    if ($mode === 'deny') {
      $message = (string) ($debugCallbacks['can_use_tool_message'] ?? 'Denied by debug UI');
      $interrupt = (bool) ($debugCallbacks['can_use_tool_interrupt'] ?? false);
      return function (string $toolName, array $input, $context) use ($message, $interrupt) {
        return new PermissionResultDeny($message, $interrupt);
      };
    }

    if ($mode === 'interactive') {
      return function (string $toolName, array $input, $context) use ($permissionRequestEmitter, $runtimeId, $requestingUid) {
        if ($runtimeId !== '' && $requestingUid > 0 && $this->hasSessionPermissionGrant($requestingUid, $runtimeId, $toolName)) {
          return new PermissionResultAllow();
        }

        if ($permissionRequestEmitter === null) {
          return new PermissionResultDeny('Interactive permission requested but no stream emitter is available.', false);
        }

        $requestId = 'perm_' . bin2hex(random_bytes(8));
        $requestPayload = [
          'request_id' => $requestId,
          'tool_name' => $toolName,
          'input' => $input,
          'runtime_id' => $runtimeId,
          'uid' => $requestingUid,
        ];

        $permissionRequestEmitter([
          'permission_request' => $requestPayload,
          'status' => 'permission_required',
        ]);

        $decision = $this->awaitPermissionDecision($requestId, 120);
        if ($decision === null) {
          return new PermissionResultDeny('Permission request timed out.', true);
        }

        $decisionType = (string) ($decision['decision'] ?? 'deny');
        if ($decisionType === 'allow_session') {
          if ($runtimeId !== '' && $requestingUid > 0) {
            $this->setSessionPermissionGrant($requestingUid, $runtimeId, $toolName);
          }
          return new PermissionResultAllow();
        }

        if ($decisionType === 'allow_once') {
          return new PermissionResultAllow();
        }

        $message = trim((string) ($decision['message'] ?? 'Denied by user.'));
        $interrupt = (bool) ($decision['interrupt'] ?? false);
        return new PermissionResultDeny($message !== '' ? $message : 'Denied by user.', $interrupt);
      };
    }

    return null;
  }

  public function permissionDecision(Request $request): JsonResponse {
    $payload = Json::decode($request->getContent() ?? '');
    if (!is_array($payload)) {
      return new JsonResponse(['ok' => false, 'error' => 'Invalid JSON body.'], 400);
    }

    $requestId = trim((string) ($payload['request_id'] ?? ''));
    $decision = trim((string) ($payload['decision'] ?? ''));
    if ($requestId === '' || $decision === '') {
      return new JsonResponse(['ok' => false, 'error' => 'request_id and decision are required.'], 400);
    }

    $allowedDecisions = ['allow_once', 'allow_session', 'deny'];
    if (!in_array($decision, $allowedDecisions, true)) {
      return new JsonResponse(['ok' => false, 'error' => 'Unsupported decision value.'], 400);
    }

    $uid = $this->currentUser()->isAuthenticated() ? (int) $this->currentUser()->id() : 0;
    $this->permissionDecisions->set($requestId, [
      'decision' => $decision,
      'message' => trim((string) ($payload['message'] ?? '')),
      'interrupt' => (bool) ($payload['interrupt'] ?? false),
      'uid' => $uid,
      'created' => time(),
    ], 600);

    return new JsonResponse(['ok' => true]);
  }

  private function awaitPermissionDecision(string $requestId, int $timeoutSeconds): ?array {
    $deadline = time() + max(1, $timeoutSeconds);
    while (time() <= $deadline) {
      $decision = $this->permissionDecisions->get($requestId);
      if (is_array($decision)) {
        $this->permissionDecisions->delete($requestId);
        return $decision;
      }
      usleep(200000);
    }

    return null;
  }

  private function hasSessionPermissionGrant(int $uid, string $runtimeId, string $toolName): bool {
    if ($uid <= 0 || $runtimeId === '' || $toolName === '') {
      return false;
    }
    return is_array($this->sessionPermissionGrants->get($this->sessionGrantKey($uid, $runtimeId, $toolName)));
  }

  private function setSessionPermissionGrant(int $uid, string $runtimeId, string $toolName): void {
    if ($uid <= 0 || $runtimeId === '' || $toolName === '') {
      return;
    }
    $this->sessionPermissionGrants->set($this->sessionGrantKey($uid, $runtimeId, $toolName), [
      'uid' => $uid,
      'runtime_id' => $runtimeId,
      'tool_name' => $toolName,
      'created' => time(),
    ], 28800);
  }

  private function sessionGrantKey(int $uid, string $runtimeId, string $toolName): string {
    return hash('sha256', $uid . ':' . $runtimeId . ':' . $toolName);
  }

  private function buildHookConfig(array $debugCallbacks): ?array {
    $raw = (string) ($debugCallbacks['hook_matchers'] ?? '');
    if (trim($raw) === '') {
      return null;
    }
    $decoded = Json::decode($raw);
    if (!is_array($decoded)) {
      return null;
    }

    $hookOutput = $this->parseJsonOrNull((string) ($debugCallbacks['hook_output'] ?? ''));

    $config = [];
    foreach ($decoded as $event => $matchers) {
      if (!is_array($matchers)) {
        continue;
      }
      $config[$event] = [];
      foreach ($matchers as $matcher) {
        if (!is_array($matcher)) {
          continue;
        }
        $hooks = [];
        $hookNames = $matcher['hooks'] ?? [];
        if (is_array($hookNames)) {
          foreach ($hookNames as $name) {
            $hooks[] = function () use ($hookOutput) {
              return $hookOutput ?? ['continue_' => true];
            };
          }
        }
        $config[$event][] = [
          'matcher' => $matcher['matcher'] ?? null,
          'timeout' => $matcher['timeout'] ?? null,
          'hooks' => $hooks,
        ];
      }
    }

    return $config;
  }

  private function buildMcpHandler(array $debugCallbacks): ?callable {
    $raw = (string) ($debugCallbacks['mcp_response'] ?? '');
    if (trim($raw) === '') {
      return null;
    }
    $decoded = $this->parseJsonOrNull($raw);
    if (!is_array($decoded)) {
      return null;
    }

    return function (string $serverName, array $message) use ($decoded) {
      return $decoded;
    };
  }

  private function parseJsonOrNull(string $raw): ?array {
    if (trim($raw) === '') {
      return null;
    }
    $decoded = Json::decode($raw);
    if (!is_array($decoded)) {
      return null;
    }
    return $decoded;
  }

}
