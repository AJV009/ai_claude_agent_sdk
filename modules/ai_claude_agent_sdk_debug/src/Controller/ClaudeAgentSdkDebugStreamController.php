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
use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\ai_claude_agent_sdk_debug\Session\SessionTracker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ClaudeAgentSdkDebugStreamController extends ControllerBase {

  public function __construct(
    private readonly SessionTracker $sessionTracker,
    private readonly SessionFileStore $sessionFileStore,
    private readonly ClaudeAgentSdkProcessLimiter $processLimiter,
    private readonly ClaudeAgentSdkAuthEnvResolver $authEnvResolver,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('ai_claude_agent_sdk_debug.session_tracker'),
      $container->get('ai_claude_agent_sdk_debug.session_file_store'),
      $container->get('ai_claude_agent_sdk.process_limiter'),
      $container->get('ai_claude_agent_sdk.auth_env_resolver'),
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
    $requestedResume = is_string($optionsData['resume'] ?? null) ? trim((string) $optionsData['resume']) : '';
    if ($requestedResume !== '' && count($this->sessionFileStore->listSessionFiles($requestedResume)) === 0) {
      return new StreamedResponse(function () use ($requestedResume) {
        echo "data: " . Json::encode(['error' => 'Resume session ID not found in local Claude session files: ' . $requestedResume]) . "\n\n";
      }, 400);
    }

    $options = $this->buildOptions($optionsData, $payload['debug_callbacks'] ?? []);
    $messages = $payload['messages'] ?? [];
    if (!is_array($messages)) {
      $messages = [];
    }
    $sessionId = is_string($payload['session_id'] ?? null) ? $payload['session_id'] : null;
    if ($sessionId !== null) {
      foreach ($messages as $idx => $message) {
        if (is_array($message) && !isset($message['session_id'])) {
          $messages[$idx]['session_id'] = $sessionId;
        }
      }
    }

    $control = $payload['control'] ?? null;
    $sessionMeta = [
      'mode' => is_string($payload['mode'] ?? null) ? (string) $payload['mode'] : 'client',
      'source' => 'stream',
      'resume' => is_string($optionsData['resume'] ?? null) ? (string) $optionsData['resume'] : null,
      'uid' => $this->currentUser()->isAuthenticated() ? (int) $this->currentUser()->id() : null,
    ];

    $response = new StreamedResponse();
    $response->headers->set('Content-Type', 'text/event-stream');
    $response->headers->set('Cache-Control', 'no-cache');
    $response->headers->set('Connection', 'keep-alive');
    $response->headers->set('X-Accel-Buffering', 'no');

    $response->setCallback(function () use ($options, $messages, $control, $sessionId, $sessionMeta, $requestedResume) {
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

  private function buildOptions(array $optionsData, array $debugCallbacks): ClaudeAgentOptions {
    $cliPath = $optionsData['cliPath'] ?? getenv('CLAUDE_CLI_PATH') ?: null;
    $cwd = $optionsData['cwd'] ?? DRUPAL_ROOT;

    $canUseTool = $this->buildCanUseToolCallback($debugCallbacks);
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
      enableFileCheckpointing: $optionsData['enableFileCheckpointing'] ?? false,
      canUseTool: $canUseTool,
      hooks: $hooks,
      mcpMessageHandler: $mcpHandler,
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
      $mode = $control['mode'] ?? 'auto';
      $client->setPermissionMode((string) $mode);
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

  private function buildCanUseToolCallback(array $debugCallbacks): ?callable {
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

    return null;
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
