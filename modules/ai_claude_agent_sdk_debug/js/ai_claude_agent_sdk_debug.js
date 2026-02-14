(function (Drupal, $, once, drupalSettings) {
  'use strict';

  Drupal.behaviors.claudeAgentSdkDebug = {
    attach: function (context) {
      const forms = once('claude-agent-sdk-debug', 'form#ai-claude-agent-sdk-debug-form, form#claude-agent-sdk-debug-form', context);
      if (!forms.length) {
        return;
      }
      const $form = $(forms);

      const modeFromSettings = (drupalSettings && drupalSettings.claudeAgentSdkDebug && drupalSettings.claudeAgentSdkDebug.mode) || '';
      const modeFromPath = (function () {
        const path = String(window.location.pathname || '');
        if (path.indexOf('/debug/terminal') !== -1) {
          return 'terminal';
        }
        if (path.indexOf('/debug/session-query') !== -1) {
          return 'session_query';
        }
        if (path.indexOf('/debug/query') !== -1) {
          return 'query';
        }
        return 'client';
      })();
      const debugMode = modeFromSettings || modeFromPath;

      const $streamButton = $('<button type="button" class="button">Stream via SSE</button>');
      const $output = $('<textarea readonly rows="12" style="width:100%;"></textarea>');

      if (debugMode === 'terminal') {
        $form.find('[name="input_type"]').closest('.form-item').hide();
        $form.find('[name="prompt"]').closest('.form-item').hide();
        $form.find('div.form-actions').hide();
        $form.find('input[type="submit"], button[type="submit"]').hide();
      }

      if (debugMode === 'client' || debugMode === 'terminal') {
        $form.find('div.form-actions').append($streamButton);
        $form.append($('<div class="claude-agent-sdk-debug-stream"></div>').append('<h3>Streaming Output</h3>').append($output));
      }

      const $chat = $('<div class="claude-agent-sdk-chat"></div>');
      const $chatLog = $('<div class="claude-agent-sdk-terminal-output"></div>');
      const $chatInput = $('<textarea rows="3" class="claude-agent-sdk-terminal-input-text"></textarea>');
      const $chatSend = $('<button type="button" class="button button--primary">Send</button>');
      const $chatControls = $('<div class="claude-agent-sdk-terminal-input"></div>');
      const $chatStatus = $('<div class="claude-agent-sdk-terminal-status"></div>');
      const $rawToggle = $('<label class="claude-agent-sdk-terminal-status claude-agent-sdk-terminal-raw-toggle"><input type="checkbox"> Show raw SSE log</label>');
      const $rawLog = $('<div class="claude-agent-sdk-terminal-raw" style="display:none;"></div>');
      const $chatSession = $('<code class="claude-agent-sdk-terminal-status"></code>');
      $chatControls.append($chatInput).append($chatSend);
      $chat.append($chatStatus).append($chatLog).append($chatControls).append($chatSession);
      const $rawToggleInput = $rawToggle.find('input');
      if ($rawToggleInput.length) {
        $rawToggleInput.on('change', function () {
          if (this.checked) {
            $rawLog.show();
          } else {
            $rawLog.hide();
          }
        });
      }

      if (debugMode === 'client' || debugMode === 'terminal') {
        if (debugMode === 'terminal') {
          const $layout = $('<div class="claude-agent-sdk-terminal-layout"></div>');
          const $sidebar = $('<div class="claude-agent-sdk-terminal-sidebar"></div>');
          const $main = $('<div class="claude-agent-sdk-terminal-main"></div>');

          const $optionsBasic = $form.find('[data-drupal-selector="edit-options-basic"]').closest('details');
          const $optionsAdvanced = $form.find('[data-drupal-selector="edit-options-advanced"]').closest('details');
          const $callbacks = $form.find('[data-drupal-selector="edit-callbacks"]').closest('details');
          const $controlAction = $form.find('[name="control_action"]').closest('.form-item');
          const $controlMode = $form.find('[name="control_mode"]').closest('.form-item');
          const $controlModel = $form.find('[name="control_model"]').closest('.form-item');
          const $controlRewind = $form.find('[name="control_user_message_id"]').closest('.form-item');

          $sidebar.append($optionsBasic, $optionsAdvanced, $callbacks);
          $sidebar.append($controlAction, $controlMode, $controlModel, $controlRewind);

          $main.append('<h3>Terminal</h3>');
          $main.append($chatStatus).append($rawToggle).append($chatLog).append($chatControls).append($chatSession).append($rawLog);

          $layout.append($sidebar).append($main);
          $form.append($layout);
          $form.find('.claude-agent-sdk-debug-stream').hide();
        } else {
          $form.append($chat);
        }
      }

      let chatSessionId = null;

      $streamButton.on('click', function () {
        $output.val('');

        const payload = buildPayload($form);
        const streamUrl = (drupalSettings && drupalSettings.claudeAgentSdkDebug && drupalSettings.claudeAgentSdkDebug.streamUrl) || '/claudeagentsdkdebug/stream';
        fetch(streamUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        }).then(function (response) {
          if (!response.ok) {
            return response.text().then(function (text) {
              throw new Error(text || ('HTTP ' + response.status));
            });
          }
          if (!response.body) {
            throw new Error('No response body from stream endpoint.');
          }
          const reader = response.body.getReader();
          const decoder = new TextDecoder('utf-8');
          let buffer = '';

          function read() {
            reader.read().then(function (result) {
              if (result.done) {
                return;
              }
              buffer += decoder.decode(result.value, { stream: true });
              const parts = buffer.split('\n\n');
              buffer = parts.pop();
              parts.forEach(function (part) {
                if (part.indexOf('data: ') === 0) {
                  const json = part.slice(6);
                  try {
                    const data = JSON.parse(json);
                    if (data.message) {
                      $output.val($output.val() + JSON.stringify(data.message) + "\n");
                    }
                    if (data.session_id) {
                      chatSessionId = data.session_id;
                      $chatSession.text('Session: ' + chatSessionId);
                    }
                    if (data.error) {
                      $output.val($output.val() + 'ERROR: ' + data.error + "\n");
                    }
                  } catch (e) {
                    $output.val($output.val() + json + "\n");
                  }
                }
              });
              read();
            });
          }

          read();
        }).catch(function (error) {
          $output.val($output.val() + 'ERROR: ' + error.message + "\n");
        });
      });

      $chatSend.on('click', function () {
        if (debugMode !== 'client' && debugMode !== 'terminal') {
          return;
        }
        const prompt = String($chatInput.val() || '').trim();
        if (!prompt) {
          return;
        }

        appendTerminalLine('> ' + prompt, 'user');
        $chatInput.val('');
        setStatus('Sending...');

        const payload = buildChatPayload($form, prompt, chatSessionId);
        payload.options.includePartialMessages = true;

        const streamUrl = (drupalSettings && drupalSettings.claudeAgentSdkDebug && drupalSettings.claudeAgentSdkDebug.streamUrl) || '/claudeagentsdkdebug/stream';

        fetch(streamUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        }).then(function (response) {
          if (!response.ok) {
            return response.text().then(function (text) {
              throw new Error(text || ('HTTP ' + response.status));
            });
          }
          if (!response.body) {
            throw new Error('No response body from stream endpoint.');
          }
          const reader = response.body.getReader();
          const decoder = new TextDecoder('utf-8');
          let buffer = '';

          function read() {
            reader.read().then(function (result) {
              if (result.done) {
                setStatus('Idle');
                return;
              }
              buffer += decoder.decode(result.value, { stream: true });
              const parts = buffer.split('\n\n');
              buffer = parts.pop();
              parts.forEach(function (part) {
                if (part.indexOf('data: ') === 0) {
                  const json = part.slice(6);
                  try {
                    const data = JSON.parse(json);
                    appendRawEvent(json);
                    if (data.session_id) {
                      chatSessionId = data.session_id;
                      $chatSession.text('Session: ' + chatSessionId);
                    }
                    if (data.status) {
                      setStatus(data.status);
                    }
                    if (data.message) {
                      const text = extractAssistantText(data.message);
                      if (text) {
                        appendTerminalLine(text, 'assistant');
                      }
                    }
                    if (data.error) {
                      appendTerminalLine('ERROR: ' + data.error, 'error');
                    }
                  } catch (e) {
                    appendTerminalLine('ERROR: ' + json, 'error');
                  }
                }
              });
              read();
            });
          }

          read();
        }).catch(function (error) {
          appendTerminalLine('ERROR: ' + error.message, 'error');
          setStatus('Error');
        });
      });

      function buildPayload($form) {
        const inputType = $form.find('[name="input_type"]').val();
        const prompt = $form.find('[name="prompt"]').val();
        const controlAction = $form.find('[name="control_action"]').val();
        const controlMode = $form.find('[name="control_mode"]').val();
        const controlModel = $form.find('[name="control_model"]').val();
        const controlUserMessageId = $form.find('[name="control_user_message_id"]').val();

        const canUseTool = $form.find('[name="can_use_tool"]').val();
        const canUseToolMessage = $form.find('[name="can_use_tool_message"]').val();
        const canUseToolInterrupt = $form.find('[name="can_use_tool_interrupt"]').is(':checked');
        const hookMatchers = $form.find('[name="hook_matchers"]').val();
        const hookOutput = $form.find('[name="hook_output"]').val();
        const mcpResponse = $form.find('[name="mcp_response"]').val();

        const options = buildOptionsFromForm($form);

        let messages = [];
        if (inputType === 'jsonl') {
          messages = parseJsonl(prompt);
        } else if (inputType === 'deepchat') {
          messages = convertDeepChat(prompt);
        } else {
          const firstMessage = {
            type: 'user',
            message: { role: 'user', content: String(prompt) },
            parent_tool_use_id: null,
          };
          if (debugMode !== 'client' && debugMode !== 'terminal') {
            firstMessage.session_id = 'default';
          }
          messages = [firstMessage];
        }

        const control = buildControl(controlAction, controlMode, controlModel, controlUserMessageId);

        return {
          mode: debugMode,
          options: options,
          messages: messages,
          control: control,
          debug_callbacks: {
            can_use_tool: canUseTool,
            can_use_tool_message: canUseToolMessage,
            can_use_tool_interrupt: canUseToolInterrupt,
            hook_matchers: hookMatchers,
            hook_output: hookOutput,
            mcp_response: mcpResponse,
          },
        };
      }

      function buildChatPayload($form, prompt, sessionId) {
        const options = buildOptionsFromForm($form);
        options.continueConversation = !!sessionId;
        if (sessionId) {
          options.resume = sessionId;
        }

        const message = {
          type: 'user',
          message: { role: 'user', content: String(prompt) },
          parent_tool_use_id: null,
        };
        if (sessionId) {
          message.session_id = sessionId;
        }

        const messages = [message];

        const payload = {
          mode: debugMode,
          options: options,
          messages: messages,
          control: null,
          debug_callbacks: buildDebugCallbacks($form),
        };
        if (sessionId) {
          payload.session_id = sessionId;
        }

        return payload;
      }

      function buildOptionsFromForm($form) {
        const options = {};

        const cliPath = String($form.find('[name="option_cli_path"]').val() || '').trim();
        if (cliPath) {
          options.cliPath = cliPath;
        }

        const cwd = String($form.find('[name="option_cwd"]').val() || '').trim();
        if (cwd) {
          options.cwd = cwd;
        }

        const systemPrompt = String($form.find('[name="option_system_prompt"]').val() || '').trim();
        if (systemPrompt) {
          options.systemPrompt = systemPrompt;
        }

        const modelChoice = String($form.find('[name="option_model_choice"]').val() || 'default');
        if (modelChoice.indexOf('preset:') === 0) {
          options.model = modelChoice.slice(7);
        } else if (modelChoice === 'custom') {
          const model = String($form.find('[name="option_model_custom"]').val() || '').trim();
          if (model) {
            options.model = model;
          }
        }

        const permissionMode = String($form.find('[name="option_permission_mode"]').val() || '');
        if (permissionMode) {
          options.permissionMode = permissionMode;
        }

        const permissionPreset = String($form.find('[name="option_permission_preset"]').val() || '').trim();
        if (permissionPreset) {
          options.permissionPreset = permissionPreset;
        }

        const maxTurns = String($form.find('[name="option_max_turns"]').val() || '').trim();
        if (maxTurns) {
          options.maxTurns = parseInt(maxTurns, 10);
        }

        const maxBudgetUsd = String($form.find('[name="option_max_budget_usd"]').val() || '').trim();
        if (maxBudgetUsd) {
          options.maxBudgetUsd = parseFloat(maxBudgetUsd);
        }

        options.continueConversation = readBoolSelect($form, 'option_continue_conversation');

        const resume = String($form.find('[name="option_resume"]').val() || '').trim();
        if (resume) {
          options.resume = resume;
        }

        options.includePartialMessages = readBoolSelect($form, 'option_include_partial_messages');
        options.forkSession = readBoolSelect($form, 'option_fork_session');
        options.enableFileCheckpointing = readBoolSelect($form, 'option_enable_file_checkpointing');

        const tools = parseLines($form.find('[name="option_tools"]').val());
        if (tools.length) {
          options.tools = tools;
        }

        const allowedTools = parseLines($form.find('[name="option_allowed_tools"]').val());
        if (allowedTools.length) {
          options.allowedTools = allowedTools;
        }

        const disallowedTools = parseLines($form.find('[name="option_disallowed_tools"]').val());
        if (disallowedTools.length) {
          options.disallowedTools = disallowedTools;
        }

        const betas = parseLines($form.find('[name="option_betas"]').val());
        if (betas.length) {
          options.betas = betas;
        }

        const fallbackModel = String($form.find('[name="option_fallback_model"]').val() || '').trim();
        if (fallbackModel) {
          options.fallbackModel = fallbackModel;
        }

        const permissionPromptToolName = String($form.find('[name="option_permission_prompt_tool_name"]').val() || '').trim();
        if (permissionPromptToolName) {
          options.permissionPromptToolName = permissionPromptToolName;
        }

        const settings = String($form.find('[name="option_settings"]').val() || '').trim();
        if (settings) {
          options.settings = settings;
        }

        const sandbox = parseJson($form.find('[name="option_sandbox"]').val());
        if (sandbox) {
          options.sandbox = sandbox;
        }

        const addDirs = parseLines($form.find('[name="option_add_dirs"]').val());
        if (addDirs.length) {
          options.addDirs = addDirs;
        }

        const mcpServers = parseJson($form.find('[name="option_mcp_servers"]').val());
        if (mcpServers) {
          options.mcpServers = mcpServers;
        }

        const agents = parseJson($form.find('[name="option_agents"]').val());
        if (agents) {
          options.agents = agents;
        }

        const settingSources = parseJson($form.find('[name="option_setting_sources"]').val());
        if (settingSources) {
          options.settingSources = settingSources;
        }

        const plugins = parseJson($form.find('[name="option_plugins"]').val());
        if (plugins) {
          options.plugins = plugins;
        }

        const maxThinkingTokens = String($form.find('[name="option_max_thinking_tokens"]').val() || '').trim();
        if (maxThinkingTokens) {
          options.maxThinkingTokens = parseInt(maxThinkingTokens, 10);
        }

        const outputFormat = parseJson($form.find('[name="option_output_format"]').val());
        if (outputFormat) {
          options.outputFormat = outputFormat;
        }

        const maxBufferSize = String($form.find('[name="option_max_buffer_size"]').val() || '').trim();
        if (maxBufferSize) {
          options.maxBufferSize = parseInt(maxBufferSize, 10);
        }

        const initializeTimeout = String($form.find('[name="option_initialize_timeout"]').val() || '').trim();
        if (initializeTimeout) {
          options.initializeTimeout = parseFloat(initializeTimeout);
        }

        options.skipInitialize = readBoolSelect($form, 'option_skip_initialize');

        const user = String($form.find('[name="option_user"]').val() || '').trim();
        if (user) {
          options.user = user;
        }

        const env = parseJson($form.find('[name="option_env"]').val());
        if (env) {
          options.env = env;
        }

        const extraArgs = parseLines($form.find('[name="option_extra_args"]').val());
        if (extraArgs.length) {
          options.extraArgs = extraArgs;
        }

        return options;
      }

      function readBoolSelect($form, name) {
        return String($form.find('[name="' + name + '"]').val() || '0') === '1';
      }

      function parseLines(raw) {
        const text = String(raw || '');
        const lines = text.split(/\r\n|\r|\n/);
        return lines.map(function (line) { return line.trim(); }).filter(Boolean);
      }

      function parseJson(raw) {
        const text = String(raw || '').trim();
        if (!text) {
          return null;
        }
        try {
          const parsed = JSON.parse(text);
          if (parsed && typeof parsed === 'object') {
            return parsed;
          }
        } catch (e) {
        }
        return null;
      }

      function buildDebugCallbacks($form) {
        return {
          can_use_tool: $form.find('[name="can_use_tool"]').val(),
          can_use_tool_message: $form.find('[name="can_use_tool_message"]').val(),
          can_use_tool_interrupt: $form.find('[name="can_use_tool_interrupt"]').is(':checked'),
          hook_matchers: $form.find('[name="hook_matchers"]').val(),
          hook_output: $form.find('[name="hook_output"]').val(),
          mcp_response: $form.find('[name="mcp_response"]').val(),
        };
      }

      function appendTerminalLine(text, type) {
        const safe = $('<div></div>').text(text).html();
        const className = 'claude-agent-sdk-terminal-line--' + (type || 'system');
        const $line = $('<div></div>').addClass(className).html(safe);
        $chatLog.append($line);
        $chatLog.scrollTop($chatLog[0].scrollHeight);
      }

      function setStatus(text) {
        $chatStatus.text(text || '');
      }

      function appendRawEvent(text) {
        const safe = $('<div></div>').text(text).html();
        const $line = $('<div></div>').html(safe);
        $rawLog.append($line);
        $rawLog.scrollTop($rawLog[0].scrollHeight);
      }

      function extractAssistantText(message) {
        if (!message || message.type !== 'assistant') {
          return '';
        }
        const content = message.message && message.message.content ? message.message.content : [];
        if (!Array.isArray(content)) {
          return '';
        }
        let text = '';
        content.forEach(function (block) {
          if (block && block.type === 'text' && block.text) {
            text += block.text;
          }
        });
        return text;
      }

      function parseJsonl(raw) {
        const lines = String(raw || '').split(/\r\n|\r|\n/);
        const out = [];
        lines.forEach(function (line) {
          const trimmed = line.trim();
          if (!trimmed) {
            return;
          }
          try {
            out.push(JSON.parse(trimmed));
          } catch (e) {
          }
        });
        return out;
      }

      function convertDeepChat(raw) {
        let payload = null;
        try {
          payload = JSON.parse(raw);
        } catch (e) {
          return [];
        }
        if (!payload || !Array.isArray(payload.messages)) {
          return [];
        }
        return payload.messages.map(function (msg) {
          const message = {
            type: msg.role || 'user',
            message: { role: msg.role || 'user', content: msg.text || '' },
            parent_tool_use_id: null,
          };
          if (debugMode !== 'client' && debugMode !== 'terminal') {
            message.session_id = 'default';
          }
          return message;
        });
      }

      function buildControl(action, mode, model, userMessageId) {
        if (!action || action === 'none') {
          return null;
        }
        const control = { action: action };
        if (action === 'set_permission_mode') {
          control.mode = mode || 'default';
        }
        if (action === 'set_model') {
          control.model = model || null;
        }
        if (action === 'rewind_files') {
          control.user_message_id = userMessageId || null;
        }
        return control;
      }
    }
  };
})(Drupal, jQuery, once, drupalSettings);
