// Clean Claude Code session markers from our own process env so spawned
// PTYs don't inherit them and trigger nested-session detection.
delete process.env.CLAUDECODE;
for (const key of Object.keys(process.env)) {
  if (key.startsWith('CLAUDE_CODE_')) {
    delete process.env[key];
  }
}

import http from 'http';
import crypto from 'crypto';
import fs from 'fs';
import { WebSocketServer } from 'ws';
import { PtyManager } from './lib/pty-manager.js';
import { buildArgs } from './lib/cli-builder.js';
import { getSessions, getSession, getActiveSessions } from './lib/sessions.js';
import { discoverSkills } from './lib/skills.js';
import { handleQuery } from './lib/query-handler.js';
import { createPermissionManager } from './lib/permission-manager.js';
import { createQueryRegistry } from './lib/query-registry.js';

const PORT = parseInt(process.env.PORT, 10) || 3000;
const CLAUDE_COMMAND = process.env.CLAUDE_COMMAND || 'claude';
const MAX_PTYS = parseInt(process.env.MAX_PTYS, 10) || 5;
const ALLOWED_ORIGINS = process.env.ALLOWED_ORIGINS || '*';
const WORKING_DIR = process.env.WORKING_DIR || '/var/www/html';
const VERSION = '0.1.0';

const ptyManager = new PtyManager(MAX_PTYS);

// Shared counter for SDK query() calls (bounded together with PTYs by MAX_PTYS).
const queryCounters = { queryCount: 0, maxConcurrent: MAX_PTYS };

const permissionManager = createPermissionManager();
const queryRegistry = createQueryRegistry();

// --- HTTP server ---

const server = http.createServer((req, res) => {
  const origin = req.headers.origin || '*';
  const allowedOrigin = ALLOWED_ORIGINS === '*' ? '*' : (ALLOWED_ORIGINS.split(',').includes(origin) ? origin : '');

  if (allowedOrigin) {
    res.setHeader('Access-Control-Allow-Origin', allowedOrigin);
    res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  }

  if (req.method === 'OPTIONS') {
    res.writeHead(204);
    res.end();
    return;
  }

  if (req.url === '/health' && req.method === 'GET') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ status: 'ok', activePTYs: ptyManager.getCount(), activeQueries: queryCounters.queryCount, version: VERSION }));
    return;
  }

  const url = new URL(req.url, `http://${req.headers.host}`);
  const pathname = url.pathname;

  if (pathname === '/api/skills' && req.method === 'GET') {
    const skills = discoverSkills(WORKING_DIR);
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ skills }));
    return;
  }

  if (pathname === '/api/sessions' && req.method === 'GET') {
    const limit = parseInt(url.searchParams.get('limit'), 10) || 50;
    const dir = url.searchParams.get('dir') || WORKING_DIR;
    getSessions(dir, limit).then((result) => {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(result));
    });
    return;
  }

  if (pathname === '/api/sessions/active' && req.method === 'GET') {
    const active = getActiveSessions(ptyManager);
    // Merge running background queries into the active list.
    const bgQueries = queryRegistry.list()
      .filter(q => q.status === 'running')
      .map(q => ({
        sessionId: q.sessionId || q.queryId,
        queryId: q.queryId,
        type: 'background_query',
        status: q.status,
        startedAt: q.startedAt,
        skillId: q.skillId,
      }));
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ active: [...bgQueries, ...active] }));
    return;
  }

  if (pathname.startsWith('/api/sessions/') && req.method === 'GET') {
    const sessionId = pathname.slice('/api/sessions/'.length);
    const limit = parseInt(url.searchParams.get('limit'), 10) || 20;
    getSession(sessionId, WORKING_DIR, limit).then((result) => {
      if (!result) {
        res.writeHead(404, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'Session not found' }));
        return;
      }
      const active = getActiveSessions(ptyManager);
      const isActive = active.some(a => a.sessionId === sessionId);
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ ...result, isActive }));
    });
    return;
  }

  if (pathname === '/api/query' && req.method !== 'POST') {
    res.writeHead(405, { 'Content-Type': 'application/json', 'Allow': 'POST' });
    res.end(JSON.stringify({ error: 'Method not allowed' }));
    return;
  }

  if (pathname === '/api/query' && req.method === 'POST') {
    let body = '';
    req.on('data', (chunk) => { body += chunk; });
    req.on('end', () => {
      let parsed;
      try {
        parsed = JSON.parse(body);
      } catch (_) {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'Invalid JSON body' }));
        return;
      }

      res.writeHead(200, {
        'Content-Type': 'text/event-stream',
        'Cache-Control': 'no-cache',
        'Connection': 'keep-alive',
        'X-Accel-Buffering': 'no',
      });

      handleQuery(req, res, parsed, queryCounters, permissionManager, queryRegistry);
    });
    return;
  }

  if (pathname === '/api/query/permission-response' && req.method !== 'POST') {
    res.writeHead(405, { 'Content-Type': 'application/json', 'Allow': 'POST' });
    res.end(JSON.stringify({ error: 'Method not allowed' }));
    return;
  }

  if (pathname === '/api/query/permission-response' && req.method === 'POST') {
    let body = '';
    req.on('data', (chunk) => { body += chunk; });
    req.on('end', () => {
      let parsed;
      try {
        parsed = JSON.parse(body);
      } catch (_) {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'Invalid JSON body' }));
        return;
      }

      const { queryId, requestId, behavior, message } = parsed;
      if (!queryId || !requestId || !behavior) {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'queryId, requestId, and behavior are required' }));
        return;
      }

      if (behavior !== 'allow' && behavior !== 'deny') {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: "behavior must be 'allow' or 'deny'" }));
        return;
      }

      const resolved = permissionManager.resolveRequest(queryId, requestId, { behavior, message });
      if (resolved) {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: true }));
      } else {
        res.writeHead(404, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'Permission request not found or already resolved' }));
      }
    });
    return;
  }

  if (pathname === '/api/queries' && req.method === 'GET') {
    const queries = queryRegistry.list();
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ queries }));
    return;
  }

  const abortMatch = pathname.match(/^\/api\/queries\/([^/]+)\/abort$/);
  if (abortMatch && req.method === 'POST') {
    const queryId = abortMatch[1];
    const aborted = queryRegistry.abort(queryId);
    if (aborted) {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ ok: true }));
    } else {
      res.writeHead(404, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: 'Query not found or not running' }));
    }
    return;
  }

  if (pathname.startsWith('/api/')) {
    res.writeHead(404, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ error: 'Not found' }));
    return;
  }

  res.writeHead(404, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify({ error: 'Not found' }));
});

