/**
 * In-memory registry for background query tracking.
 */

const PRUNE_AGE_MS = 30 * 60 * 1000; // 30 minutes.

export function createQueryRegistry() {
  /** @type {Map<string, object>} */
  const entries = new Map();

  function register(queryId, abortController, metadata = {}) {
    entries.set(queryId, {
      queryId,
      abortController,
      status: 'running',
      startedAt: new Date().toISOString(),
      completedAt: null,
      sessionId: null,
      result: null,
      error: null,
      skillId: metadata.skillId || null,
      initiatorUid: metadata.initiatorUid || null,
      taskId: metadata.taskId || null,
    });
  }

  function update(queryId, fields) {
    const entry = entries.get(queryId);
    if (entry) {
      Object.assign(entry, fields);
    }
  }

  function get(queryId) {
    return entries.get(queryId) || null;
  }

  function list() {
    // Prune completed entries older than 30 minutes.
    const cutoff = Date.now() - PRUNE_AGE_MS;
    for (const [id, entry] of entries) {
      if (entry.status !== 'running' && entry.completedAt) {
        if (new Date(entry.completedAt).getTime() < cutoff) {
          entries.delete(id);
        }
      }
    }
    return Array.from(entries.values());
  }

  function abort(queryId) {
    const entry = entries.get(queryId);
    if (entry && entry.status === 'running') {
      entry.abortController.abort();
      entry.status = 'aborted';
      entry.completedAt = new Date().toISOString();
      return true;
    }
    return false;
  }

  function cleanup() {
    entries.clear();
  }

  return { register, update, get, list, abort, cleanup };
}
