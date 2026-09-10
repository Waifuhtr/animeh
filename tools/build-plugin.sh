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
# Not the same check: run.php proves the pure logic, this one proves the
# WordPress layer loads, registers and executes against rows. A `use` that an
# edit failed to add parses fine, passes every unit test, and 500s the site on
# the first request that has data to format.
php "$PLUGIN_DIR/tests/smoke/run.php" > /dev/null

# And the bridge, which installs on a different site and so is easy to forget:
# its own 500 was a rename that landed on a definition and not on its call
# site. The call check finds that shape statically; the smoke run executes
# every route it registers.
BRIDGE_DIR="$ROOT/wordpress-plugin/animeh-manga-bridge"
php "$ROOT/tools/php-call-check.php" "$PLUGIN_DIR/src" "$BRIDGE_DIR" > /dev/null
# And the same question for methods: does the method exist, does the count
# fit, do literal arguments match the declared types.
php "$ROOT/tools/php-method-check.php" > /dev/null
php "$BRIDGE_DIR/tests/smoke.php" > /dev/null

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

# The bridge, packaged here too rather than by hand: it is a separate plugin
# for a separate site, and the one built separately is the one that goes stale.
BRIDGE_VERSION="$(grep -oE "^const VERSION\s*=\s*'[^']+'" "$BRIDGE_DIR/animeh-manga-bridge.php" | grep -oE "'[^']+'" | tr -d "'")"
BRIDGE_ARCHIVE="$DIST/animeh-manga-bridge-$BRIDGE_VERSION.zip"

rm -f "$BRIDGE_ARCHIVE"
mkdir -p "$STAGE/animeh-manga-bridge"
cp "$BRIDGE_DIR/animeh-manga-bridge.php" "$STAGE/animeh-manga-bridge/"
( cd "$STAGE" && zip -qr "$BRIDGE_ARCHIVE" animeh-manga-bridge )

echo
echo "$ARCHIVE"
unzip -l "$ARCHIVE" | tail -1
echo "$BRIDGE_ARCHIVE"
unzip -l "$BRIDGE_ARCHIVE" | tail -1
