<?php

declare(strict_types=1);

namespace Drupal\ai_claude_agent_sdk_debug\Form;

use Claude\AgentSdk\ClaudeAgentOptions;
use Claude\AgentSdk\Client;
use Claude\AgentSdk\Query;
use Claude\AgentSdk\Types\PermissionResultAllow;
use Claude\AgentSdk\Types\PermissionResultDeny;
use Drupal\ai_claude_agent_sdk\Service\ClaudeAgentSdkAuthEnvResolver;
use Drupal\ai_claude_agent_sdk\Service\ClaudeAgentSdkProcessLimiter;
use Drupal\ai_claude_agent_sdk_debug\Support\PermissionPresetHelper;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\ai_claude_agent_sdk_debug\Session\SessionFileStore;
use Drupal\ai_claude_agent_sdk_debug\Session\SessionTracker;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class ClaudeAgentSdkDebugForm extends FormBase {

  protected SessionTracker $sessionTracker;

  protected ClaudeAgentSdkProcessLimiter $processLimiter;

  protected ClaudeAgentSdkAuthEnvResolver $authEnvResolver;

  protected SessionFileStore $sessionFileStore;

  private ?string $lastSessionId = null;

  public function __construct(SessionTracker $sessionTracker, ClaudeAgentSdkProcessLimiter $processLimiter, ClaudeAgentSdkAuthEnvResolver $authEnvResolver, SessionFileStore $sessionFileStore) {
    $this->sessionTracker = $sessionTracker;
    $this->processLimiter = $processLimiter;
    $this->authEnvResolver = $authEnvResolver;
    $this->sessionFileStore = $sessionFileStore;
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('ai_claude_agent_sdk_debug.session_tracker'),
      $container->get('ai_claude_agent_sdk.process_limiter'),
      $container->get('ai_claude_agent_sdk.auth_env_resolver'),
      $container->get('ai_claude_agent_sdk_debug.session_file_store'),
    );
  }

  public function getFormId(): string {
    return 'ai_claude_agent_sdk_debug_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $buildInfo = $form_state->getBuildInfo();
    $mode = (string) ($buildInfo['args'][0] ?? 'client');

    $form['#attached']['library'][] = 'ai_claude_agent_sdk_debug/debug';
    $form['#attached']['drupalSettings']['claudeAgentSdkDebug'] = [
      'streamUrl' => Url::fromRoute('ai_claude_agent_sdk_debug.stream')->toString(),
      'permissionDecisionUrl' => Url::fromRoute('ai_claude_agent_sdk_debug.permission_decision')->toString(),
      'mode' => $mode,
    ];

    $form['stream_endpoint'] = [
      '#type' => 'item',
      '#title' => $this->t('Streaming endpoint'),
      '#markup' => $this->t('POST JSON to <code>@url</code> (GET will return an error).', [
        '@url' => Url::fromRoute('ai_claude_agent_sdk_debug.stream')->toString(),
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
        '#markup' => $this->t('Single exchange (query). This page creates a new session for each request. For bridge tool-calling tests, use <a href=":terminal_url">Terminal (Client)</a>.', [
          ':terminal_url' => Url::fromRoute('ai_claude_agent_sdk_debug.terminal')->toString(),
        ]),
      ];
    }
    elseif ($mode === 'session_query') {
      $form['input_type'] = [
        '#type' => 'hidden',
        '#value' => 'string',
      ];
      $form['mode_notice'] = [
        '#type' => 'item',
        '#title' => $this->t('Mode'),
        '#markup' => $this->t('Session exchange (query). Uses query mode with explicit session resume support and no streaming client. For bridge tool-calling tests, use <a href=":terminal_url">Terminal (Client)</a>.', [
          ':terminal_url' => Url::fromRoute('ai_claude_agent_sdk_debug.terminal')->toString(),
        ]),
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
      '#required' => FALSE,
    ];

    if ($mode === 'session_query') {
      $sessionContinueDefault = $form_state->getValue('session_continue');
      if ($sessionContinueDefault === NULL) {
        $sessionContinueDefault = $form_state->get('ai_claude_agent_sdk_debug_session_continue');
      }
      if ($sessionContinueDefault === NULL) {
        $sessionContinueDefault = TRUE;
      }
      $form['session_continue'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Continue conversation'),
        '#description' => $this->t('If enabled, the last returned session ID is auto-filled and reused.'),
        '#default_value' => (bool) $sessionContinueDefault,
      ];
    }

    $form += $this->buildOptionsForm($form_state, $mode);

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
      '#access' => !in_array($mode, ['query', 'session_query'], TRUE),
    ];

    $form['control_mode'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Permission Mode'),
      '#default_value' => PermissionPresetHelper::normalizePermissionMode($form_state->getValue('control_mode')) ?? 'default',
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
      '#access' => !in_array($mode, ['query', 'session_query'], TRUE),
    ];

    $form['callbacks']['can_use_tool'] = [
      '#type' => 'select',
      '#title' => $this->t('canUseTool behavior'),
      '#options' => [
        'none' => $this->t('None'),
        'allow' => $this->t('Allow'),
        'deny' => $this->t('Deny'),
        'interactive' => $this->t('Interactive popup (Terminal stream only)'),
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

    $output = $form_state->get('ai_claude_agent_sdk_debug_output');
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

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->isPrimarySubmit($form_state)) {
      return;
    }

    $buildInfo = $form_state->getBuildInfo();
    $mode = (string) ($buildInfo['args'][0] ?? 'client');

    $prompt = trim((string) $form_state->getValue('prompt'));
    if ($prompt === '') {
      $form_state->setErrorByName('prompt', $this->t('Prompt / Messages is required.'));
      return;
    }

    if (!$this->isBridgeModeSupported($mode)) {
      return;
    }

    $bridgeMode = $this->resolveBridgeMode($form_state, $mode);
    if ($bridgeMode !== 'cli') {
      return;
    }

    $selectedToolIds = $this->extractSelectedBridgeToolIds($form_state);
    if (empty($selectedToolIds)) {
      $form_state->setErrorByName('option_bridge_tools', $this->t('Select at least one tool for bridge mode.'));
      return;
    }

    $supportedCount = 0;
    foreach ($selectedToolIds as $toolId) {
      if ($this->normalizeBridgeToolId($toolId) !== null) {
        $supportedCount++;
      }
    }
    if ($supportedCount === 0) {
      $form_state->setErrorByName('option_bridge_tools', $this->t('Bridge mode requires at least one Tool API entry (tool IDs that start with <code>tool:</code>).'));
    }
  }

  private function isPrimarySubmit(FormStateInterface $form_state): bool {
    $triggeringElement = $form_state->getTriggeringElement();
    if (!is_array($triggeringElement)) {
      return TRUE;
    }

    $name = (string) ($triggeringElement['#name'] ?? '');
    if (str_ends_with($name, '-ai-tools-library-update') || $name === 'open_tools_library') {
      return FALSE;
    }

    $parents = $triggeringElement['#parents'] ?? [];
    return $parents === ['actions', 'submit'] || $name === 'submit';
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->isPrimarySubmit($form_state)) {
      $selectedBridgeTools = $this->extractSelectedBridgeToolIds($form_state);
      $form_state->set('ai_claude_agent_sdk_debug_bridge_tools', $selectedBridgeTools);
      $form_state->set('ai_claude_agent_sdk_debug_bridge_tool_config', $this->extractBridgeToolConfigs($form_state));
      return;
    }

    $buildInfo = $form_state->getBuildInfo();
    $mode = (string) ($buildInfo['args'][0] ?? 'client');
    $inputType = (string) $form_state->getValue('input_type');
    $promptRaw = (string) $form_state->getValue('prompt');
    $controlAction = (string) $form_state->getValue('control_action');
    $controlMode = (string) $form_state->getValue('control_mode');
    $controlModel = (string) $form_state->getValue('control_model');
    $controlUserMessageId = (string) $form_state->getValue('control_user_message_id');
    $sessionContinue = $mode === 'session_query' ? (bool) $form_state->getValue('session_continue') : FALSE;
    $bridgeMode = $this->resolveBridgeMode($form_state, $mode);
    $requestedResume = null;

    $selectedBridgeTools = $this->extractSelectedBridgeToolIds($form_state);
    if ($bridgeMode === 'cli' && empty($selectedBridgeTools)) {
      $storedBridgeTools = $form_state->get('ai_claude_agent_sdk_debug_bridge_tools');
      if (is_array($storedBridgeTools) && !empty($storedBridgeTools)) {
        $selectedBridgeTools = array_values(array_filter($storedBridgeTools, 'is_string'));
      }
    }
    $form_state->set('ai_claude_agent_sdk_debug_bridge_mode', $bridgeMode !== '' ? $bridgeMode : 'none');
    $form_state->set('ai_claude_agent_sdk_debug_bridge_tools', $selectedBridgeTools);
    $form_state->set('ai_claude_agent_sdk_debug_bridge_tool_config', $this->extractBridgeToolConfigs($form_state));

    $optionsData = $this->collectOptions($form_state, $mode);
    if (($mode === 'client' || $mode === 'terminal') && !isset($optionsData['initializeTimeout'])) {
      // Keep client initialization failures fast in debug mode.
      $optionsData['initializeTimeout'] = 2.0;
    }
    if (($mode === 'client' || $mode === 'terminal') && !isset($optionsData['skipInitialize'])) {
      $optionsData['skipInitialize'] = TRUE;
    }
    if ($mode === 'session_query') {
      if (!$sessionContinue) {
        unset($optionsData['resume']);
        $optionsData['continueConversation'] = FALSE;
        $form_state->set('ai_claude_agent_sdk_debug_resume', '');
      }
      else {
        $resume = trim((string) ($optionsData['resume'] ?? ''));
        if ($resume === '') {
          $resume = (string) ($form_state->get('ai_claude_agent_sdk_debug_resume') ?? '');
        }
        if ($resume === '') {
          unset($optionsData['resume']);
          $optionsData['continueConversation'] = FALSE;
        }
        else {
          if (count($this->sessionFileStore->listSessionFiles($resume)) === 0) {
            $this->messenger()->addError($this->t('Resume session ID not found in local Claude session files: @id', ['@id' => $resume]));
            return;
          }
          $optionsData['resume'] = $resume;
          $optionsData['continueConversation'] = TRUE;
          $requestedResume = $resume;
        }
      }
      $form_state->set('ai_claude_agent_sdk_debug_session_continue', $sessionContinue);
    }

    $permissionPresetResult = PermissionPresetHelper::apply($optionsData);
    $optionsData = is_array($permissionPresetResult['options'] ?? null) ? $permissionPresetResult['options'] : $optionsData;
    $permissionPresetWarnings = is_array($permissionPresetResult['warnings'] ?? null) ? $permissionPresetResult['warnings'] : [];
    foreach ($permissionPresetWarnings as $permissionPresetWarning) {
      if (is_string($permissionPresetWarning) && $permissionPresetWarning !== '') {
        $this->messenger()->addWarning($this->t('Permission preset warning: @warning', [
          '@warning' => $permissionPresetWarning,
        ]));
      }
    }

    if (($optionsData['bridgeMode'] ?? null) === 'cli' && !empty($selectedBridgeTools)) {
      $ignoredToolIds = [];
      foreach ($selectedBridgeTools as $toolId) {
        if ($this->normalizeBridgeToolId($toolId) === null) {
          $ignoredToolIds[] = $toolId;
        }
      }
      if (!empty($ignoredToolIds)) {
        $this->messenger()->addWarning($this->t('Bridge mode ignored non-Tool-API entries: @tools', [
          '@tools' => implode(', ', $ignoredToolIds),
        ]));
      }
    }

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
      $this->lastSessionId = null;

      if ($mode === 'query' || $mode === 'session_query') {
        $output = $this->runQuery($promptRaw, $options, $sessionMeta);
      }
      else {
        if ($inputType === 'jsonl') {
          $messages = $this->parseJsonl($promptRaw);
          $output = $this->runStreaming($messages, $options, $controlAction, $controlMode, $controlModel, $controlUserMessageId, $sessionMeta, $mode);
        }
        elseif ($inputType === 'deepchat') {
          $messages = $this->convertDeepChatToMessages($promptRaw);
          $output = $this->runStreaming($messages, $options, $controlAction, $controlMode, $controlModel, $controlUserMessageId, $sessionMeta, $mode);
        }
        else {
          $message = [
            'type' => 'user',
            'message' => [
              'role' => 'user',
              'content' => $promptRaw,
            ],
            'parent_tool_use_id' => null,
          ];
          if (!in_array($mode, ['client', 'terminal'], TRUE)) {
            $message['session_id'] = 'default';
          }
          $messages = [$message];
          $output = $this->runStreaming($messages, $options, $controlAction, $controlMode, $controlModel, $controlUserMessageId, $sessionMeta, $mode);
        }
      }

      if ($mode === 'session_query' && is_string($requestedResume) && $requestedResume !== '' && is_string($this->lastSessionId) && $this->lastSessionId !== '' && $this->lastSessionId !== $requestedResume) {
        throw new \RuntimeException(sprintf('Resume session mismatch: requested %s but Claude returned %s. Response rejected.', $requestedResume, $this->lastSessionId));
      }

      if ($output === '') {
        $output = '[No assistant text returned. The model may have only produced tool events. Check the Sessions debug view for full event details.]';
        $this->messenger()->addWarning($this->t('No assistant text was returned. Check the Sessions debug view for tool events.'));
      }

      $form_state->set('ai_claude_agent_sdk_debug_output', $output);
      if (is_string($this->lastSessionId) && $this->lastSessionId !== '') {
        $this->messenger()->addStatus($this->t('Session ID: @session_id', [
          '@session_id' => $this->lastSessionId,
        ]));
        if ($mode === 'session_query' && $sessionContinue) {
          $form_state->setValue('option_resume', $this->lastSessionId);
          $form_state->set('ai_claude_agent_sdk_debug_resume', $this->lastSessionId);
          $userInput = $form_state->getUserInput();
          if (is_array($userInput)) {
            $userInput['option_resume'] = $this->lastSessionId;
            $form_state->setUserInput($userInput);
          }
        }
      }
      $form_state->setRebuild(TRUE);
    }
    catch (\Throwable $e) {
      \Drupal::logger('ai_claude_agent_sdk_debug')->error('Debug query failed: @type @message', [
        '@type' => get_class($e),
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('SDK error: @msg', ['@msg' => $e->getMessage()]));
      $form_state->setRebuild(TRUE);
    }
  }

  public static function bridgeToolsElementAfterBuild(array $element, FormStateInterface $form_state): array {
    if (isset($element['tools_library']['open_modal']['#attributes']['class']) && is_array($element['tools_library']['open_modal']['#attributes']['class'])) {
      $classes = array_values(array_filter(
        $element['tools_library']['open_modal']['#attributes']['class'],
        static fn ($class): bool => !in_array((string) $class, ['js-form-submit', 'form-submit'], TRUE)
      ));
      $element['tools_library']['open_modal']['#attributes']['class'] = $classes;
    }
    if (isset($element['tools_library']['update_widget']['#submit']) && is_array($element['tools_library']['update_widget']['#submit'])) {
      $element['tools_library']['update_widget']['#submit'][] = [self::class, 'bridgeToolsUpdateSubmit'];
    }
    return $element;
  }

  public static function bridgeToolsUpdateSubmit(array $form, FormStateInterface $form_state): void {
    $userInput = $form_state->getUserInput();
    if (!is_array($userInput)) {
      return;
    }

    $raw = $userInput['tools'] ?? null;
    $selected = [];

    if (is_string($raw)) {
      $parts = explode(',', $raw);
      foreach ($parts as $part) {
        $toolId = trim($part);
        if ($toolId !== '') {
          $selected[$toolId] = $toolId;
        }
      }
    }
    elseif (is_array($raw)) {
      foreach ($raw as $value) {
        if (is_string($value) && $value !== '') {
          $selected[$value] = $value;
        }
      }
    }

    $form_state->set('ai_claude_agent_sdk_debug_bridge_tools', array_values($selected));
  }

  private function buildOptions(array $optionsData, array $debugCallbacks): ClaudeAgentOptions {
    $permissionPresetResult = PermissionPresetHelper::apply($optionsData);
    $optionsData = is_array($permissionPresetResult['options'] ?? null) ? $permissionPresetResult['options'] : $optionsData;

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
      skipInitialize: $optionsData['skipInitialize'] ?? false,
      initializeTimeout: $optionsData['initializeTimeout'] ?? null,
      user: $optionsData['user'] ?? null,
      env: $this->authEnvResolver->buildEnv($optionsData['env'] ?? []),
      extraArgs: $optionsData['extraArgs'] ?? [],
    );
  }

  private function runStreaming(array $messages, ClaudeAgentOptions $options, string $controlAction, string $controlMode, string $controlModel, string $controlUserMessageId, array $sessionMeta, string $mode): string {
    $stream = (function () use ($messages): iterable {
      foreach ($messages as $message) {
        yield $message;
      }
    })();

    $client = null;
    try {
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

      return $output;
    }
    finally {
      if ($client instanceof Client) {
        $client->close();
      }
    }
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
    $uid = \Drupal::currentUser()->id();
    $uid = is_numeric($uid) && (int) $uid > 0 ? (int) $uid : null;

    return [
      'mode' => $mode,
      'source' => $source,
      'resume' => is_string($optionsData['resume'] ?? null) ? (string) $optionsData['resume'] : null,
      'bridge_mode' => is_string($optionsData['bridgeMode'] ?? null) ? (string) $optionsData['bridgeMode'] : null,
      'bridge_allowed_tools' => is_array($optionsData['bridgeAllowedTools'] ?? null) ? array_values($optionsData['bridgeAllowedTools']) : [],
      'uid' => $uid,
    ];
  }

  private function recordSessionFromMessage(array $raw, array $sessionMeta): void {
    $sessionId = $raw['session_id'] ?? null;
    if (!is_string($sessionId) || $sessionId === '' || $sessionId === 'default') {
      return;
    }
    $this->lastSessionId = $sessionId;
    $this->sessionTracker->record($sessionId, $sessionMeta);
  }

  private function buildOptionsForm(FormStateInterface $form_state, string $mode): array {
    $sdkConfig = $this->config('ai_claude_agent_sdk.settings');
    $bridgeSupported = $this->isBridgeModeSupported($mode);

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

    $permissionModeDefault = PermissionPresetHelper::normalizePermissionMode($form_state->getValue('option_permission_mode')) ?? '';
    $form['options_basic']['option_permission_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Permission mode'),
      '#options' => [
        '' => $this->t('Use CLI default'),
        'default' => $this->t('Default'),
        'plan' => $this->t('Plan'),
        'dontAsk' => $this->t('DontAsk'),
        'acceptEdits' => $this->t('AcceptEdits'),
        'bypassPermissions' => $this->t('BypassPermissions'),
        'delegate' => $this->t('Delegate'),
      ],
      '#default_value' => $permissionModeDefault,
    ];

    $permissionPresetDefault = (string) ($form_state->getValue('option_permission_preset') ?? 'none');
    $form['options_basic']['option_permission_preset'] = [
      '#type' => 'select',
      '#title' => $this->t('Permission preset'),
      '#description' => $this->t('Quick policy presets for non-interactive testing in Terminal mode.'),
      '#options' => [
        'none' => $this->t('Manual (use fields below as-is)'),
        'strict_deny' => $this->t('Strict deny'),
        'ask' => $this->t('Ask'),
        'bridge_only' => $this->t('Allow bridge command only'),
        'bridge_plus_read' => $this->t('Allow bridge command + read helpers (pwd/ls)'),
      ],
      '#default_value' => $permissionPresetDefault,
    ];

    $bridgeModeForPreview = $this->resolveBridgeMode($form_state, $mode);
    $previewInput = [
      'permissionPreset' => $permissionPresetDefault,
      'permissionMode' => (string) (PermissionPresetHelper::normalizePermissionMode($form_state->getValue('option_permission_mode')) ?? ''),
      'settings' => trim((string) ($form_state->getValue('option_settings') ?? '')),
      'allowedTools' => $this->parseLines($form_state->getValue('option_allowed_tools')),
      'disallowedTools' => $this->parseLines($form_state->getValue('option_disallowed_tools')),
      'bridgeMode' => $bridgeModeForPreview,
    ];
    $previewResult = PermissionPresetHelper::apply($previewInput);
    $previewText = PermissionPresetHelper::formatPreview($previewResult['options'], $previewResult['warnings']);

    $form['options_basic']['option_permission_policy_preview'] = [
      '#type' => 'item',
      '#title' => $this->t('Effective policy preview'),
      '#markup' => '<pre class="ai-claude-agent-sdk-policy-preview">' . Html::escape($previewText) . '</pre>',
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
      '#default_value' => $form_state->getValue('option_continue_conversation') ?? ($mode === 'session_query' ? '1' : '0'),
      '#access' => $mode !== 'session_query',
    ];

    $resumeDefault = $form_state->getValue('option_resume');
    $sessionContinue = $mode === 'session_query'
      ? (bool) ($form_state->getValue('session_continue') ?? $form_state->get('ai_claude_agent_sdk_debug_session_continue') ?? TRUE)
      : TRUE;
    if (($resumeDefault === NULL || $resumeDefault === '') && $sessionContinue) {
      $resumeDefault = $form_state->get('ai_claude_agent_sdk_debug_resume') ?? '';
    }
    if ($resumeDefault === NULL) {
      $resumeDefault = '';
    }
    $form['options_basic']['option_resume'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Resume session ID'),
      '#description' => $this->t('Provide a session ID to resume.'),
      '#default_value' => $resumeDefault,
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

    if ($this->isBridgeIntegrationAvailable() && $bridgeSupported) {
      $bridgeModeDefault = (string) ($form_state->getValue('option_bridge_mode') ?? $form_state->get('ai_claude_agent_sdk_debug_bridge_mode') ?? 'none');
      $selectedBridgeTools = $this->extractSelectedBridgeToolIds($form_state);
      $bridgeToolConfigs = $this->extractBridgeToolConfigs($form_state);
      $toolDefinitions = $this->getAiToolDefinitions();

      $form['options_bridge'] = [
        '#type' => 'details',
        '#title' => $this->t('Bridge Mode'),
        '#description' => $this->t('Use Drupal Tool API tools through the CLI bridge. Tool selection uses the same modal picker as AI Agents.'),
        '#open' => $bridgeModeDefault !== 'none',
      ];

      $form['options_bridge']['option_bridge_mode'] = [
        '#type' => 'select',
        '#title' => $this->t('Bridge mode'),
        '#options' => [
          'none' => $this->t('Disabled'),
          'cli' => $this->t('CLI bridge via Drush'),
        ],
        '#default_value' => $bridgeModeDefault,
        '#description' => $this->t('When enabled, Claude is instructed to call Drupal tools using <code>drush ai-claude-agent-sdk:tool-run</code>.'),
      ];

      $form['options_bridge']['bridge_tools_box'] = [
        '#type' => 'details',
        '#title' => $this->t('Allowed bridge tools'),
        '#description' => $this->t('Select which tools Claude is allowed to invoke through bridge mode.'),
        '#open' => TRUE,
        '#states' => [
          'visible' => [
            ':input[name="option_bridge_mode"]' => ['value' => 'cli'],
          ],
        ],
      ];

      $form['options_bridge']['bridge_tools_box']['option_bridge_tools'] = [
        '#type' => 'ai_tools_library',
        '#title' => $this->t('Tools for bridge mode'),
        '#default_value' => $selectedBridgeTools,
        '#after_build' => [
          [self::class, 'bridgeToolsElementAfterBuild'],
        ],
      ];

      $form['options_bridge']['option_bridge_help'] = [
        '#type' => 'item',
        '#title' => $this->t('Bridge compatibility'),
        '#markup' => $this->t('Only Tool API entries (tool IDs prefixed with <code>tool:</code>) are enforced by this bridge mode.'),
        '#states' => [
          'visible' => [
            ':input[name="option_bridge_mode"]' => ['value' => 'cli'],
          ],
        ],
      ];

      if (!empty($selectedBridgeTools)) {
        $form['options_bridge']['option_bridge_tool_config'] = [
          '#type' => 'details',
          '#title' => $this->t('Bridge tool configuration'),
          '#description' => $this->t('Optional per-tool guidance appended to the bridge instructions.'),
          '#open' => FALSE,
          '#tree' => TRUE,
          '#states' => [
            'visible' => [
              ':input[name="option_bridge_mode"]' => ['value' => 'cli'],
            ],
          ],
        ];

        foreach ($selectedBridgeTools as $toolId) {
          $toolKey = $this->bridgeToolKey($toolId);
          $definition = is_array($toolDefinitions[$toolId] ?? null) ? $toolDefinitions[$toolId] : [];
          $toolLabel = (string) ($definition['name'] ?? $toolId);
          $toolDescription = trim((string) ($definition['description'] ?? ''));
          $config = is_array($bridgeToolConfigs[$toolId] ?? null) ? $bridgeToolConfigs[$toolId] : [];

          $form['options_bridge']['option_bridge_tool_config'][$toolKey] = [
            '#type' => 'details',
            '#title' => $toolLabel,
            '#open' => FALSE,
          ];

          $form['options_bridge']['option_bridge_tool_config'][$toolKey]['tool_id'] = [
            '#type' => 'hidden',
            '#value' => $toolId,
          ];

          if ($toolDescription !== '') {
            $form['options_bridge']['option_bridge_tool_config'][$toolKey]['description_current'] = [
              '#type' => 'item',
              '#title' => $this->t('Current description'),
              '#markup' => $toolDescription,
            ];
          }

          $form['options_bridge']['option_bridge_tool_config'][$toolKey]['description_override'] = [
            '#type' => 'textarea',
            '#title' => $this->t('Bridge description override'),
            '#description' => $this->t('Optional custom description for this tool in bridge instructions.'),
            '#rows' => 2,
            '#default_value' => (string) ($config['description_override'] ?? ''),
          ];

          $form['options_bridge']['option_bridge_tool_config'][$toolKey]['args_example'] = [
            '#type' => 'textarea',
            '#title' => $this->t('Args example (JSON)'),
            '#description' => $this->t('Optional JSON object example for <code>--args</code>.'),
            '#rows' => 2,
            '#default_value' => (string) ($config['args_example'] ?? ''),
          ];
        }
      }
    }
    elseif ($this->isBridgeIntegrationAvailable()) {
      $form['options_bridge_notice'] = [
        '#type' => 'details',
        '#title' => $this->t('Bridge Mode'),
        '#open' => FALSE,
      ];
      $form['options_bridge_notice']['message'] = [
        '#type' => 'item',
        '#markup' => $this->t('Bridge tool-calling is available in Client modes. Use <a href=":terminal_url">Terminal (Client)</a> for back-and-forth tool debugging.', [
          ':terminal_url' => Url::fromRoute('ai_claude_agent_sdk_debug.terminal')->toString(),
        ]),
      ];
      $form['options_bridge_notice']['option_bridge_mode'] = [
        '#type' => 'hidden',
        '#value' => 'none',
      ];
    }

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
      '#title' => $this->t('Settings (JSON or file path)'),
      '#description' => $this->t('Inline JSON object or path to a settings file to pass to Claude Code.'),
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

    $form['options_advanced']['option_skip_initialize'] = [
      '#type' => 'select',
      '#title' => $this->t('Skip client initialize control request'),
      '#description' => $this->t('Workaround for environments where Claude CLI stream mode does not respond to initialize control requests.'),
      '#options' => [
        '0' => $this->t('No'),
        '1' => $this->t('Yes'),
      ],
      '#default_value' => $form_state->getValue('option_skip_initialize') ?? ($mode === 'client' || $mode === 'terminal' ? '1' : '0'),
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

  private function collectOptions(FormStateInterface $form_state, string $mode): array {
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

    $permissionMode = PermissionPresetHelper::normalizePermissionMode($form_state->getValue('option_permission_mode'));
    if ($permissionMode !== null) {
      $options['permissionMode'] = $permissionMode;
    }

    $permissionPreset = trim((string) $form_state->getValue('option_permission_preset'));
    if ($permissionPreset !== '') {
      $options['permissionPreset'] = $permissionPreset;
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

    $options['skipInitialize'] = $this->parseBoolSelect($form_state->getValue('option_skip_initialize'));

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

    return $this->applyBridgeModeOptions($options, $form_state, $mode);
  }

  private function applyBridgeModeOptions(array $options, FormStateInterface $form_state, string $mode): array {
    $bridgeMode = $this->resolveBridgeMode($form_state, $mode);
    if ($bridgeMode !== 'cli') {
      return $options;
    }

    $selectedToolIds = $this->extractSelectedBridgeToolIds($form_state);
    $bridgeToolConfigs = $this->extractBridgeToolConfigs($form_state);
    $toolDefinitions = $this->getAiToolDefinitions();

    $bridgeTools = [];
    foreach ($selectedToolIds as $toolId) {
      $toolApiId = $this->normalizeBridgeToolId($toolId);
      if ($toolApiId === null) {
        continue;
      }
      $config = is_array($bridgeToolConfigs[$toolId] ?? null) ? $bridgeToolConfigs[$toolId] : [];
      $definition = is_array($toolDefinitions[$toolId] ?? null) ? $toolDefinitions[$toolId] : [];
      $bridgeTools[$toolApiId] = [
        'selected_id' => $toolId,
        'tool_api_id' => $toolApiId,
        'tool_label' => trim((string) ($definition['name'] ?? '')),
        'description_override' => trim((string) ($config['description_override'] ?? '')),
        'args_example' => trim((string) ($config['args_example'] ?? '')),
      ];
    }

    if (empty($bridgeTools)) {
      return $options;
    }

    $bridgePrompt = $this->buildBridgeSystemPrompt(array_values($bridgeTools));
    if ($bridgePrompt !== '') {
      $existingSystemPrompt = trim((string) ($options['systemPrompt'] ?? ''));
      $options['systemPrompt'] = $existingSystemPrompt !== ''
        ? $existingSystemPrompt . "\n\n" . $bridgePrompt
        : $bridgePrompt;
    }

    $env = is_array($options['env'] ?? null) ? $options['env'] : [];
    $env['AI_CLAUDE_AGENT_SDK_BRIDGE_MODE'] = 'cli';
    $env['AI_CLAUDE_AGENT_SDK_BRIDGE_ALLOWED_TOOLS'] = implode(',', array_keys($bridgeTools));
    $env['AI_CLAUDE_AGENT_SDK_BRIDGE_UID'] = $this->currentUser()->isAuthenticated() ? (string) $this->currentUser()->id() : '';
    $options['env'] = $env;

    if (!isset($options['permissionMode']) || trim((string) $options['permissionMode']) === '') {
      // Bridge mode relies on Bash/Drush orchestration; default to CLI default permission behavior unless explicitly overridden.
      $options['permissionMode'] = 'default';
    }

    if (isset($options['allowedTools']) && is_array($options['allowedTools']) && !in_array('Bash', $options['allowedTools'], TRUE)) {
      $options['allowedTools'][] = 'Bash';
    }
    if (isset($options['tools']) && is_array($options['tools']) && !in_array('Bash', $options['tools'], TRUE)) {
      $options['tools'][] = 'Bash';
    }

    $options['bridgeMode'] = 'cli';
    $options['bridgeAllowedTools'] = array_keys($bridgeTools);

    return $options;
  }

  private function buildBridgeSystemPrompt(array $bridgeTools): string {
    if (empty($bridgeTools)) {
      return '';
    }

    $lines = [
      'Drupal Tool API bridge mode is enabled.',
      'Selected bridge tools are available through Bash command execution.',
      'Use this command to run Drupal tools:',
      "drush ai-claude-agent-sdk:tool-run tool_api:<tool_id> --args='{\"key\":\"value\"}'",
      'Only use these tool_id values:',
    ];

    foreach ($bridgeTools as $bridgeTool) {
      $toolApiId = (string) ($bridgeTool['tool_api_id'] ?? '');
      if ($toolApiId === '') {
        continue;
      }
      $label = trim((string) ($bridgeTool['tool_label'] ?? ''));
      $selectedId = trim((string) ($bridgeTool['selected_id'] ?? ''));
      if ($label !== '' || $selectedId !== '') {
        $parts = [];
        if ($label !== '') {
          $parts[] = 'label: ' . $label;
        }
        if ($selectedId !== '') {
          $parts[] = 'picker_id: ' . $selectedId;
        }
        $lines[] = '- ' . $toolApiId . ' (' . implode(', ', $parts) . ')';
      }
      else {
        $lines[] = '- ' . $toolApiId;
      }
      $descriptionOverride = trim((string) ($bridgeTool['description_override'] ?? ''));
      if ($descriptionOverride !== '') {
        $lines[] = '  description: ' . $descriptionOverride;
      }
      $argsExample = trim((string) ($bridgeTool['args_example'] ?? ''));
      if ($argsExample !== '') {
        $lines[] = '  args_example: ' . $argsExample;
      }
    }

    $lines[] = 'Never call a tool outside this allowlist.';
    $lines[] = 'Always pass --args as a valid JSON object (use {} for tools without inputs).';
    $lines[] = 'This runs inside the Drupal container; use drush directly and do not prepend ddev unless absolutely required.';
    $lines[] = 'Do not claim tools are unavailable if they appear in this allowlist; call them through the bridge command.';

    return implode("\n", $lines);
  }

  private function isBridgeIntegrationAvailable(): bool {
    return \Drupal::moduleHandler()->moduleExists('ai_claude_agent_sdk_agents_integration');
  }

  private function extractSelectedBridgeToolIds(FormStateInterface $form_state): array {
    $value = $form_state->getValue('option_bridge_tools');
    if ($this->isEmptyToolsValue($value)) {
      $value = $form_state->getValue('tools');
    }
    if ($this->isEmptyToolsValue($value)) {
      $value = $form_state->getValue([
        'options_bridge',
        'bridge_tools_box',
        'option_bridge_tools',
      ]);
    }
    if ($this->isEmptyToolsValue($value)) {
      $userInput = $form_state->getUserInput();
      if (is_array($userInput)) {
        $value = $this->extractBridgeToolsFromUserInput($userInput);
      }
    }
    if ($this->isEmptyToolsValue($value)) {
      $requestInput = \Drupal::request()->request->all();
      if (is_array($requestInput)) {
        $value = $this->extractBridgeToolsFromUserInput($requestInput);
      }
    }
    if ($this->isEmptyToolsValue($value)) {
      $value = $form_state->get('ai_claude_agent_sdk_debug_bridge_tools');
    }
    return $this->normalizeToolsLibraryValue($value);
  }

  private function extractBridgeToolsFromUserInput(array $userInput): mixed {
    $candidates = [
      $userInput['tools'] ?? null,
      $userInput['option_bridge_tools'] ?? null,
      $userInput['option_bridge_tools']['tools'] ?? null,
      $userInput['options_bridge']['bridge_tools_box']['option_bridge_tools'] ?? null,
      $userInput['options_bridge']['bridge_tools_box']['option_bridge_tools']['tools'] ?? null,
    ];

    foreach ($candidates as $candidate) {
      if ($candidate !== null && $candidate !== '') {
        return $candidate;
      }
    }

    return null;
  }

  private function isEmptyToolsValue(mixed $value): bool {
    if ($value === null) {
      return true;
    }
    if (is_string($value)) {
      return trim($value) === '';
    }
    if (is_array($value)) {
      return empty($this->normalizeToolsLibraryValue($value));
    }
    return false;
  }

  private function resolveBridgeMode(FormStateInterface $form_state, string $mode): string {
    if (!$this->isBridgeModeSupported($mode)) {
      return 'none';
    }

    $value = $form_state->getValue('option_bridge_mode');
    if (is_string($value) && $value !== '') {
      return $value;
    }

    $stored = $form_state->get('ai_claude_agent_sdk_debug_bridge_mode');
    if (is_string($stored) && $stored !== '') {
      return $stored;
    }

    return 'none';
  }

  private function isBridgeModeSupported(string $mode): bool {
    return in_array($mode, ['client', 'terminal'], TRUE);
  }

  private function extractBridgeToolConfigs(FormStateInterface $form_state): array {
    $raw = $form_state->getValue('option_bridge_tool_config');
    if (!is_array($raw)) {
      $raw = $form_state->get('ai_claude_agent_sdk_debug_bridge_tool_config');
    }
    if (!is_array($raw)) {
      return [];
    }

    $configs = [];
    foreach ($raw as $item) {
      if (!is_array($item)) {
        continue;
      }
      $toolId = trim((string) ($item['tool_id'] ?? ''));
      if ($toolId === '') {
        continue;
      }

      $configs[$toolId] = [
        'description_override' => trim((string) ($item['description_override'] ?? '')),
        'args_example' => trim((string) ($item['args_example'] ?? '')),
      ];
    }

    return $configs;
  }

  private function normalizeToolsLibraryValue(mixed $value): array {
    $out = [];

    if (is_string($value)) {
      $parts = explode(',', $value);
      foreach ($parts as $part) {
        $toolId = trim($part);
        if ($toolId !== '') {
          $out[$toolId] = $toolId;
        }
      }
      return array_values($out);
    }

    if (is_array($value)) {
      if (array_key_exists('tools', $value)) {
        foreach ($this->normalizeToolsLibraryValue($value['tools']) as $toolId) {
          $out[$toolId] = $toolId;
        }
      }

      foreach ($value as $key => $item) {
        if ($key === 'tools') {
          continue;
        }

        if (is_string($item)) {
          foreach ($this->normalizeToolsLibraryValue($item) as $toolId) {
            $out[$toolId] = $toolId;
          }
          continue;
        }

        if (is_array($item)) {
          foreach ($this->normalizeToolsLibraryValue($item) as $toolId) {
            $out[$toolId] = $toolId;
          }
          continue;
        }

        if (is_bool($item) && $item && is_string($key) && $key !== '') {
          $out[$key] = $key;
        }
      }
    }

    return array_values($out);
  }

  private function normalizeBridgeToolId(string $toolId): ?string {
    $toolId = trim($toolId);
    if ($toolId === '') {
      return null;
    }

    if (str_starts_with($toolId, 'tool:')) {
      $toolId = substr($toolId, 5);
    }
    elseif (str_starts_with($toolId, 'tool_api:')) {
      $toolId = substr($toolId, 9);
    }

    if ($toolId === '') {
      return null;
    }

    return preg_match('/^[a-zA-Z0-9_]+$/', $toolId) ? $toolId : null;
  }

  private function bridgeToolKey(string $toolId): string {
    return preg_replace('/[^a-zA-Z0-9_]+/', '_', $toolId) ?? $toolId;
  }

  private function getAiToolDefinitions(): array {
    if (!\Drupal::hasService('plugin.manager.ai.function_calls')) {
      return [];
    }
    $definitions = \Drupal::service('plugin.manager.ai.function_calls')->getDefinitions();
    return is_array($definitions) ? $definitions : [];
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
      $normalizedMode = PermissionPresetHelper::normalizePermissionMode($mode);
      $client->setPermissionMode($normalizedMode ?? 'default');
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

    if ($mode === 'interactive') {
      return function (string $toolName, array $input, $context) {
        return new PermissionResultDeny('Interactive approval is only supported in Terminal stream mode.', false);
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
