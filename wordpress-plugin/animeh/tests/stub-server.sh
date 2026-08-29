#!/usr/bin/env bash
# Starts the stub server the browser tests drive the admin panel against.
#
# PHP's built-in server handles one request at a time. That is tolerable here
# because a browser fetches a playlist and then its segments largely in
# sequence, but it is worth knowing when reading a slow run.
# `PHP_CLI_SERVER_WORKERS` would lift the limit and is deliberately not used:
# it forks, and forking is not available in every container.
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT="${STUB_PORT:-8765}"
LOG="${STUB_LOG:-/tmp/animeh-stub.log}"

case "${1:-start}" in
  start)
    rm -rf "$PLUGIN_DIR/tests/.stub-state"
    setsid php -S "127.0.0.1:$PORT" -t "$PLUGIN_DIR" "$PLUGIN_DIR/tests/stub-server.php" \
      > "$LOG" 2>&1 < /dev/null &
    for _ in $(seq 1 40); do
      if curl -fs -o /dev/null -m 1 "http://127.0.0.1:$PORT/"; then
        echo "stub listening on http://127.0.0.1:$PORT"
        exit 0
      fi
      sleep 0.25
    done
    echo "stub failed to start; see $LOG" >&2
    exit 1
    ;;
  stop)
    # Matched on the listening address, not the script name: a pattern
    # containing "stub-server.php" also matches the shell running this script.
    pkill -f "php -S 127.0.0.1:$PORT" 2>/dev/null || true
    echo "stub stopped"
    ;;
  *)
    echo "usage: $0 [start|stop]" >&2
    exit 64
    ;;
esac
