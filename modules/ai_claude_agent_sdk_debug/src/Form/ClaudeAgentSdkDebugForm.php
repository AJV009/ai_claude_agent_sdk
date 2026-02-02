<?php

declare(strict_types=1);

namespace Drupal\claude_agent_sdk_debug\Form;

use Claude\AgentSdk\ClaudeAgentOptions;
use Claude\AgentSdk\Client;
use Claude\AgentSdk\Query;
use Claude\AgentSdk\Types\PermissionResultAllow;
use Claude\AgentSdk\Types\PermissionResultDeny;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

final class ClaudeAgentSdkDebugForm extends FormBase {

  public function getFormId(): string {
    return 'claude_agent_sdk_debug_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['input_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Input Type'),
      '#options' => [
        'string' => $this->t('String prompt'),
        'jsonl' => $this->t('JSONL messages (streaming)'),
        'deepchat' => $this->t('DeepChat request JSON'),
      ],
      '#default_value' => $form_state->getValue('input_type') ?? 'string',
    ];

    $form['prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Prompt / Messages'),
      '#rows' => 10,
      '#description' => $this->t('For JSONL mode, provide one JSON object per line in CLI stream format. For DeepChat, provide full request JSON.'),
      '#default_value' => $form_state->getValue('prompt') ?? '',
      '#required' => TRUE,
    ];

    $form['options'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Options (JSON)'),
      '#rows' => 8,
      '#description' => $this->t('Optional ClaudeAgentOptions overrides. Example: {"permissionMode":"auto","model":"claude-3-7-sonnet"}'),
      '#default_value' => $form_state->getValue('options') ?? '',
    ];

    $form['control_action'] = [
      '#type' => 'select',
      '#title' => $this->t('Control Action'),
      '#options' => [
        'none' => $this->t('None'),
        'interrupt' => $this->t('Interrupt'),
        'mcp_status' => $this->t('MCP Status'),
        'set_permission_mode' => $this->t('Set Permission Mode'),
        'set_model' => $this->t('Set Model'),
        'rewind_files' => $this->t('Rewind Files'),
      ],
      '#default_value' => $form_state->getValue('control_action') ?? 'none',
    ];

    $form['control_mode'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Permission Mode'),
      '#default_value' => $form_state->getValue('control_mode') ?? 'auto',
      '#states' => [
        'visible' => [
          ':input[name="control_action"]' => ['value' => 'set_permission_mode'],
        ],
      ],
    ];

    $form['control_model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Model'),
      '#default_value' => $form_state->getValue('control_model') ?? '',
      '#states' => [
        'visible' => [
          ':input[name="control_action"]' => ['value' => 'set_model'],
        ],
      ],
    ];

    $form['control_user_message_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('User Message ID (for rewind)'),
      '#default_value' => $form_state->getValue('control_user_message_id') ?? '',
      '#states' => [
        'visible' => [
          ':input[name="control_action"]' => ['value' => 'rewind_files'],
        ],
      ],
    ];

    $form['callbacks'] = [
      '#type' => 'details',
      '#title' => $this->t('Callbacks (Debug)'),
      '#open' => FALSE,
    ];

    $form['callbacks']['can_use_tool'] = [
      '#type' => 'select',
      '#title' => $this->t('canUseTool behavior'),
      '#options' => [
        'none' => $this->t('None'),
        'allow' => $this->t('Allow'),
        'deny' => $this->t('Deny'),
      ],
      '#default_value' => $form_state->getValue('can_use_tool') ?? 'none',
    ];

    $form['callbacks']['can_use_tool_message'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Deny message'),
      '#default_value' => $form_state->getValue('can_use_tool_message') ?? 'Denied by debug UI',
      '#states' => [
        'visible' => [
          ':input[name="can_use_tool"]' => ['value' => 'deny'],
        ],
      ],
    ];

    $form['callbacks']['can_use_tool_interrupt'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Interrupt on deny'),
      '#default_value' => (bool) ($form_state->getValue('can_use_tool_interrupt') ?? FALSE),
      '#states' => [
        'visible' => [
          ':input[name="can_use_tool"]' => ['value' => 'deny'],
        ],
      ],
    ];

    $form['callbacks']['hook_matchers'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Hooks config (JSON)'),
      '#rows' => 6,
      '#description' => $this->t('Map hook events to matchers. Each matcher can include "matcher", "timeout", and "hooks" (array of names). Example: {"tool_use":[{"matcher":{"tool_name":"bash"},"hooks":["default"]}]}'),
      '#default_value' => $form_state->getValue('hook_matchers') ?? '',
    ];

    $form['callbacks']['hook_output'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Hook callback output (JSON)'),
      '#rows' => 4,
      '#description' => $this->t('Returned for any hook callback. Example: {"continue_":true}'),
      '#default_value' => $form_state->getValue('hook_output') ?? '',
    ];

    $form['callbacks']['mcp_response'] = [
      '#type' => 'textarea',
      '#title' => $this->t('MCP response (JSON)'),
      '#rows' => 4,
      '#description' => $this->t('If set, responses to mcp_message will be this JSON object.'),
      '#default_value' => $form_state->getValue('mcp_response') ?? '',
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send to SDK'),
    ];

    $output = $form_state->get('claude_agent_sdk_debug_output');
    if (is_string($output) && $output !== '') {
      $form['output'] = [
        '#type' => 'details',
        '#title' => $this->t('Last Response'),
        '#open' => TRUE,
        'content' => [
          '#type' => 'textarea',
          '#value' => $output,
          '#rows' => 12,
          '#attributes' => ['readonly' => 'readonly'],
        ],
      ];
    }

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $inputType = (string) $form_state->getValue('input_type');
    $promptRaw = (string) $form_state->getValue('prompt');
    $optionsRaw = (string) $form_state->getValue('options');
    $controlAction = (string) $form_state->getValue('control_action');
    $controlMode = (string) $form_state->getValue('control_mode');
    $controlModel = (string) $form_state->getValue('control_model');
    $controlUserMessageId = (string) $form_state->getValue('control_user_message_id');

    $options = $this->buildOptions($optionsRaw, [
      'can_use_tool' => $form_state->getValue('can_use_tool'),
      'can_use_tool_message' => $form_state->getValue('can_use_tool_message'),
      'can_use_tool_interrupt' => $form_state->getValue('can_use_tool_interrupt'),
      'hook_matchers' => $form_state->getValue('hook_matchers'),
      'hook_output' => $form_state->getValue('hook_output'),
      'mcp_response' => $form_state->getValue('mcp_response'),
    ]);
    if ($options === null) {
      $this->messenger()->addError($this->t('Options JSON is invalid.'));
      return;
    }

    try {
      if ($inputType === 'jsonl') {
        $messages = $this->parseJsonl($promptRaw);
        $output = $this->runStreaming($messages, $options, $controlAction, $controlMode, $controlModel, $controlUserMessageId);
      }
      elseif ($inputType === 'deepchat') {
        $messages = $this->convertDeepChatToMessages($promptRaw);
        $output = $this->runStreaming($messages, $options, $controlAction, $controlMode, $controlModel, $controlUserMessageId);
      }
      else {
        $output = $this->runQuery($promptRaw, $options);
      }

      $form_state->set('claude_agent_sdk_debug_output', $output);
      $form_state->setRebuild(TRUE);
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('SDK error: @msg', ['@msg' => $e->getMessage()]));
    }
  }

  private function buildOptions(string $optionsRaw, array $debugCallbacks): ?ClaudeAgentOptions {
    $optionsData = [];
    if (trim($optionsRaw) !== '') {
      $decoded = Json::decode($optionsRaw);
      if (!is_array($decoded)) {
        return null;
      }
      $optionsData = $decoded;
    }

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

  private function runQuery(string $prompt, ClaudeAgentOptions $options): string {
    $output = '';
    foreach (Query::query($prompt, $options) as $message) {
      $raw = $message->getRaw();
      if (($raw['type'] ?? '') === 'assistant') {
        $content = $raw['message']['content'] ?? [];
        if (is_array($content)) {
          foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text') {
              $output .= (string) ($block['text'] ?? '');
            }
          }
        }
      }
    }
    return $output;
  }

  private function runStreaming(array $messages, ClaudeAgentOptions $options, string $controlAction, string $controlMode, string $controlModel, string $controlUserMessageId): string {
    $stream = (function () use ($messages): iterable {
      foreach ($messages as $message) {
        yield $message;
      }
    })();

    $client = new Client($options);
    $client->connect($stream);

    $this->applyControl($client, $controlAction, $controlMode, $controlModel, $controlUserMessageId);

    $output = '';
    foreach ($client->receiveMessages() as $message) {
      $raw = $message->getRaw();
      if (($raw['type'] ?? '') === 'assistant') {
        $content = $raw['message']['content'] ?? [];
        if (is_array($content)) {
          foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text') {
              $output .= (string) ($block['text'] ?? '');
            }
          }
        }
      }
    }

    $client->close();

    return $output;
  }

  private function parseJsonl(string $raw): array {
    $messages = [];
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      $decoded = Json::decode($line);
      if (!is_array($decoded)) {
        throw new \RuntimeException('Invalid JSONL line: ' . $line);
      }
      $messages[] = $decoded;
    }
    return $messages;
  }

  private function convertDeepChatToMessages(string $raw): array {
    $decoded = Json::decode($raw);
    if (!is_array($decoded) || !isset($decoded['messages']) || !is_array($decoded['messages'])) {
      return [];
    }

    $messages = [];
    foreach ($decoded['messages'] as $message) {
      if (!is_array($message)) {
        continue;
      }
      $role = $message['role'] ?? 'user';
      $text = $message['text'] ?? '';
      $messages[] = [
        'type' => $role,
        'message' => [
          'role' => $role,
          'content' => $text,
        ],
        'parent_tool_use_id' => null,
        'session_id' => 'default',
      ];
    }

    return $messages;
  }

  private function applyControl(Client $client, string $action, string $mode, string $model, string $userMessageId): void {
    if ($action === 'interrupt') {
      $client->interrupt();
    }
    elseif ($action === 'mcp_status') {
      $client->getMcpStatus();
    }
    elseif ($action === 'set_permission_mode') {
      $client->setPermissionMode($mode !== '' ? $mode : 'auto');
    }
    elseif ($action === 'set_model') {
      $client->setModel($model !== '' ? $model : null);
    }
    elseif ($action === 'rewind_files') {
      if ($userMessageId !== '') {
        $client->rewindFiles($userMessageId);
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
