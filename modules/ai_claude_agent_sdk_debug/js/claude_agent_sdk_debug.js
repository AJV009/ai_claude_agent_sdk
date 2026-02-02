(function (Drupal, $) {
  'use strict';

  Drupal.behaviors.claudeAgentSdkDebug = {
    attach: function (context) {
      const $form = $(context).find('form#claude-agent-sdk-debug-form').once('claude-agent-sdk-debug');
      if ($form.length === 0) {
        return;
      }

      const $streamButton = $('<button type="button" class="button">Stream via SSE</button>');
      const $output = $('<textarea readonly rows="12" style="width:100%;"></textarea>');

      $form.find('div.form-actions').append($streamButton);
      $form.append($('<div class="claude-agent-sdk-debug-stream"></div>').append('<h3>Streaming Output</h3>').append($output));

      $streamButton.on('click', function () {
        $output.val('');

        const payload = buildPayload($form);
        fetch('/claudeagentsdkdebug/stream', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        }).then(function (response) {
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
        });
      });

      function buildPayload($form) {
        const inputType = $form.find('[name="input_type"]').val();
        const prompt = $form.find('[name="prompt"]').val();
        const optionsText = $form.find('[name="options"]').val();
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

        let options = {};
        if (optionsText) {
          try {
            options = JSON.parse(optionsText);
          } catch (e) {
            options = {};
          }
        }

        let messages = [];
        if (inputType === 'jsonl') {
          messages = parseJsonl(prompt);
        } else if (inputType === 'deepchat') {
          messages = convertDeepChat(prompt);
        } else {
          messages = [{
            type: 'user',
            message: { role: 'user', content: String(prompt) },
            parent_tool_use_id: null,
            session_id: 'default',
          }];
        }

        const control = buildControl(controlAction, controlMode, controlModel, controlUserMessageId);

        return {
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
          return {
            type: msg.role || 'user',
            message: { role: msg.role || 'user', content: msg.text || '' },
            parent_tool_use_id: null,
            session_id: 'default',
          };
        });
      }

      function buildControl(action, mode, model, userMessageId) {
        if (!action || action === 'none') {
          return null;
        }
        const control = { action: action };
        if (action === 'set_permission_mode') {
          control.mode = mode || 'auto';
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
})(Drupal, jQuery);
