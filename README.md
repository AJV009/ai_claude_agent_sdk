# AI Claude Agent SDK

## Build Process Documentation

Use the full build guide here:

- `docs/BUILD_PROCESS.md`
- `docs/InitialSetup.md`

It documents:

- Live build process (rebuild-safe git checkout setup).
- Dev build process (git-based workflow for module + library with separate remotes).
- Optional `ai_console` integration via `ai_claude_agent_sdk_console` (not enabled by default).

## Rebuild-Safe Setup Commands

From the project root, use:

```bash
bash web/modules/contrib/ai_claude_agent_sdk/scripts/setup-claudeagentsdk-live.sh
```

or:

```bash
bash web/modules/contrib/ai_claude_agent_sdk/scripts/setup-claudeagentsdk-dev.sh
```

These commands bootstrap checkouts from remotes using module-owned scripts. They are designed to be rerun after rebuilds.

They also ensure `ai` + `key` are enabled and default SDK auth to `ANTHROPIC_API_KEY` from the DDEV environment.

## Local Development Setup Script

This module owns setup scripts:

- `scripts/setup-claudeagentsdk-dev.sh`
- `scripts/setup-claudeagentsdk-live.sh`

The DDEV commands are the primary rebuild-safe entrypoint and are safe to rerun.
