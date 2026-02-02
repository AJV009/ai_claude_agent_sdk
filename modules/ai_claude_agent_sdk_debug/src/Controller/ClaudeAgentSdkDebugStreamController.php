<?php

declare(strict_types=1);

namespace Drupal\claude_agent_sdk_debug\Controller;

use Claude\AgentSdk\ClaudeAgentOptions;
use Claude\AgentSdk\Client;
use Claude\AgentSdk\Types\PermissionResultAllow;
use Claude\AgentSdk\Types\PermissionResultDeny;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ClaudeAgentSdkDebugStreamController extends ControllerBase {

  public function stream(Request $request): StreamedResponse {
    $payload = Json::decode($request->getContent() ?? '');
    if (!is_array($payload)) {
      return new StreamedResponse(function () {
        echo "data: " . Json::encode(['error' => 'Invalid JSON body.']) . "\n\n";
      }, 400);
    }

    $options = $this->buildOptions($payload['options'] ?? [], $payload['debug_callbacks'] ?? []);
    $messages = $payload['messages'] ?? [];
    if (!is_array($messages)) {
      $messages = [];
    }

    $control = $payload['control'] ?? null;

    $response = new StreamedResponse();
    $response->headers->set('Content-Type', 'text/event-stream');
    $response->headers->set('Cache-Control', 'no-cache');
    $response->headers->set('Connection', 'keep-alive');

    $response->setCallback(function () use ($options, $messages, $control) {
      $client = new Client($options);

      $stream = (function () use ($messages): iterable {
        foreach ($messages as $message) {
          if (is_array($message)) {
            yield $message;
          }
        }
      })();

      $client->connect($stream);

      if (is_array($control)) {
        $this->applyControl($client, $control);
      }

      foreach ($client->receiveMessages() as $message) {
        $raw = $message->getRaw();
        echo 'data: ' . Json::encode(['message' => $raw]) . "\n\n";
        flush();
      }

      $client->close();
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
      env: $optionsData['env'] ?? [],
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
