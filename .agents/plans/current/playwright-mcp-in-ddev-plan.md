# Playwright MCP in DDEV (Deferred)

Status: Deferred (do not implement yet)
Date: 2026-02-12

## Goal

Enable Playwright MCP inside DDEV so Claude Code can run browser automation within the container boundary.

## Planned Steps

1. Add Playwright MCP runtime packages to DDEV web image.
2. Install browser dependencies and Chromium in the container.
3. Wire MCP config into the in-container Claude home config.
4. Validate Claude can see and call the MCP server.
5. Add smoke tests for opening a local DDEV URL and reading page content.
6. Document troubleshooting for TLS, container rebuilds, and permissions.

## Acceptance Criteria

- Claude CLI and Playwright MCP are available in the web container.
- MCP calls work from inside DDEV.
- A documented smoke test passes against a local DDEV site URL.

## Notes

- Keep this deferred until explicitly requested.
- When execution starts, move this plan to active work and create an implementation checklist.