// --- WebSocket server ---

const wss = new WebSocketServer({ server, path: '/ws' });

wss.on('connection', (ws) => {
  const connId = crypto.randomUUID();
  let ptyId = null;
  let cleanupFiles = [];

  function sendJson(obj) {
    if (ws.readyState === ws.OPEN) {
      ws.send(JSON.stringify(obj));
    }
  }

  function spawnPty(profile = {}, resume = null, prompt = null, env = null) {
    if (ptyId) {
      sendJson({ type: 'error', message: 'PTY already spawned for this connection' });
      return;
    }

    // Don't pass prompt to buildArgs (which would add -p for non-interactive mode).
    // Instead, queue it and send via PTY stdin after REPL prompt detection.
    const { args, cleanupFiles: files } = buildArgs(profile, { resume });
    cleanupFiles = files;

    let pendingPrompt = prompt || null;

    const cwd = profile.working_directory || process.env.WORKING_DIR || '/var/www/html';
    const entry = ptyManager.spawn(connId, CLAUDE_COMMAND, args, {
      cwd,
      env: env || undefined,
      sessionId: resume || null,
      onExit: (exitCode) => {
        for (const f of cleanupFiles) {
          try { fs.unlinkSync(f); } catch (_) {}
        }
        cleanupFiles = [];
        sendJson({ type: 'exit', code: exitCode });
        ws.close();
      },
    });

    if (!entry) {
      sendJson({ type: 'error', message: `Max PTY limit reached (${MAX_PTYS})` });
      ws.close();
      return;
    }

    ptyId = connId;
    sendJson({ type: 'spawned', pid: entry.pty.pid, sessionId: entry.sessionId });

    entry.pty.onData((data) => {
      if (ws.readyState === ws.OPEN) {
        ws.send(data);
      }
      // Detect REPL prompt and send queued initial prompt.
      if (pendingPrompt && (data.includes('\u276f') || data.includes('> '))) {
        const text = pendingPrompt;
        pendingPrompt = null;
        setTimeout(() => {
          ptyManager.write(ptyId, text + '\r');
        }, 100);
      }
    });
  }

  // Auto-spawn timeout: if no spawn message within 5s, spawn bare claude.
  const spawnTimeout = setTimeout(() => {
    if (!ptyId) {
      spawnPty();
    }
  }, 5000);

  ws.on('message', (msg) => {
    const str = msg.toString();

    // Try to parse as JSON control message.
    try {
      const json = JSON.parse(str);

      if (json.type === 'spawn') {
        clearTimeout(spawnTimeout);
        spawnPty(json.profile || {}, json.resume || null, json.prompt || null, json.env || null);
        return;
      }

      if (json.type === 'resize' && json.cols && json.rows) {
        if (ptyId) {
          ptyManager.resize(ptyId, json.cols, json.rows);
        }
        return;
      }
    } catch (_) {
      // Not JSON — treat as raw PTY input.
    }

    // Forward raw input to PTY.
    if (ptyId) {
      ptyManager.write(ptyId, str);
    }
  });

  ws.on('close', () => {
    clearTimeout(spawnTimeout);
    if (ptyId) {
      ptyManager.kill(ptyId);
      for (const f of cleanupFiles) {
        try { fs.unlinkSync(f); } catch (_) {}
      }
      cleanupFiles = [];
      ptyId = null;
    }
  });
});

// --- Graceful shutdown ---

function shutdown() {
  console.log('Shutting down...');
  ptyManager.killAll();
  for (const client of wss.clients) {
    client.close();
  }
  server.close(() => {
    console.log('Server closed.');
    process.exit(0);
  });
  // Force exit after 5s if connections linger.
  setTimeout(() => process.exit(1), 5000).unref();
}

process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);

// --- Start ---

server.listen(PORT, () => {
  console.log(`Claude TUI sidecar v${VERSION} listening on port ${PORT}`);
});
