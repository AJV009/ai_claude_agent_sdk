import fs from 'fs';
import path from 'path';
import os from 'os';

/**
 * Translate an agent profile object to Claude CLI args.
 *
 * @param {object} profile - Agent profile from toSidecarFormat().
 * @param {object} [options] - Additional options.
 * @param {string} [options.resume] - Session ID to resume.
 * @param {string} [options.prompt] - Initial prompt (added last).
 * @returns {{ args: string[], cleanupFiles: string[] }}
 */
export function buildArgs(profile = {}, options = {}) {
  const args = [];
  const cleanupFiles = [];

  if (profile.model) {
    args.push('--model', profile.model);
  }

  if (profile.permission_mode) {
    args.push('--permission-mode', profile.permission_mode);
  }

  if (profile.max_turns) {
    args.push('--max-turns', String(profile.max_turns));
  }

  if (profile.system_prompt) {
    args.push('--system-prompt', profile.system_prompt);
  }

  if (Array.isArray(profile.allowed_tools)) {
    for (const tool of profile.allowed_tools) {
      args.push('--allowedTools', tool);
    }
  }

  if (Array.isArray(profile.denied_tools)) {
    for (const tool of profile.denied_tools) {
      args.push('--disallowedTools', tool);
    }
  }

  if (profile.mcp_servers && Object.keys(profile.mcp_servers).length > 0) {
    const mcpConfig = { mcpServers: profile.mcp_servers };
    const tmpFile = path.join(os.tmpdir(), `claude-mcp-${Date.now()}-${Math.random().toString(36).slice(2)}.json`);
    fs.writeFileSync(tmpFile, JSON.stringify(mcpConfig, null, 2));
    args.push('--mcp-config', tmpFile);
    cleanupFiles.push(tmpFile);
  }

  if (Array.isArray(profile.extra_args)) {
    args.push(...profile.extra_args);
  }

  if (options.resume) {
    args.push('--resume', options.resume);
  }

  if (options.prompt) {
    args.push('-p', options.prompt);
  }

  return { args, cleanupFiles };
}
