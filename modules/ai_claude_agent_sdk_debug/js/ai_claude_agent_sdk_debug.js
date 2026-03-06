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
      const permissionResponseUrl = (drupalSettings && drupalSettings.claudeAgentSdkDebug && drupalSettings.claudeAgentSdkDebug.permissionResponseUrl) || '';
      const runtimeId = 'runtime-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);

      const $streamButton = $('<button type="button" class="button">Stream via SSE</button>');
      const $output = $('<textarea readonly rows="12" style="width:100%;"></textarea>');

      if (debugMode === 'terminal') {
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

      const permissionQueue = [];
      let activePermissionRequest = null;
      let queryId = null;
      const $permissionModal = $('<div class="claude-agent-sdk-permission-modal" style="display:none;"></div>');
      const $permissionPanel = $('<div class="claude-agent-sdk-permission-panel"></div>');
      const $permissionTitle = $('<h3>Tool Permission Required</h3>');
      const $permissionBody = $('<div class="claude-agent-sdk-permission-body"></div>');
      const $permissionTool = $('<div class="claude-agent-sdk-permission-tool"></div>');
      const $permissionInput = $('<pre class="claude-agent-sdk-permission-input"></pre>');
      const $permissionActions = $('<div class="claude-agent-sdk-permission-actions"></div>');
      const $permissionAllowOnce = $('<button type="button" class="button button--primary">Allow</button>');
      const $permissionDeny = $('<button type="button" class="button">Deny</button>');

      $permissionActions.append($permissionAllowOnce).append($permissionDeny);
      $permissionBody.append($permissionTool).append($permissionInput);
      $permissionPanel.append($permissionTitle).append($permissionBody).append($permissionActions);
      $permissionModal.append($permissionPanel);
      $('body').append($permissionModal);
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

      $permissionAllowOnce.on('click', function () {
        submitPermissionDecision('allow');
      });
      $permissionDeny.on('click', function () {
        submitPermissionDecision('deny');
      });

      if (debugMode === 'client' || debugMode === 'terminal') {
        if (debugMode === 'terminal') {
          const $layout = $('<div class="claude-agent-sdk-terminal-layout"></div>');
          const $sidebar = $('<div class="claude-agent-sdk-terminal-sidebar"></div>');
          const $main = $('<div class="claude-agent-sdk-terminal-main"></div>');

          const $optionsBasic = $form.find('[data-drupal-selector="edit-options-basic"]').closest('details');
          const $optionsAdvanced = $form.find('[data-drupal-selector="edit-options-advanced"]').closest('details');
          const $callbacks = $form.find('[data-drupal-selector="edit-callbacks"]').closest('details');

          $sidebar.append($optionsBasic, $optionsAdvanced, $callbacks);

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
                    handleSseEvent(data, $output, null);
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
                    handleSseEvent(data, null, 'terminal');
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

      function handleSseEvent(data, $outputArea, outputMode) {
        // Handle sidecar SSE event format.
        if (data.type === 'query_start') {
          queryId = data.queryId;
          if (outputMode === 'terminal') {
            setStatus('connected');
          }
        }
        if (data.type === 'assistant') {
          const text = extractAssistantText(data);
          if (text) {
            if (outputMode === 'terminal') {
              appendTerminalLine(text, 'assistant');
            } else if ($outputArea) {
              $outputArea.val($outputArea.val() + text + "\n");
            }
          }
        }
        if (data.type === 'result' && data.subtype === 'success') {
          if (data.result) {
            if (outputMode === 'terminal') {
              appendTerminalLine(data.result, 'assistant');
            } else if ($outputArea) {
              $outputArea.val($outputArea.val() + data.result + "\n");
            }
          }
          if (data.session_id) {
            chatSessionId = data.session_id;
            $chatSession.text('Session: ' + chatSessionId);
          }
        }
        if (data.type === 'error') {
          const msg = 'ERROR: ' + (data.message || 'Unknown');
          if (outputMode === 'terminal') {
            appendTerminalLine(msg, 'error');
          } else if ($outputArea) {
            $outputArea.val($outputArea.val() + msg + "\n");
          }
        }
        if (data.type === 'permission_request') {
          queuePermissionRequest({
            query_id: data.queryId,
            request_id: data.requestId,
            tool_name: data.toolName,
            input: data.input
          });
        }
        // Track session_id from any event that has it.
        if (data.session_id && data.session_id !== 'default') {
          chatSessionId = data.session_id;
          $chatSession.text('Session: ' + chatSessionId);
        }
      }

      function buildPayload($form) {
        const prompt = $form.find('[name="prompt"]').val();
        const options = buildOptionsFromForm($form);
        const canUseTool = $form.find('[name="can_use_tool"]').val();

        return {
          prompt: String(prompt || ''),
          mode: debugMode,
          runtime_id: runtimeId,
          options: options,
          debug_callbacks: {
            can_use_tool: canUseTool
          }
        };
      }

      function buildChatPayload($form, prompt, sessionId) {
        const options = buildOptionsFromForm($form);
        options.continueConversation = !!sessionId;
        options.includePartialMessages = true;
        if (sessionId) {
          options.resume = sessionId;
        }

        const canUseTool = $form.find('[name="can_use_tool"]').val();

        return {
          prompt: String(prompt),
          mode: debugMode,
          runtime_id: runtimeId,
          options: options,
          session_id: sessionId || null,
          debug_callbacks: {
            can_use_tool: canUseTool
          }
        };
      }

      function buildOptionsFromForm($form) {
        const options = {};

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
          options.additionalDirectories = addDirs;
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

        const user = String($form.find('[name="option_user"]').val() || '').trim();
        if (user) {
          options.user = user;
        }

        const env = parseJson($form.find('[name="option_env"]').val());
        if (env) {
          options.env = env;
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

      function queuePermissionRequest(request) {
        if (!request || !request.request_id) {
          return;
        }
        permissionQueue.push(request);
        appendTerminalLine('Permission request for tool: ' + String(request.tool_name || 'unknown'), 'system');
        if (!activePermissionRequest) {
          showNextPermissionRequest();
        }
      }

      function showNextPermissionRequest() {
        if (activePermissionRequest || !permissionQueue.length) {
          return;
        }

        activePermissionRequest = permissionQueue.shift();
        const toolName = String(activePermissionRequest.tool_name || 'unknown');
        const input = activePermissionRequest.input && typeof activePermissionRequest.input === 'object'
          ? JSON.stringify(activePermissionRequest.input, null, 2)
          : String(activePermissionRequest.input || '{}');

        $permissionTool.text('Tool: ' + toolName);
        $permissionInput.text(input);
        $permissionModal.show();
        setStatus('Awaiting permission');
      }

      function submitPermissionDecision(decision) {
        if (!activePermissionRequest) {
          return;
        }
        if (!permissionResponseUrl) {
          appendTerminalLine('ERROR: Permission response URL is not configured.', 'error');
          return;
        }

        fetch(permissionResponseUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            queryId: activePermissionRequest.query_id,
            requestId: activePermissionRequest.request_id,
            behavior: decision
          }),
        }).then(function (response) {
          if (!response.ok) {
            return response.text().then(function (text) {
              throw new Error(text || ('HTTP ' + response.status));
            });
          }
          appendTerminalLine('Permission decision submitted: ' + decision, 'system');
        }).catch(function (error) {
          appendTerminalLine('ERROR: Failed to submit permission decision: ' + error.message, 'error');
        }).finally(function () {
          activePermissionRequest = null;
          $permissionModal.hide();
          setStatus('Running');
          showNextPermissionRequest();
        });
      }

      function extractAssistantText(data) {
        if (!data || data.type !== 'assistant') {
          return '';
        }
        const content = data.message && data.message.content ? data.message.content : [];
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
    }
  };
})(Drupal, jQuery, once, drupalSettings);
