#!/usr/bin/env bash
#
# Claude TUI Sidecar — setup helper.
#
# DDEV users: Files are placed automatically by Composer scaffold.
#   composer install && ddev restart
#
# Non-DDEV / manual:
#   bash setup.sh install   # npm install
#   node server.js           # start sidecar
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

usage() {
  cat <<EOF
Usage: $0 <command>

Commands:
  install   Install npm dependencies (for manual / non-DDEV use)
  check     Check if sidecar is running

DDEV users don't need this script — Composer scaffold + ddev restart handles everything.
EOF
}

cmd_install() {
  echo "Installing npm dependencies..."
  cd "$SCRIPT_DIR"

  if ! command -v node &> /dev/null; then
    echo "Error: Node.js is required but not installed."
    exit 1
  fi

  if ! command -v npm &> /dev/null; then
    echo "Error: npm is required but not installed."
    exit 1
  fi

  npm install
  echo "Done. Start with: node server.js"
}

cmd_check() {
  local url="${CLAUDE_SIDECAR_URL:-http://localhost:3000}"
  if curl -sf "$url/health" 2>/dev/null; then
    echo ""
    echo "Sidecar is running."
  else
    echo "Sidecar is not responding at $url"
    exit 1
  fi
}

case "${1:-}" in
  install) cmd_install ;;
  check)   cmd_check ;;
  *)       usage ;;
esac
