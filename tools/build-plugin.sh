#!/usr/bin/env bash
# Builds the installable WordPress plugin archive.
#
#   dist/animeh-<version>.zip
#
# The player is built here rather than committed: the bundle plus the libass
# wasm is over five megabytes, which does not belong in git. The zip is what
# gets uploaded to WordPress.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_DIR="$ROOT/wordpress-plugin/animeh"
DIST="$ROOT/dist"

VERSION="$(grep -oE "^const VERSION\s*=\s*'[^']+'" "$PLUGIN_DIR/animeh.php" | grep -oE "'[^']+'" | tr -d "'")"
if [ -z "$VERSION" ]; then
  echo "could not read the plugin version from animeh.php" >&2
  exit 1
fi

echo "==> building the player bundle"
( cd "$ROOT/player" && npm run --silent build:plugin )

echo "==> checking the plugin"
find "$PLUGIN_DIR" -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null
php "$PLUGIN_DIR/tests/run.php" > /dev/null

# Everything the plugin needs at runtime, and nothing else: no tests, no
# development state, no editor leftovers.
REQUIRED=(
  "animeh.php"
  "uninstall.php"
  "readme.txt"
  "src"
  "assets/admin"
  "assets/player/animeh-player.js"
  "assets/player/animeh-player.css"
  "assets/jassub/jassub-worker.js"
  "assets/jassub/jassub-worker.wasm"
  "assets/jassub/jassub-worker-modern.wasm"
  "assets/jassub/default.woff2"
)

for entry in "${REQUIRED[@]}"; do
  if [ ! -e "$PLUGIN_DIR/$entry" ]; then
    echo "missing from the build: $entry" >&2
    exit 1
  fi
done

echo "==> packaging"
mkdir -p "$DIST"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/animeh"
for entry in "${REQUIRED[@]}"; do
  mkdir -p "$STAGE/animeh/$(dirname "$entry")"
  cp -R "$PLUGIN_DIR/$entry" "$STAGE/animeh/$entry"
done

ARCHIVE="$DIST/animeh-$VERSION.zip"
rm -f "$ARCHIVE"
( cd "$STAGE" && zip -qr "$ARCHIVE" animeh )

echo
echo "$ARCHIVE"
unzip -l "$ARCHIVE" | tail -1
