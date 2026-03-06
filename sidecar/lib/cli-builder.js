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

  if (profile.mcp_servers && (Array.isArray(profile.mcp_servers) ? profile.mcp_servers.length > 0 : Object.keys(profile.mcp_servers).length > 0)) {
    // Transform array format [{name, transport, url}] to CLI config format {name: {type, url}}.
    let mcpServers;
    if (Array.isArray(profile.mcp_servers)) {
      mcpServers = {};
      for (const server of profile.mcp_servers) {
        if (server.name && server.url) {
          mcpServers[server.name] = { type: server.transport || 'http', url: server.url };
          if (server.headers) mcpServers[server.name].headers = server.headers;
        }
      }
    } else {
      mcpServers = profile.mcp_servers;
    }

    if (Object.keys(mcpServers).length > 0) {
      const mcpConfig = { mcpServers };
      const tmpFile = path.join(os.tmpdir(), `claude-mcp-${Date.now()}-${Math.random().toString(36).slice(2)}.json`);
      fs.writeFileSync(tmpFile, JSON.stringify(mcpConfig, null, 2));
      args.push('--mcp-config', tmpFile);
      cleanupFiles.push(tmpFile);
    }
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
