# Claude SDK Permissions (Debug + Module Integration)

## Purpose
This project now supports non-interactive permission presets for Claude SDK debug flows, so tool/command execution can be pre-approved for testing without popup interaction.

## Debug UI usage
On the debug forms (`Terminal (Client)` and other debug pages), use:

- `Permission preset`: quick preset selector
- `Effective policy preview`: shows the resulting mode/tool/settings allowlist before execution

Available presets:

- `Manual (use fields below as-is)` (`none`)
- `Strict deny` (`strict_deny`)
- `Ask` (`ask`)
- `Allow bridge command only` (`bridge_only`)
- `Allow bridge command + read helpers (pwd/ls)` (`bridge_plus_read`)

## Programmatic integration for Drupal modules
When a module builds Claude SDK options payloads, it can set:

- `permissionPreset`: one of the values above
- `permissionMode`: optional explicit override
- `allowedTools` / `disallowedTools`: optional tool constraints
- `settings`: inline JSON or file path

If `permissionPreset` is `bridge_only` or `bridge_plus_read`, the helper will:

- ensure `Bash` is allowed
- default `permissionMode` to `default` if unset
- merge a permissions allowlist into inline `settings` JSON:
  - bridge command patterns for `drush ai-claude-agent-sdk:tool-run`
  - optional `ls` / `pwd` read helpers for `bridge_plus_read`

## Implementation hook
The normalization lives in:

- `modules/ai_claude_agent_sdk_debug/src/Support/PermissionPresetHelper.php`

Use `PermissionPresetHelper::apply($optionsData)` before constructing `ClaudeAgentOptions`.

## Important behavior
Natural-language chat text like "you have permission" is not treated as a permission grant. Permission decisions must be provided through SDK options/control flow.

## Legacy alias note
Legacy permission mode aliases (`auto`, `ask`, `deny`) are intentionally not active right now.

- Supported values are the current Claude CLI modes (`default`, `plan`, `dontAsk`, `acceptEdits`, `bypassPermissions`, `delegate`).
- The old alias mapping is kept in code comments in `PermissionPresetHelper` for possible future use, but it is disabled by design.
