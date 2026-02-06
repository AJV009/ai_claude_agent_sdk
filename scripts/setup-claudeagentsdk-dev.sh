#!/usr/bin/env bash

set -euo pipefail

if [ -z "${DDEV_APPROOT:-}" ]; then
  echo "This script must be run via ddev (DDEV_APPROOT is not set)."
  exit 1
fi

cd "$DDEV_APPROOT" || exit 1

mkdir -p web/modules/contrib
mkdir -p claude_code_workspace

symlink_module() {
  local source_rel="$1"
  local target="$2"
  mkdir -p "$(dirname "$target")"
  rm -rf "$target"
  ln -s "$source_rel" "$target"
}

echo "Linking ai_claude_agent_sdk into contrib..."
symlink_module "../../../modules/ai_claude_agent_sdk" "web/modules/contrib/ai_claude_agent_sdk"

echo "Ensuring claude-agent-sdk-php library is installed..."
if ddev exec "composer show jamieaa64/claude-agent-sdk-php --no-ansi >/dev/null 2>&1"; then
  echo "jamieaa64/claude-agent-sdk-php already installed, skipping..."
else
  ddev composer require jamieaa64/claude-agent-sdk-php:dev-main
fi

echo "Ensuring Claude Code CLI is available in web container..."
ddev exec "if ! command -v claude >/dev/null 2>&1; then npm install -g @anthropic-ai/claude-code; fi"

echo "Creating runtime workspace in container..."
ddev exec "mkdir -p /var/www/html/claude_code_workspace"

echo "Enabling Claude SDK related modules..."
ddev drush -y pm:en ai_claude_agent_sdk ai_claude_agent_sdk_debug ai_console ai_claude_agent_sdk_console

echo "Configuring SDK defaults..."
ddev exec 'CLAUDE_PATH="$(command -v claude || true)"; if [ -n "$CLAUDE_PATH" ]; then drush -y cset ai_claude_agent_sdk.settings cli_path "$CLAUDE_PATH"; fi'
ddev drush -y cset ai_claude_agent_sdk.settings working_directory "/var/www/html/claude_code_workspace"

echo "Clearing cache..."
ddev drush cr

echo "Claude Agent SDK dev setup complete."
