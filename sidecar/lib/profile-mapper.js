/**
 * Maps AgentProfile::toSidecarFormat() output to JS SDK Options type.
 *
 * @param {object} profile - Profile from toSidecarFormat().
 * @returns {object} SDK Options object.
 */
export function mapProfileToOptions(profile = {}) {
  const options = {};

  if (profile.system_prompt) {
    options.systemPrompt = profile.system_prompt;
  }

  if (profile.model) {
    options.model = profile.model;
  }

  if (profile.permission_mode) {
    options.permissionMode = profile.permission_mode;
  }

  if (profile.max_turns) {
    options.maxTurns = profile.max_turns;
  }

  if (Array.isArray(profile.allowed_tools) && profile.allowed_tools.length > 0) {
    options.allowedTools = profile.allowed_tools;
  }

  if (Array.isArray(profile.denied_tools) && profile.denied_tools.length > 0) {
    options.disallowedTools = profile.denied_tools;
  }

  if (profile.working_directory) {
    options.cwd = profile.working_directory;
  }

  if (Array.isArray(profile.allowed_directories) && profile.allowed_directories.length > 0) {
    options.additionalDirectories = profile.allowed_directories;
  }

  if (profile.sandbox) {
    options.sandbox = { enabled: true };
  }

  if (profile.mcp_servers && Array.isArray(profile.mcp_servers)) {
    const mcpServers = {};
    for (const server of profile.mcp_servers) {
      if (server.name && server.url) {
        mcpServers[server.name] = {
          type: server.transport || 'http',
          url: server.url,
        };
        if (server.headers) {
          mcpServers[server.name].headers = server.headers;
        }
      }
    }
    if (Object.keys(mcpServers).length > 0) {
      options.mcpServers = mcpServers;
    }
  } else if (profile.mcp_servers && typeof profile.mcp_servers === 'object') {
    // Already in Record<string, config> format
    options.mcpServers = profile.mcp_servers;
  }

  return options;
}
