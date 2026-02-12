#!/usr/bin/env bash

set -euo pipefail

find_ddev_approot() {
  local dir="$1"
  while [ "$dir" != "/" ]; do
    if [ -f "$dir/.ddev/config.yaml" ]; then
      echo "$dir"
      return 0
    fi
    dir="$(dirname "$dir")"
  done
  return 1
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APPROOT="${DDEV_APPROOT:-}"

if [ -z "$APPROOT" ] || [ ! -f "$APPROOT/.ddev/config.yaml" ]; then
  if APPROOT="$(find_ddev_approot "$PWD")"; then
    :
  elif APPROOT="$(find_ddev_approot "$SCRIPT_DIR")"; then
    :
  else
    echo "Could not find .ddev/config.yaml from current directory or script path."
    echo "Run this script from a Drupal project that uses DDEV."
    exit 1
  fi
fi

export DDEV_APPROOT="$APPROOT"

if [ ! -f "$DDEV_APPROOT/.ddev/config.yaml" ]; then
  echo "Could not find .ddev/config.yaml at $DDEV_APPROOT"
  echo "Run this script from a Drupal project that uses DDEV."
  exit 1
fi

if ! command -v ddev >/dev/null 2>&1; then
  echo "ddev command not found on PATH."
  echo "Install/start DDEV first, then rerun."
  exit 1
fi

cd "$DDEV_APPROOT" || exit 1

MODULE_REMOTE="git@git.drupal.org:project/ai_claude_agent_sdk.git"
MODULE_WORKTREE="$DDEV_APPROOT/modules/ai_claude_agent_sdk"
LIB_WORKTREE="$DDEV_APPROOT/libraries/claude-agent-sdk-php"
MODULE_CONTRIB="$DDEV_APPROOT/web/modules/contrib/ai_claude_agent_sdk"
MODULE_CONTRIB_TARGET="../../../modules/ai_claude_agent_sdk"
WORKSPACE_DIR="/var/www/html/claude_code_workspace"
LIB_REMOTE="git@github.com:jamieaa64/claude-agent-sdk-php.git"

ensure_checkout() {
  local path="$1"
  local remote="$2"
  local label="$3"

  if [ -d "$path/.git" ]; then
    if git -C "$path" remote | grep -qx "origin"; then
      git -C "$path" remote set-url origin "$remote"
    else
      git -C "$path" remote add origin "$remote"
    fi
    echo "$label checkout already exists at $path"
    return
  fi

  if [ -e "$path" ]; then
    echo "ERROR: $label path exists but is not a git checkout: $path"
    echo "Please remove or rename this path and rerun."
    exit 1
  fi

  echo "Cloning $label checkout into $path..."
  git clone "$remote" "$path"
}

mkdir -p "$DDEV_APPROOT/modules" "$DDEV_APPROOT/libraries" "$DDEV_APPROOT/web/modules/contrib"
ensure_checkout "$MODULE_WORKTREE" "$MODULE_REMOTE" "Module"
ensure_checkout "$LIB_WORKTREE" "$LIB_REMOTE" "Library"

echo "Linking module into contrib..."
if [ -e "$MODULE_CONTRIB" ] && [ ! -L "$MODULE_CONTRIB" ]; then
  rm -rf "$MODULE_CONTRIB"
fi
ln -sfn "$MODULE_CONTRIB_TARGET" "$MODULE_CONTRIB"

echo "Configuring Composer to use local library checkout..."
ddev composer config repositories.claude-agent-sdk path ./libraries/claude-agent-sdk-php
ddev composer require --no-interaction jamieaa64/claude-agent-sdk-php:dev-main

echo "Ensuring Claude Code CLI is available in web container..."
if ! ddev exec command -v claude >/dev/null 2>&1; then
  echo "Claude CLI not found in container; installing fallback runtime now."
  echo "For persistent installs, keep .ddev/web-build/Dockerfile.claude-code in this project."
  ddev exec npm install -g @anthropic-ai/claude-code
fi

echo "Configuring and enabling Drupal modules..."
ddev exec mkdir -p "$WORKSPACE_DIR"
ddev drush -y pm:en ai key
ddev drush -y pm:en ai_claude_agent_sdk ai_claude_agent_sdk_debug
ddev exec 'CLAUDE_PATH="$(command -v claude || true)"; if [ -n "$CLAUDE_PATH" ]; then drush -y cset ai_claude_agent_sdk.settings cli_path "$CLAUDE_PATH"; fi'
ddev drush -y cset ai_claude_agent_sdk.settings working_directory "$WORKSPACE_DIR"
ddev drush -y cset ai_claude_agent_sdk.settings api_key_source environment
ddev drush -y cset ai_claude_agent_sdk.settings api_key_env_var ANTHROPIC_API_KEY
ddev drush cr

echo "Verifying remotes..."
git -C "$MODULE_WORKTREE" remote -v
git -C "$LIB_WORKTREE" remote -v

echo "Claude Agent SDK live setup complete."
