'use strict';

const pty = require('node-pty');

class PtyManager {
  constructor(maxPtys = 5) {
    this.maxPtys = maxPtys;
    /** @type {Map<string, { id: string, pty: object, createdAt: Date, sessionId: string|null, command: string, onExit: function|null }>} */
    this.entries = new Map();
  }

  /**
   * Spawn a new PTY process.
   *
   * @param {string} id - Unique identifier for this PTY.
   * @param {string} command - Command to run.
   * @param {string[]} args - Command arguments.
   * @param {object} [options] - Spawn options.
   * @param {string} [options.cwd] - Working directory.
   * @param {object} [options.env] - Environment variables.
   * @param {number} [options.cols] - Initial columns.
   * @param {number} [options.rows] - Initial rows.
   * @param {string|null} [options.sessionId] - Associated session ID.
   * @param {function} [options.onExit] - Callback on PTY exit.
   * @returns {object|null} Entry object or null if limit reached.
   */
  spawn(id, command, args, options = {}) {
    if (this.entries.size >= this.maxPtys) {
      return null;
    }

    const proc = pty.spawn(command, args, {
      name: 'xterm-256color',
      cols: options.cols || 80,
      rows: options.rows || 24,
      cwd: options.cwd || process.env.WORKING_DIR || '/var/www/html',
      env: (() => {
        const env = { ...process.env, ...options.env, TERM: 'xterm-256color' };
        // Remove Claude Code session markers to avoid nested-session detection.
        delete env.CLAUDECODE;
        for (const key of Object.keys(env)) {
          if (key.startsWith('CLAUDE_CODE_')) {
            delete env[key];
          }
        }
        return env;
      })(),
    });

    const entry = {
      id,
      pty: proc,
      createdAt: new Date(),
      sessionId: options.sessionId || null,
      command,
      onExit: options.onExit || null,
    };

    this.entries.set(id, entry);

    proc.onExit(({ exitCode }) => {
      this.entries.delete(id);
      if (entry.onExit) {
        entry.onExit(exitCode);
      }
    });

    return entry;
  }

  resize(id, cols, rows) {
    const entry = this.entries.get(id);
    if (entry) {
      entry.pty.resize(cols, rows);
    }
  }

  write(id, data) {
    const entry = this.entries.get(id);
    if (entry) {
      entry.pty.write(data);
    }
  }

  kill(id) {
    const entry = this.entries.get(id);
    if (entry) {
      entry.pty.kill();
      this.entries.delete(id);
    }
  }

  list() {
    return Array.from(this.entries.values()).map((e) => ({
      id: e.id,
      command: e.command,
      createdAt: e.createdAt,
      sessionId: e.sessionId,
      pid: e.pty.pid,
    }));
  }

  getCount() {
    return this.entries.size;
  }

  killAll() {
    for (const [id, entry] of this.entries) {
      entry.pty.kill();
    }
    this.entries.clear();
  }
}

module.exports = { PtyManager };
