# Terminal Session Mode Investigation Plan

Status: Completed (investigation only)  
Date: 2026-02-13

## Goal

Find why Debug Terminal mode stays on "Sending..." and verify whether session-based non-query mode works end-to-end.

## Hypotheses

1. Frontend JS issue in terminal stream handler prevents message/render completion.
2. Stream endpoint never finishes for chat sends because input is not explicitly closed.
3. PHP SDK `Client` path differs from `Query::query` behavior in session mode.
4. Session ID is not persisted/resumed correctly between requests.

## Investigation Steps

1. Static code audit:
- Debug JS handler for terminal send flow.
- Stream controller lifecycle (`connect`, `receiveMessages`, close/closeInput).
- Session tracking logic in stream responses.

2. Runtime endpoint test (outside browser):
- POST to `/admin/config/ai/claude-agent-sdk/debug/stream` equivalent payload.
- Confirm whether first events arrive and whether request terminates.

3. Direct SDK baseline tests (CLI/drush):
- `Query::query()` one-shot baseline.
- `Client` streaming mode with one user message and `continueConversation=true`.
- Verify if loop exits without explicit `closeInput()`.

4. Session continuity test:
- Capture `session_id` from first request.
- Send second request with `options.resume=<session_id>`.
- Verify continued context and tracker updates.

5. Optional integration test path:
- Add a non-streaming debug form action (single request with explicit session ID field) to isolate frontend SSE issues from SDK/session issues.

## Exit Criteria

1. Root cause identified with evidence (code + runtime test).
2. Minimal fix proposed or implemented.
3. Repeatable verification steps documented.

## Findings

1. SDK `Client` streaming path hangs in this environment.
- `Query::query()` works and returns responses quickly.
- `Client->connect(...); receiveMessages()` and `Client->connect(); query(); receiveResponse()` both hang (drush command timeout at 120s).
- Code path evidence:
- `Client::connect()` always calls `initialize()` for iterable prompts (`src/Claude/AgentSdk/Client.php:63-65`).
- Debug terminal and stream controller use `Client` streaming mode (`src/Form/ClaudeAgentSdkDebugForm.php:369-383`, `src/Controller/ClaudeAgentSdkDebugStreamController.php:109-115`).

2. Session continuity itself is not broken.
- Verified with `Query::query()` using `continueConversation=true` and `resume=<session_id>`.
- Result: second request correctly recalled prior message/token.

3. Debug terminal JS has a runtime bug.
- Stray block references `$form` and `$rawLog` outside behavior closure:
- `modules/ai_claude_agent_sdk_debug/js/ai_claude_agent_sdk_debug.js:549-558`
- This can throw `ReferenceError` in browser and destabilize terminal UI behavior.

4. Query path is currently the reliable fallback for session tests.
- `Query::query()` with iterable messages and `continueConversation=true` returned events/session id and completed.
- This path closes input deterministically (`src/Claude/AgentSdk/Query.php:23-28`).

## Recommended Next Implementation

1. Fix JS runtime bug by moving/removing the stray raw-toggle block in `ai_claude_agent_sdk_debug.js`.
2. Add a non-stream session debug mode using `Query::query()` + explicit `resume session_id` field.
3. Keep current Client/SSE terminal behind an "experimental" label until SDK Client hang is resolved upstream.
4. Add a reproducible drush verification command pair:
- request 1 stores token and captures session id
- request 2 resumes session and verifies recall
