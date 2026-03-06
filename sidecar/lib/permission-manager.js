import crypto from 'crypto';

/**
 * Factory that creates a permission manager for handling interactive
 * permission requests during SDK query() calls.
 *
 * Two-level Map: queryId → requestId → { resolve, reject, timer }.
 */
export function createPermissionManager() {
  /** @type {Map<string, Map<string, {resolve: Function, reject: Function, timer: NodeJS.Timeout}>>} */
  const pending = new Map();

  return {
    /**
     * Create a pending permission request.
     *
     * @param {string} queryId
     * @param {string} toolName
     * @param {object} input
     * @param {object} opts - SDK canUseTool options (decisionReason, suggestions, toolUseID).
     * @param {number} timeoutMs - Auto-deny after this many milliseconds.
     * @returns {{ requestId: string, promise: Promise<object> }}
     */
    createRequest(queryId, toolName, input, opts, timeoutMs = 120000) {
      if (!pending.has(queryId)) {
        pending.set(queryId, new Map());
      }
      const queryMap = pending.get(queryId);
      const requestId = crypto.randomUUID();

      const promise = new Promise((resolve, reject) => {
        const timer = setTimeout(() => {
          queryMap.delete(requestId);
          if (queryMap.size === 0) pending.delete(queryId);
          resolve({ behavior: 'deny', message: 'Permission request timed out' });
        }, timeoutMs);

        queryMap.set(requestId, { resolve, reject, timer });
      });

      return { requestId, promise };
    },

    /**
     * Resolve a pending permission request with a user decision.
     *
     * @param {string} queryId
     * @param {string} requestId
     * @param {{ behavior: 'allow'|'deny', message?: string }} decision
     * @returns {boolean} True if the request was found and resolved.
     */
    resolveRequest(queryId, requestId, decision) {
      const queryMap = pending.get(queryId);
      if (!queryMap) return false;

      const entry = queryMap.get(requestId);
      if (!entry) return false;

      clearTimeout(entry.timer);
      queryMap.delete(requestId);
      if (queryMap.size === 0) pending.delete(queryId);

      entry.resolve(decision);
      return true;
    },

    /**
     * Clean up all pending requests for a query (e.g. on client disconnect).
     *
     * @param {string} queryId
     */
    cleanup(queryId) {
      const queryMap = pending.get(queryId);
      if (!queryMap) return;

      for (const [, entry] of queryMap) {
        clearTimeout(entry.timer);
        const err = new Error('Query aborted');
        err.name = 'AbortError';
        entry.reject(err);
      }
      pending.delete(queryId);
    },
  };
}
