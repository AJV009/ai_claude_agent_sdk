'use strict';

const { listSessions, getSessionMessages } = require('@anthropic-ai/claude-agent-sdk');

async function getSessions(projectDir, limit = 50) {
  try {
    const sessions = await listSessions({ dir: projectDir, limit });
    return {
      sessions: sessions.sort((a, b) => b.lastModified - a.lastModified),
      total: sessions.length,
    };
  } catch (err) {
    return { sessions: [], total: 0 };
  }
}

async function getSession(sessionId, projectDir, messageLimit = 20) {
  const { sessions } = await getSessions(projectDir, 999);
  const session = sessions.find(s => s.sessionId === sessionId);
  if (!session) return null;

  try {
    const messages = await getSessionMessages(sessionId, {
      dir: projectDir,
      limit: messageLimit,
    });
    return { session, messages };
  } catch {
    return { session, messages: [] };
  }
}

function getActiveSessions(ptyManager) {
  return ptyManager.list().map(p => ({
    connectionId: p.id,
    pid: p.pid,
    sessionId: p.sessionId,
    createdAt: p.createdAt,
    command: p.command,
  }));
}

module.exports = { getSessions, getSession, getActiveSessions };
