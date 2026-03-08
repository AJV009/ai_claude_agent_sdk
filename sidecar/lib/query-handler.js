import crypto from 'crypto';
import { query } from '@anthropic-ai/claude-agent-sdk';
import { mapProfileToOptions } from './profile-mapper.js';

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
        })}\n\n`);
      }
      return promise;
    };
  }

  // Background mode: register, send queryId, then run detached.
  if (isBackground) {
    sdkOptions.permissionMode = 'plan';
    const metadata = {
      skillId: body.skillId || null,
      initiatorUid: body.initiatorUid || null,
      taskId: body.taskId || null,
    };
    queryRegistry.register(queryId, abortController, metadata);

    res.write(`data: ${JSON.stringify({ type: 'query_start', queryId })}\n\n`);
    res.write('data: [DONE]\n\n');
    res.end();

    // Run query in detached async IIFE.
    (async () => {
      try {
        const conversation = query({
          prompt: prompt || '',
          options: sdkOptions,
        });

        for await (const message of conversation) {
          // Capture session_id from messages.
          if (message.session_id) {
            queryRegistry.update(queryId, { sessionId: message.session_id });
          }
          // Capture result.
          if (message.type === 'result' && message.subtype === 'success') {
            queryRegistry.update(queryId, { result: message.result || '' });
          }
        }
        queryRegistry.update(queryId, {
          status: 'completed',
          completedAt: new Date().toISOString(),
        });
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
