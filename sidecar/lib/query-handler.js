import { query } from '@anthropic-ai/claude-agent-sdk';
import { mapProfileToOptions } from './profile-mapper.js';

/**
 * Handle a /api/query request using the JS SDK's query() function.
 *
 * @param {object} req - HTTP request.
 * @param {object} res - HTTP response (SSE headers already set by caller).
 * @param {object} body - Parsed request body.
 * @param {object} counters - { queryCount, maxConcurrent } shared state.
 */
export async function handleQuery(req, res, body, counters) {
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

  const abortController = new AbortController();
  req.on('close', () => abortController.abort());

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

  // Inject env (ANTHROPIC_API_KEY etc.) from request.
  if (requestOptions.env) {
    sdkOptions.env = { ...process.env, ...requestOptions.env };
  }

  try {
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
