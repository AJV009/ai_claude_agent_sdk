# AI Claude Agent SDK Agents Integration

This submodule wires Claude Agent SDK debug/query workflows to Drupal AI agent tooling.

## Required packages

Install Tool API alpha + MCP Server:

```bash
ddev composer require 'drupal/tool:1.0.0-alpha9' 'drupal/mcp_server:1.x-dev@dev' 'drupal/simple_oauth:^6' 'drupal/simple_oauth_21:1.13.0' -W
```

## Enable

```bash
ddev drush -y pm:en ai_claude_agent_sdk_agents_integration mcp_server simple_oauth simple_oauth_21 simple_oauth_server_metadata
```

Enabling this submodule also enables these module dependencies:

- `ai_agents`
- `tool`
- `tool_ai_connector`

## Query-mode testing

After enabling, use:

- `/admin/config/ai/claude-agent-sdk/debug/query`
- `/admin/config/ai/claude-agent-sdk/debug/session-query`

to test Claude SDK query flows with your Drupal tool setup.

## MCP server config for Session Query

In **Advanced options** -> **MCP servers (JSON)**, use:

```json
{
  "drupal": {
    "transport": "http",
    "url": "http://localhost/_mcp"
  }
}
```

Notes:

- `claude` runs inside the DDEV web container, so `http://localhost/_mcp` resolves to Drupal in the same container.
- `mcp_server` endpoint and `tools/list` + `tools/call` are reachable in this setup.

You can also register the MCP server in Claude's local project config:

```bash
ddev exec "claude mcp add --transport http -s local drupal http://localhost/_mcp"
```

### Current runtime limitation (important)

With Claude Code `2.1.39` in SDK query/client flows, external MCP servers connect successfully but MCP tools are not exposed to the model as callable tools (only MCP resource helper tools are visible).

This means:

- MCP transport is working.
- Drupal MCP tools are discoverable via direct MCP JSON-RPC (`tools/list`, `tools/call`).
- Claude SDK debug/query currently cannot reliably invoke Drupal MCP tools from the model in this runtime.

## CLI Tool API bridge (working path)

This submodule also provides Drush bridge commands that Claude can call via `Bash`.

List tools:

```bash
ddev drush ai-claude-agent-sdk:tool-list
```

Run a no-input tool:

```bash
ddev drush ai-claude-agent-sdk:tool-run tool_api:entity_type_list
```

Run a tool with JSON args:

```bash
ddev drush ai-claude-agent-sdk:tool-run tool_api:entity_load_by_id --args='{"entity_type_id":"node","entity_id":1}'
```

Notes:

- Both IDs are accepted: `entity_type_list` and `tool_api:entity_type_list`.
- Output is normalized JSON for machine parsing in debug flows.
- In Claude SDK debug query mode inside the web container, use `drush ...` (not `ddev drush ...`).
