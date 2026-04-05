import crypto from 'crypto';
import { query } from '@anthropic-ai/claude-agent-sdk';
import { mapProfileToOptions } from './profile-mapper.js';
import { WebhookEmitter } from './webhook-emitter.js';

/**
 * Handle a /api/query request using the JS SDK's query() function.
 *
 * @param {object} req - HTTP request.
 * @param {object} res - HTTP response (SSE headers already set by caller).
 * @param {object} body - Parsed request body.
 * @param {object} counters - { queryCount, maxConcurrent } shared state.
 * @param {object} permissionManager - Permission manager instance.
 * @param {object} queryRegistry - Background query registry instance.
 */
export async function handleQuery(req, res, body, counters, permissionManager, queryRegistry) {
  const { prompt, options: requestOptions = {} } = body;

  if (!prompt && !requestOptions.resume) {
    writeSseError(res, 'prompt or options.resume is required');
    return;
  }

  if (counters.queryCount >= counters.maxConcurrent) {
    writeSseError(res, `Concurrent limit reached (${counters.maxConcurrent})`);
    return;
  }

  counters.queryCount++;

  const queryId = crypto.randomUUID();
  const isBackground = body.background === true;

  const abortController = new AbortController();
  if (!isBackground) {
    res.on('close', () => {
      abortController.abort();
      permissionManager.cleanup(queryId);
    });
  }

  // Build SDK options from profile fields passed through options.
  const profileOptions = mapProfileToOptions(requestOptions);

  // Merge explicit SDK options over profile-mapped ones.
  const sdkOptions = {
    ...profileOptions,
    abortController,
  };

  // Override with directly-passed SDK fields.
  if (requestOptions.resume) sdkOptions.resume = requestOptions.resume;
  if (requestOptions.maxBudgetUsd) sdkOptions.maxBudgetUsd = requestOptions.maxBudgetUsd;
  if (requestOptions.maxTurns) sdkOptions.maxTurns = requestOptions.maxTurns;
  if (requestOptions.cwd) sdkOptions.cwd = requestOptions.cwd;
  if (requestOptions.systemPrompt) sdkOptions.systemPrompt = requestOptions.systemPrompt;
  if (requestOptions.model) sdkOptions.model = requestOptions.model;
  if (requestOptions.permissionMode) sdkOptions.permissionMode = requestOptions.permissionMode;
  if (requestOptions.allowedTools) sdkOptions.allowedTools = requestOptions.allowedTools;
  if (requestOptions.disallowedTools) sdkOptions.disallowedTools = requestOptions.disallowedTools;
  if (requestOptions.mcpServers) sdkOptions.mcpServers = requestOptions.mcpServers;
  if (requestOptions.additionalDirectories) sdkOptions.additionalDirectories = requestOptions.additionalDirectories;
  if (requestOptions.sandbox) sdkOptions.sandbox = requestOptions.sandbox;
  if (requestOptions.continueConversation) sdkOptions.continueConversation = requestOptions.continueConversation;
  if (requestOptions.tools) sdkOptions.tools = requestOptions.tools;
  if (requestOptions.settings) sdkOptions.settings = requestOptions.settings;
  if (requestOptions.maxThinkingTokens) sdkOptions.maxThinkingTokens = requestOptions.maxThinkingTokens;
  if (requestOptions.user) sdkOptions.user = requestOptions.user;
  if (requestOptions.maxBudgetUsd) sdkOptions.maxBudgetUsd = requestOptions.maxBudgetUsd;
  if (requestOptions.includePartialMessages) sdkOptions.includePartialMessages = requestOptions.includePartialMessages;
  if (requestOptions.forkSession) sdkOptions.forkSession = requestOptions.forkSession;
  if (requestOptions.enableFileCheckpointing) sdkOptions.enableFileCheckpointing = requestOptions.enableFileCheckpointing;
  if (requestOptions.betas) sdkOptions.betas = requestOptions.betas;
  if (requestOptions.fallbackModel) sdkOptions.fallbackModel = requestOptions.fallbackModel;
  if (requestOptions.permissionPromptToolName) sdkOptions.permissionPromptToolName = requestOptions.permissionPromptToolName;
  if (requestOptions.agents) sdkOptions.agents = requestOptions.agents;
  if (requestOptions.settingSources) sdkOptions.settingSources = requestOptions.settingSources;
  if (requestOptions.plugins) sdkOptions.plugins = requestOptions.plugins;
  if (requestOptions.outputFormat) sdkOptions.outputFormat = requestOptions.outputFormat;
  if (requestOptions.maxBufferSize) sdkOptions.maxBufferSize = requestOptions.maxBufferSize;

  // Inject env (ANTHROPIC_API_KEY etc.) from request.
  if (requestOptions.env) {
    sdkOptions.env = { ...process.env, ...requestOptions.env };
  }

  // Wire up interactive permission callback when requested.
  if (requestOptions.interactivePermissions) {
    const timeoutMs = requestOptions.permissionTimeoutMs || 120000;
    sdkOptions.canUseTool = async (toolName, input, opts) => {
      const { requestId, promise } = permissionManager.createRequest(
        queryId, toolName, input, opts, timeoutMs,
      );
      if (!res.writableEnded) {
        res.write(`data: ${JSON.stringify({
          type: 'permission_request',
          queryId,
          requestId,
          toolName,
          input,
          decisionReason: opts?.decisionReason,
          suggestions: opts?.suggestions,
          toolUseID: opts?.toolUseID,
          agentId: opts?.agentID || '',
        })}\n\n`);
      }
      return promise;
    };
  }

  // Background mode: register, send queryId, then run detached.
  if (isBackground) {
    // 'default' permission mode requires TTY for interactive prompting —
    // incompatible with background execution. Override to 'plan' so that
    // canUseTool handles all permission decisions via webhook polling.
    // Other modes (acceptEdits, plan, bypassPermissions) work natively.
    if (!sdkOptions.permissionMode || sdkOptions.permissionMode === 'default') {
      sdkOptions.permissionMode = 'plan';
    }
    const metadata = {
      skillId: body.skillId || null,
      initiatorUid: body.initiatorUid || null,
      taskId: body.taskId || null,
    };
    queryRegistry.register(queryId, abortController, metadata);

    // Webhook callback support: create emitter if callbackUrl is provided.
    const emitter = body.callbackUrl
      ? new WebhookEmitter(body.callbackUrl, body.callbackToken || '', body.callbackEvents || [])
      : null;

    // Wire canUseTool for background mode via webhook.
    // Permission requests are emitted to Drupal, which stores them for
    // poll-based approval. The promise waits indefinitely (no timeout).
    if (emitter) {
      sdkOptions.canUseTool = async (toolName, input, opts) => {
        const { requestId, promise } = permissionManager.createRequest(
          queryId, toolName, input, opts, 0,
        );

        await emitter.emit({
          type: 'permission_request',
          queryId,
          requestId,
          toolName,
          input,
          decisionReason: opts?.decisionReason || '',
          suggestions: opts?.suggestions || [],
          blockedPath: opts?.blockedPath || '',
          toolUseID: opts?.toolUseID || '',
          agentId: opts?.agentID || '',
        });

        return promise;
      };
    }

    res.write(`data: ${JSON.stringify({ type: 'query_start', queryId })}\n\n`);
    res.write('data: [DONE]\n\n');
    res.end();

    // Run query in detached async IIFE.
    (async () => {
      try {
        // Emit query_start.
        if (emitter) {
          await emitter.emit({ type: 'query_start', queryId, sessionId: null });
        }

        const conversation = query({
          prompt: prompt || '',
          options: sdkOptions,
        });

        let sessionId = null;
        let totalTurns = 0;
        let lastAssistantText = '';

        for await (const message of conversation) {
          // Capture text from assistant messages as fallback response.
          // When tools are used, message.result may be empty, but
          // the assistant's text messages contain the actual response.
          if (message.type === 'assistant') {
            const content = message.message?.content;
            if (Array.isArray(content)) {
              for (const block of content) {
                if (block.type === 'text' && block.text) {
                  lastAssistantText = block.text;
                }
              }
            } else if (typeof message.message === 'string' && message.message) {
              lastAssistantText = message.message;
            }

            // Emit assistant text blocks for real-time polling display.
            if (emitter) {
              const textContent = message.message?.content;
              if (Array.isArray(textContent)) {
                for (const block of textContent) {
                  if (block.type === 'text' && block.text) {
                    await emitter.emit({
                      type: 'assistant_text',
                      queryId,
                      text: block.text,
                    });
                  }
                }
              }
            }
          }
          // Capture session_id from messages.
          if (message.session_id) {
            sessionId = message.session_id;
            queryRegistry.update(queryId, { sessionId: message.session_id });
          }

          // Emit tool_use events.
          // The SDK yields assistant messages with tool_use content blocks
          // in message.message.content (not message.subtype).
          if (message.type === 'assistant' && emitter) {
            const content = message.message?.content;
            if (Array.isArray(content)) {
              for (const block of content) {
                if (block.type === 'tool_use') {
                  await emitter.emit({
                    type: 'tool_use',
                    queryId,
                    toolName: block.name || 'unknown',
                    input: block.input || {},
                    output: null,
                    duration: null,
                  });
                }
              }
            }
          }

          // Emit tool_result events.
          // The SDK yields user messages with tool_result content blocks.
          if (message.type === 'user' && message.tool_use_result && emitter) {
            const content = message.message?.content;
            if (Array.isArray(content)) {
              for (const block of content) {
                if (block.type === 'tool_result') {
                  await emitter.emit({
                    type: 'tool_use',
                    queryId,
                    toolName: block.tool_use_id || 'unknown',
                    input: null,
                    output: typeof block.content === 'string' ? block.content.substring(0, 200) : '',
                    duration: null,
                  });
                }
              }
            }
          }

          // Capture result.
          if (message.type === 'result') {
            // Use message.result if available, otherwise fall back to
            // the last assistant text message captured during the loop.
            const resultText = message.result || lastAssistantText || '';

            queryRegistry.update(queryId, { result: resultText });

            if (emitter) {
              await emitter.emit({
                type: 'result',
                queryId,
                status: message.subtype || 'success',
                response: resultText,
                sessionId,
                totalTurns,
              });
            }
          }

          totalTurns++;
        }

        queryRegistry.update(queryId, {
          status: 'completed',
          completedAt: new Date().toISOString(),
        });

        // Drain any remaining webhook events.
        if (emitter) {
          await emitter.drain();
        }
      } catch (err) {
        const isAbort = err.name === 'AbortError'
          || (err.message && err.message.includes('aborted'));
        // Don't overwrite if registry.abort() already set status.
        const current = queryRegistry.get(queryId);
        if (current && current.status === 'aborted') {
          // Already marked aborted by registry.abort() — no-op.
        } else if (isAbort) {
          queryRegistry.update(queryId, {
            status: 'aborted',
            completedAt: new Date().toISOString(),
          });
        } else {
          queryRegistry.update(queryId, {
            status: 'error',
            error: err.message,
            completedAt: new Date().toISOString(),
          });
        }

        // Emit error event.
        if (emitter) {
          await emitter.emit({
            type: 'error',
            queryId,
            errorType: isAbort ? 'abort' : 'runtime',
            message: err.message,
          });
          await emitter.drain();
        }
      } finally {
        counters.queryCount--;
      }
    })();

    return;
  }

  try {
    // Emit query_start so the client knows the queryId for permission responses.
    res.write(`data: ${JSON.stringify({ type: 'query_start', queryId })}\n\n`);

    const conversation = query({
      prompt: prompt || '',
      options: sdkOptions,
    });

    for await (const message of conversation) {
      if (res.writableEnded) break;
      res.write(`data: ${JSON.stringify(message)}\n\n`);
    }
  } catch (err) {
    if (err.name === 'AbortError') {
      // Client disconnected — normal.
    } else if (!res.writableEnded) {
      res.write(`data: ${JSON.stringify({ type: 'error', message: err.message })}\n\n`);
    }
  } finally {
    permissionManager.cleanup(queryId);
    counters.queryCount--;
    if (!res.writableEnded) {
      res.write('data: [DONE]\n\n');
      res.end();
    }
  }
}

function writeSseError(res, message) {
  res.write(`data: ${JSON.stringify({ type: 'error', message })}\n\n`);
  res.write('data: [DONE]\n\n');
  res.end();
}
