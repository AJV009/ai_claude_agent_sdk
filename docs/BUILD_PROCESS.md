# AI Claude Agent SDK Build Process

For first-time project bootstrap instructions intended for issue queue users, see `docs/InitialSetup.md`.

This module supports two setup modes:

- Live build process: stable setup for normal project use.
- Dev build process: local git workflow for active module/library development.

Use project commands to run these modes:

```bash
bash web/modules/contrib/ai_claude_agent_sdk/scripts/setup-claudeagentsdk-live.sh
bash web/modules/contrib/ai_claude_agent_sdk/scripts/setup-claudeagentsdk-dev.sh
```

These are idempotent module-owned scripts intended for rebuild-safe reboots of the environment.

## 1) Live Build Process

Use this when you want a reproducible environment with explicit local git checkouts.

1. Add API key to `.ddev/.env`:
   ```bash
   cp .ddev/.env.example .ddev/.env
   # then edit .ddev/.env and set ANTHROPIC_API_KEY
   ```
2. Start/rebuild DDEV:
   ```bash
   ddev start
   ```
3. Run setup script:
   ```bash
   bash web/modules/contrib/ai_claude_agent_sdk/scripts/setup-claudeagentsdk-live.sh
   ```
4. Verify runtime:
   ```bash
   ddev exec command -v claude
   ```

Base setup enables:

- `ai`
- `key`
- `ai_claude_agent_sdk`
- `ai_claude_agent_sdk_debug`

Base setup also defaults SDK auth to environment mode:

- `ai_claude_agent_sdk.settings:api_key_source = environment`
- `ai_claude_agent_sdk.settings:api_key_env_var = ANTHROPIC_API_KEY`

Both setup commands create/maintain:

- Module checkout: `modules/ai_claude_agent_sdk` (git remote: `git@git.drupal.org:project/ai_claude_agent_sdk.git`)
- Contrib symlink: `web/modules/contrib/ai_claude_agent_sdk -> ../../../modules/ai_claude_agent_sdk`

## 2) Dev Build Process (Git Workflow)

Use this when you are actively developing module and library code and need push/pull against each upstream.

1. Add API key to `.ddev/.env`:
   ```bash
   cp .ddev/.env.example .ddev/.env
   # then edit .ddev/.env and set ANTHROPIC_API_KEY
   ```
2. Start/rebuild DDEV:
   ```bash
   ddev start
   ```
3. Run setup script:
   ```bash
   bash web/modules/contrib/ai_claude_agent_sdk/scripts/setup-claudeagentsdk-dev.sh
   ```

### Verify remotes

```bash
git -C web/modules/contrib/ai_claude_agent_sdk remote -v
```

Expected:
- Module points at Drupal GitLab via SSH (`git@git.drupal.org:project/ai_claude_agent_sdk.git`).

### Daily dev loop

1. Edit module in `modules/ai_claude_agent_sdk`.
2. Run `ddev drush cr`.
3. Commit and push.

### Optional console integration submodule

`ai_claude_agent_sdk_console` is intentionally not enabled by default.

Enable it only when you also want `ai_console`:

```bash
ddev composer require drupal/ai_console
ddev drush -y pm:en ai_console ai_claude_agent_sdk_console
```

## Notes

- Keep secrets in DDEV env, not committed config.
- Claude CLI is installed via `.ddev/web-build/Dockerfile.claude-code`; run `ddev restart` (or `ddev debug rebuild`) after Dockerfile changes.
