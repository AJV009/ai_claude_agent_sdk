<?php

declare(strict_types=1);

namespace Drupal\claude_agent_sdk_debug\Form;

use Claude\AgentSdk\ClaudeAgentOptions;
use Claude\AgentSdk\Client;
use Claude\AgentSdk\Query;
use Claude\AgentSdk\Types\PermissionResultAllow;
use Claude\AgentSdk\Types\PermissionResultDeny;
use Drupal\ai_claude_agent_sdk\Service\ClaudeAgentSdkProcessLimiter;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\claude_agent_sdk_debug\Session\SessionTracker;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class ClaudeAgentSdkDebugForm extends FormBase {

  public function __construct(
    private readonly SessionTracker $sessionTracker,
    private readonly ClaudeAgentSdkProcessLimiter $processLimiter,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('claude_agent_sdk_debug.session_tracker'),
      $container->get('ai_claude_agent_sdk.process_limiter'),
    );
  }

  public function getFormId(): string {
    return 'claude_agent_sdk_debug_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $buildInfo = $form_state->getBuildInfo();
    $mode = (string) ($buildInfo['args'][0] ?? 'client');

    $form['#attached']['library'][] = 'claude_agent_sdk_debug/debug';
    $form['#attached']['drupalSettings']['claudeAgentSdkDebug'] = [
      'streamUrl' => Url::fromRoute('claude_agent_sdk_debug.stream')->toString(),
      'mode' => $mode,
    ];

    $form['stream_endpoint'] = [
      '#type' => 'item',
      '#title' => $this->t('Streaming endpoint'),
      '#markup' => $this->t('POST JSON to <code>@url</code> (GET will return an error).', [
        '@url' => Url::fromRoute('claude_agent_sdk_debug.stream')->toString(),
      ]),
    ];

    if ($mode === 'query') {
      $form['input_type'] = [
        '#type' => 'hidden',
        '#value' => 'string',
      ];
      $form['mode_notice'] = [
        '#type' => 'item',
        '#title' => $this->t('Mode'),
        '#markup' => $this->t('Single exchange (query). This page creates a new session for each request.'),
      ];
    }
    elseif ($mode === 'terminal') {
      $form['input_type'] = [
        '#type' => 'hidden',
        '#value' => 'string',
      ];
      $form['mode_notice'] = [
        '#type' => 'item',
        '#title' => $this->t('Mode'),
        '#markup' => $this->t('Terminal (Client). Uses the streaming client with a persistent session.'),
      ];
    }
    else {
      $form['mode_notice'] = [
        '#type' => 'item',
        '#title' => $this->t('Mode'),
        '#markup' => $this->t('Multiple exchanges (Client). This page reuses the same session when streaming.'),
      ];
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
    }

    $form['prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Prompt / Messages'),
      '#rows' => 10,
      '#description' => $this->t('For JSONL mode, provide one JSON object per line in CLI stream format. For DeepChat, provide full request JSON.'),
      '#default_value' => $form_state->getValue('prompt') ?? '',
      '#required' => TRUE,
    ];

    $form += $this->buildOptionsForm($form_state);

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
      '#access' => $mode !== 'query',
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
      '#access' => $mode !== 'query',
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
    $buildInfo = $form_state->getBuildInfo();
    $mode = (string) ($buildInfo['args'][0] ?? 'client');
    $inputType = (string) $form_state->getValue('input_type');
    $promptRaw = (string) $form_state->getValue('prompt');
    $controlAction = (string) $form_state->getValue('control_action');
    $controlMode = (string) $form_state->getValue('control_mode');
    $controlModel = (string) $form_state->getValue('control_model');
    $controlUserMessageId = (string) $form_state->getValue('control_user_message_id');

    $optionsData = $this->collectOptions($form_state);
    $options = $this->buildOptions($optionsData, [
      'can_use_tool' => $form_state->getValue('can_use_tool'),
      'can_use_tool_message' => $form_state->getValue('can_use_tool_message'),
      'can_use_tool_interrupt' => $form_state->getValue('can_use_tool_interrupt'),
      'hook_matchers' => $form_state->getValue('hook_matchers'),
      'hook_output' => $form_state->getValue('hook_output'),
      'mcp_response' => $form_state->getValue('mcp_response'),
    ]);

    $sessionMeta = $this->buildSessionMeta($mode, $optionsData, 'form');

    if (!$this->processLimiter->canStart()) {
      $status = $this->processLimiter->getStatus();
      $this->messenger()->addError($this->t('Claude CLI limit reached (@running running, limit @limit). Try again after other sessions finish.', [
        '@running' => $status['running'],
        '@limit' => $status['limit'],
      ]));
      return;
    }

    try {
      if ($mode === 'query') {
        $output = $this->runQuery($promptRaw, $options, $sessionMeta);
      }
      else {
        if ($inputType === 'jsonl') {
          $messages = $this->parseJsonl($promptRaw);
          $output = $this->runStreaming($messages, $options, $controlAction, $controlMode, $controlModel, $controlUserMessageId, $sessionMeta);
        }
        elseif ($inputType === 'deepchat') {
          $messages = $this->convertDeepChatToMessages($promptRaw);
          $output = $this->runStreaming($messages, $options, $controlAction, $controlMode, $controlModel, $controlUserMessageId, $sessionMeta);
        }
        else {
          $messages = [[
            'type' => 'user',
            'message' => [
              'role' => 'user',
              'content' => $promptRaw,
            ],
            'parent_tool_use_id' => null,
            'session_id' => 'default',
          ]];
          $output = $this->runStreaming($messages, $options, $controlAction, $controlMode, $controlModel, $controlUserMessageId, $sessionMeta);
        }
      }

      $form_state->set('claude_agent_sdk_debug_output', $output);
      $form_state->setRebuild(TRUE);
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('SDK error: @msg', ['@msg' => $e->getMessage()]));
    }
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
      maxBufferSize: $optionsData['maxBufferSize'] ?? null,
      enableFileCheckpointing: $optionsData['enableFileCheckpointing'] ?? false,
      canUseTool: $canUseTool,
      hooks: $hooks,
      mcpMessageHandler: $mcpHandler,
      initializeTimeout: $optionsData['initializeTimeout'] ?? null,
      user: $optionsData['user'] ?? null,
      env: $optionsData['env'] ?? [],
      extraArgs: $optionsData['extraArgs'] ?? [],
    );
  }

  private function runStreaming(array $messages, ClaudeAgentOptions $options, string $controlAction, string $controlMode, string $controlModel, string $controlUserMessageId, array $sessionMeta): string {
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
      $this->recordSessionFromMessage($raw, $sessionMeta);
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

  private function runQuery(string $prompt, ClaudeAgentOptions $options, array $sessionMeta): string {
    $output = '';
    foreach (Query::query($prompt, $options) as $message) {
      $raw = $message->getRaw();
      $this->recordSessionFromMessage($raw, $sessionMeta);
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

  private function buildSessionMeta(string $mode, array $optionsData, string $source): array {
    return [
      'mode' => $mode,
      'source' => $source,
      'resume' => is_string($optionsData['resume'] ?? null) ? (string) $optionsData['resume'] : null,
    ];
  }

  private function recordSessionFromMessage(array $raw, array $sessionMeta): void {
    $sessionId = $raw['session_id'] ?? null;
    if (!is_string($sessionId) || $sessionId === '' || $sessionId === 'default') {
      return;
    }
    $this->sessionTracker->record($sessionId, $sessionMeta);
  }

  private function buildOptionsForm(FormStateInterface $form_state): array {
    $sdkConfig = $this->config('ai_claude_agent_sdk.settings');

    $form = [];
    $form['options_basic'] = [
      '#type' => 'details',
      '#title' => $this->t('Basic Options'),
      '#open' => TRUE,
    ];

    $form['options_basic']['option_cli_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Claude CLI path'),
      '#description' => $this->t('Leave empty to use the system default or CLAUDE_CLI_PATH env var.'),
      '#default_value' => $form_state->getValue('option_cli_path') ?? ($sdkConfig->get('cli_path') ?? ''),
    ];

    $form['options_basic']['option_cwd'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Working directory'),
      '#description' => $this->t('Defaults to the Drupal root.'),
      '#default_value' => $form_state->getValue('option_cwd') ?? ($sdkConfig->get('working_directory') ?? DRUPAL_ROOT),
    ];

    $form['options_basic']['option_system_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('System prompt'),
      '#rows' => 3,
      '#description' => $this->t('Optional system prompt override.'),
      '#default_value' => $form_state->getValue('option_system_prompt') ?? '',
    ];

    $model_default = $sdkConfig->get('default_model') ?? '';
    $form['options_basic']['option_model_choice'] = [
      '#type' => 'select',
      '#title' => $this->t('Model selection'),
      '#options' => [
        'default' => $this->t('Use default model (settings)'),
        'preset:claude-3-7-sonnet' => $this->t('Claude 3.7 Sonnet'),
        'preset:claude-3-5-sonnet' => $this->t('Claude 3.5 Sonnet'),
        'preset:claude-3-5-haiku' => $this->t('Claude 3.5 Haiku'),
        'preset:claude-3-opus' => $this->t('Claude 3 Opus'),
        'custom' => $this->t('Custom model name'),
      ],
      '#default_value' => $form_state->getValue('option_model_choice') ?? 'default',
    ];

    $form['options_basic']['option_model_custom'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Custom model'),
      '#description' => $this->t('Only used when model selection is set to Custom.'),
      '#default_value' => $form_state->getValue('option_model_custom') ?? $model_default,
      '#states' => [
        'visible' => [
          ':input[name="option_model_choice"]' => ['value' => 'custom'],
        ],
      ],
    ];

    $form['options_basic']['option_permission_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Permission mode'),
      '#options' => [
        '' => $this->t('Use CLI default'),
        'auto' => $this->t('Auto'),
        'ask' => $this->t('Ask'),
        'deny' => $this->t('Deny'),
      ],
      '#default_value' => $form_state->getValue('option_permission_mode') ?? '',
    ];

    $form['options_basic']['option_max_turns'] = [
      '#type' => 'number',
      '#title' => $this->t('Max turns'),
      '#description' => $this->t('Maximum turns allowed for the session.'),
      '#default_value' => $form_state->getValue('option_max_turns') ?? '',
      '#min' => 1,
    ];

    $form['options_basic']['option_max_budget_usd'] = [
      '#type' => 'number',
      '#title' => $this->t('Max budget (USD)'),
      '#description' => $this->t('Stops when the spend exceeds this amount.'),
      '#default_value' => $form_state->getValue('option_max_budget_usd') ?? '',
      '#step' => 0.01,
      '#min' => 0,
    ];

    $form['options_basic']['option_continue_conversation'] = [
      '#type' => 'select',
      '#title' => $this->t('Continue conversation'),
      '#options' => [
        '0' => $this->t('No'),
        '1' => $this->t('Yes'),
      ],
      '#default_value' => $form_state->getValue('option_continue_conversation') ?? '0',
    ];

    $form['options_basic']['option_resume'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Resume session ID'),
      '#description' => $this->t('Provide a session ID to resume.'),
      '#default_value' => $form_state->getValue('option_resume') ?? '',
    ];

    $form['options_basic']['option_include_partial_messages'] = [
      '#type' => 'select',
      '#title' => $this->t('Include partial messages'),
      '#options' => [
        '0' => $this->t('No'),
        '1' => $this->t('Yes'),
      ],
      '#default_value' => $form_state->getValue('option_include_partial_messages') ?? '0',
    ];

    $form['options_basic']['option_fork_session'] = [
      '#type' => 'select',
      '#title' => $this->t('Fork session'),
      '#options' => [
        '0' => $this->t('No'),
        '1' => $this->t('Yes'),
      ],
      '#default_value' => $form_state->getValue('option_fork_session') ?? '0',
    ];

    $form['options_basic']['option_enable_file_checkpointing'] = [
      '#type' => 'select',
      '#title' => $this->t('Enable file checkpointing'),
      '#options' => [
        '0' => $this->t('No'),
        '1' => $this->t('Yes'),
      ],
      '#default_value' => $form_state->getValue('option_enable_file_checkpointing') ?? '0',
    ];

    $form['options_advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Options'),
      '#open' => FALSE,
    ];

    $form['options_advanced']['option_tools'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Tools (one per line)'),
      '#description' => $this->t('List tool names to allow. Leave empty for defaults.'),
      '#rows' => 3,
      '#default_value' => $form_state->getValue('option_tools') ?? '',
    ];

    $form['options_advanced']['option_allowed_tools'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed tools (one per line)'),
      '#rows' => 3,
      '#default_value' => $form_state->getValue('option_allowed_tools') ?? '',
    ];

    $form['options_advanced']['option_disallowed_tools'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Disallowed tools (one per line)'),
      '#rows' => 3,
      '#default_value' => $form_state->getValue('option_disallowed_tools') ?? '',
    ];

    $form['options_advanced']['option_betas'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Betas (one per line)'),
      '#rows' => 2,
      '#default_value' => $form_state->getValue('option_betas') ?? '',
    ];

    $form['options_advanced']['option_fallback_model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Fallback model'),
      '#default_value' => $form_state->getValue('option_fallback_model') ?? '',
    ];

    $form['options_advanced']['option_permission_prompt_tool_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Permission prompt tool name'),
      '#default_value' => $form_state->getValue('option_permission_prompt_tool_name') ?? '',
    ];

    $form['options_advanced']['option_settings'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Settings file'),
      '#description' => $this->t('Path to a settings file to pass to Claude Code.'),
      '#default_value' => $form_state->getValue('option_settings') ?? '',
    ];

    $form['options_advanced']['option_sandbox'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Sandbox (JSON)'),
      '#description' => $this->t('JSON object describing sandbox configuration.'),
      '#rows' => 3,
      '#default_value' => $form_state->getValue('option_sandbox') ?? '',
    ];

    $form['options_advanced']['option_add_dirs'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Additional directories (one per line)'),
      '#rows' => 2,
      '#default_value' => $form_state->getValue('option_add_dirs') ?? '',
    ];

    $form['options_advanced']['option_mcp_servers'] = [
      '#type' => 'textarea',
      '#title' => $this->t('MCP servers (JSON)'),
      '#description' => $this->t('JSON object defining MCP servers.'),
      '#rows' => 3,
      '#default_value' => $form_state->getValue('option_mcp_servers') ?? '',
    ];

    $form['options_advanced']['option_agents'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Agents (JSON)'),
      '#description' => $this->t('JSON list of agents to pass to Claude Code.'),
      '#rows' => 2,
      '#default_value' => $form_state->getValue('option_agents') ?? '',
    ];

    $form['options_advanced']['option_setting_sources'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Setting sources (JSON)'),
      '#rows' => 2,
      '#default_value' => $form_state->getValue('option_setting_sources') ?? '',
    ];

    $form['options_advanced']['option_plugins'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Plugins (JSON)'),
      '#rows' => 2,
      '#default_value' => $form_state->getValue('option_plugins') ?? '',
    ];

    $form['options_advanced']['option_max_thinking_tokens'] = [
      '#type' => 'number',
      '#title' => $this->t('Max thinking tokens'),
      '#default_value' => $form_state->getValue('option_max_thinking_tokens') ?? '',
      '#min' => 0,
    ];

    $form['options_advanced']['option_output_format'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Output format (JSON)'),
      '#rows' => 2,
      '#default_value' => $form_state->getValue('option_output_format') ?? '',
    ];

    $form['options_advanced']['option_max_buffer_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Max buffer size (bytes)'),
      '#default_value' => $form_state->getValue('option_max_buffer_size') ?? '',
      '#min' => 0,
    ];

    $form['options_advanced']['option_initialize_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Initialize timeout (seconds)'),
      '#default_value' => $form_state->getValue('option_initialize_timeout') ?? '',
      '#min' => 0,
      '#step' => 0.1,
    ];

    $form['options_advanced']['option_user'] = [
      '#type' => 'textfield',
      '#title' => $this->t('User'),
      '#default_value' => $form_state->getValue('option_user') ?? '',
    ];

    $form['options_advanced']['option_env'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Environment variables (JSON)'),
      '#rows' => 3,
      '#default_value' => $form_state->getValue('option_env') ?? '',
    ];

    $form['options_advanced']['option_extra_args'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Extra args (one per line)'),
      '#rows' => 2,
      '#default_value' => $form_state->getValue('option_extra_args') ?? '',
    ];

    return $form;
  }

  private function collectOptions(FormStateInterface $form_state): array {
    $options = [];

    $cliPath = trim((string) $form_state->getValue('option_cli_path'));
    if ($cliPath !== '') {
      $options['cliPath'] = $cliPath;
    }

    $cwd = trim((string) $form_state->getValue('option_cwd'));
    if ($cwd !== '') {
      $options['cwd'] = $cwd;
    }

    $systemPrompt = trim((string) $form_state->getValue('option_system_prompt'));
    if ($systemPrompt !== '') {
      $options['systemPrompt'] = $systemPrompt;
    }

    $modelChoice = (string) $form_state->getValue('option_model_choice');
    if (str_starts_with($modelChoice, 'preset:')) {
      $options['model'] = substr($modelChoice, 7);
    }
    elseif ($modelChoice === 'custom') {
      $model = trim((string) $form_state->getValue('option_model_custom'));
      if ($model !== '') {
        $options['model'] = $model;
      }
    }

    $permissionMode = (string) $form_state->getValue('option_permission_mode');
    if ($permissionMode !== '') {
      $options['permissionMode'] = $permissionMode;
    }

    $maxTurns = $this->parseNumber($form_state->getValue('option_max_turns'));
    if ($maxTurns !== null) {
      $options['maxTurns'] = $maxTurns;
    }

    $maxBudgetUsd = $this->parseFloat($form_state->getValue('option_max_budget_usd'));
    if ($maxBudgetUsd !== null) {
      $options['maxBudgetUsd'] = $maxBudgetUsd;
    }

    $options['continueConversation'] = $this->parseBoolSelect($form_state->getValue('option_continue_conversation'));

    $resume = trim((string) $form_state->getValue('option_resume'));
    if ($resume !== '') {
      $options['resume'] = $resume;
    }

    $options['includePartialMessages'] = $this->parseBoolSelect($form_state->getValue('option_include_partial_messages'));
    $options['forkSession'] = $this->parseBoolSelect($form_state->getValue('option_fork_session'));
    $options['enableFileCheckpointing'] = $this->parseBoolSelect($form_state->getValue('option_enable_file_checkpointing'));

    $tools = $this->parseLines($form_state->getValue('option_tools'));
    if (!empty($tools)) {
      $options['tools'] = $tools;
    }

    $allowedTools = $this->parseLines($form_state->getValue('option_allowed_tools'));
    if (!empty($allowedTools)) {
      $options['allowedTools'] = $allowedTools;
    }

    $disallowedTools = $this->parseLines($form_state->getValue('option_disallowed_tools'));
    if (!empty($disallowedTools)) {
      $options['disallowedTools'] = $disallowedTools;
    }

    $betas = $this->parseLines($form_state->getValue('option_betas'));
    if (!empty($betas)) {
      $options['betas'] = $betas;
    }

    $fallbackModel = trim((string) $form_state->getValue('option_fallback_model'));
    if ($fallbackModel !== '') {
      $options['fallbackModel'] = $fallbackModel;
    }

    $permissionPromptToolName = trim((string) $form_state->getValue('option_permission_prompt_tool_name'));
    if ($permissionPromptToolName !== '') {
      $options['permissionPromptToolName'] = $permissionPromptToolName;
    }

    $settings = trim((string) $form_state->getValue('option_settings'));
    if ($settings !== '') {
      $options['settings'] = $settings;
    }

    $sandbox = $this->parseJson($form_state->getValue('option_sandbox'));
    if (is_array($sandbox)) {
      $options['sandbox'] = $sandbox;
    }

    $addDirs = $this->parseLines($form_state->getValue('option_add_dirs'));
    if (!empty($addDirs)) {
      $options['addDirs'] = $addDirs;
    }

    $mcpServers = $this->parseJson($form_state->getValue('option_mcp_servers'));
    if (is_array($mcpServers)) {
      $options['mcpServers'] = $mcpServers;
    }

    $agents = $this->parseJson($form_state->getValue('option_agents'));
    if (is_array($agents)) {
      $options['agents'] = $agents;
    }

    $settingSources = $this->parseJson($form_state->getValue('option_setting_sources'));
    if (is_array($settingSources)) {
      $options['settingSources'] = $settingSources;
    }

    $plugins = $this->parseJson($form_state->getValue('option_plugins'));
    if (is_array($plugins)) {
      $options['plugins'] = $plugins;
    }

    $maxThinkingTokens = $this->parseNumber($form_state->getValue('option_max_thinking_tokens'));
    if ($maxThinkingTokens !== null) {
      $options['maxThinkingTokens'] = $maxThinkingTokens;
    }

    $outputFormat = $this->parseJson($form_state->getValue('option_output_format'));
    if (is_array($outputFormat)) {
      $options['outputFormat'] = $outputFormat;
    }

    $maxBufferSize = $this->parseNumber($form_state->getValue('option_max_buffer_size'));
    if ($maxBufferSize !== null) {
      $options['maxBufferSize'] = $maxBufferSize;
    }

    $initializeTimeout = $this->parseFloat($form_state->getValue('option_initialize_timeout'));
    if ($initializeTimeout !== null) {
      $options['initializeTimeout'] = $initializeTimeout;
    }

    $user = trim((string) $form_state->getValue('option_user'));
    if ($user !== '') {
      $options['user'] = $user;
    }

    $env = $this->parseJson($form_state->getValue('option_env'));
    if (is_array($env)) {
      $options['env'] = $env;
    }

    $extraArgs = $this->parseLines($form_state->getValue('option_extra_args'));
    if (!empty($extraArgs)) {
      $options['extraArgs'] = $extraArgs;
    }

    return $options;
  }

  private function parseLines($value): array {
    $lines = preg_split('/\r\n|\r|\n/', (string) $value);
    $out = [];
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line !== '') {
        $out[] = $line;
      }
    }
    return $out;
  }

  private function parseJson($value): ?array {
    $raw = trim((string) $value);
    if ($raw === '') {
      return null;
    }
    $decoded = Json::decode($raw);
    return is_array($decoded) ? $decoded : null;
  }

  private function parseNumber($value): ?int {
    if ($value === '' || $value === null) {
      return null;
    }
    return (int) $value;
  }

  private function parseFloat($value): ?float {
    if ($value === '' || $value === null) {
      return null;
    }
    return (float) $value;
  }

  private function parseBoolSelect($value): bool {
    return (string) $value === '1';
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
