# AI Claude Agent SDK Initial Setup

## Quick Start (Existing Drupal CMS Site)

If you already have a running Drupal CMS site, use these commands in this order:

```bash
ddev composer require drupal/ai_claude_agent_sdk
ddev drush -y pm:en ai_claude_agent_sdk ai_claude_agent_sdk_debug
bash web/modules/contrib/ai_claude_agent_sdk/scripts/setup-claudeagentsdk-live.sh
```

If you are actively developing both repos (module + library), run this instead of the last command:

```bash
bash web/modules/contrib/ai_claude_agent_sdk/scripts/setup-claudeagentsdk-dev.sh
```

This is the canonical first-time setup for this module.

Goal:
- keep module and SDK library as separate git repos
- keep setup rebuild-safe
- avoid manual one-off project root edits

The scripts in this module manage required project-level wiring.

## Managed Layout

After setup, the expected layout is:

- module git checkout: `modules/ai_claude_agent_sdk`
- library git checkout: `libraries/claude-agent-sdk-php`
- contrib symlink: `web/modules/contrib/ai_claude_agent_sdk -> ../../../modules/ai_claude_agent_sdk`
- composer package `jamieaa64/claude-agent-sdk-php` sourced from `./libraries/claude-agent-sdk-php`

## Prerequisites

1. DDEV is installed and the site can start:
```bash
ddev start
```

2. SSH access is configured for both repos:
- `git@git.drupal.org:project/ai_claude_agent_sdk.git`
- `git@github.com:jamieaa64/claude-agent-sdk-php.git`

3. `ANTHROPIC_API_KEY` is available to DDEV.

Global DDEV example:
```bash
ddev auth ssh
# API key can be set in your global DDEV environment config.
```

Project `.ddev/.env` example:
```bash
cp .ddev/.env.example .ddev/.env
# Add:
# ANTHROPIC_API_KEY=sk-ant-...
ddev restart
```

## Setup Commands

Run from project root:

Dev workflow (recommended while building/debugging):
```bash
bash web/modules/contrib/ai_claude_agent_sdk/scripts/setup-claudeagentsdk-dev.sh
```

Live workflow (same reproducible structure, no extra dev assumptions):
```bash
bash web/modules/contrib/ai_claude_agent_sdk/scripts/setup-claudeagentsdk-live.sh
```

## What Setup Scripts Do

Both scripts:
- ensure module checkout exists and points to Drupal SSH remote
- ensure library checkout exists and points to GitHub SSH remote
- create/refresh contrib symlink to module checkout
- configure composer path repository for local library checkout
- ensure Claude CLI exists in DDEV web container
- enable Drupal modules: `ai`, `key`, `ai_claude_agent_sdk`, `ai_claude_agent_sdk_debug`
- set auth default `ai_claude_agent_sdk.settings:api_key_source = environment`
- set auth env var `ai_claude_agent_sdk.settings:api_key_env_var = ANTHROPIC_API_KEY`
- clear Drupal caches

## Verification

Run:
```bash
git -C modules/ai_claude_agent_sdk remote -v
git -C libraries/claude-agent-sdk-php remote -v
ls -l web/modules/contrib/ai_claude_agent_sdk
ddev exec command -v claude
ddev drush pm:list --status=enabled --type=module --no-core --format=list | grep -E '^(ai|key|ai_claude_agent_sdk|ai_claude_agent_sdk_debug)$'
ddev drush cget ai_claude_agent_sdk.settings api_key_source
ddev drush cget ai_claude_agent_sdk.settings api_key_env_var
```

Expected remotes:
- module: `git@git.drupal.org:project/ai_claude_agent_sdk.git`
- library: `git@github.com:jamieaa64/claude-agent-sdk-php.git`

## Daily Development Workflow

1. Edit module code in `modules/ai_claude_agent_sdk`.
2. Edit SDK library code in `libraries/claude-agent-sdk-php`.
3. Rebuild caches:
```bash
ddev drush cr
```
4. Commit/push in each repo separately.

Do not edit/commit library code in `vendor/`; Composer should symlink vendor to `libraries/claude-agent-sdk-php`.

## Rebuild / Recovery

If containers were rebuilt or dependencies changed, rerun:
```bash
bash web/modules/contrib/ai_claude_agent_sdk/scripts/setup-claudeagentsdk-dev.sh
```

If setup state is broken, reset library/symlink state and rerun:
```bash
rm -rf libraries/claude-agent-sdk-php web/modules/contrib/ai_claude_agent_sdk
bash web/modules/contrib/ai_claude_agent_sdk/scripts/setup-claudeagentsdk-dev.sh
```

If the module checkout itself was removed, restore it first:
```bash
mkdir -p modules
git clone git@git.drupal.org:project/ai_claude_agent_sdk.git modules/ai_claude_agent_sdk
bash modules/ai_claude_agent_sdk/scripts/setup-claudeagentsdk-dev.sh
```

## Troubleshooting

- `Permission denied (publickey)` on clone/push: confirm SSH keys are loaded and access is granted on git.drupal.org and GitHub.
- `ddev command not found`: install DDEV and use a shell where `ddev` is on `PATH`.
- `Claude CLI not found` after setup: rerun setup script; it installs fallback runtime in web container.
- No menu links under AI config: ensure `ai` module is enabled and run `ddev drush cr`.

## Module-local Plans

Module planning artifacts live in:
- `.agents/plans/current`
- `.agents/plans/archive`
- `.agents/plans/future`

Use module-local plan files when linking implementation plans in issue queue comments.
