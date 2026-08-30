#!/usr/bin/env bash
# Packages the Hugging Face Space, with the Android source inside it.
#
#   dist/animeh-apk-builder-space.zip
#
# Upload every file in the archive to a Docker Space, keeping the layout: the
# build server looks for the project at ./android, and moving it is how the
# Space ends up reporting "Proje: BULUNAMADI".
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SPACE_DIR="$ROOT/huggingface-space"
ANDROID_DIR="$ROOT/android"
DIST="$ROOT/dist"
ARCHIVE="$DIST/animeh-apk-builder-space.zip"

echo "==> checking the Space"
for entry in Dockerfile app.py requirements.txt README.md static/index.html static/style.css static/app.js; do
  [ -f "$SPACE_DIR/$entry" ] || { echo "missing: $entry" >&2; exit 1; }
done

python3 -c "import ast,sys; ast.parse(open('$SPACE_DIR/app.py').read())"
node --input-type=module --check < "$SPACE_DIR/static/app.js" 2>/dev/null \
  || node --check "$SPACE_DIR/static/app.js"

echo "==> checking the Android project"
for entry in settings.gradle.kts build.gradle.kts gradle.properties gradlew \
             gradle/libs.versions.toml gradle/wrapper/gradle-wrapper.jar \
             gradle/wrapper/gradle-wrapper.properties \
             app/build.gradle.kts app/src/main/AndroidManifest.xml; do
  [ -f "$ANDROID_DIR/$entry" ] || { echo "missing: android/$entry" >&2; exit 1; }
done

echo "==> staging"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

cp "$SPACE_DIR/Dockerfile" "$SPACE_DIR/app.py" "$SPACE_DIR/requirements.txt" \
   "$SPACE_DIR/README.md" "$SPACE_DIR/.dockerignore" "$STAGE/"
cp -R "$SPACE_DIR/static" "$STAGE/static"

# The source, minus anything generated. Build output would be stale on arrival
# and is large; local.properties is written by the image for its own SDK path.
mkdir -p "$STAGE/android"
( cd "$ANDROID_DIR" && \
  find . \
    -path ./build -prune -o \
    -path ./app/build -prune -o \
    -path ./.gradle -prune -o \
    -path ./.idea -prune -o \
    -name local.properties -prune -o \
    -type f -print ) \
  | while read -r file; do
      mkdir -p "$STAGE/android/$(dirname "$file")"
      cp "$ANDROID_DIR/$file" "$STAGE/android/$file"
    done

chmod +x "$STAGE/android/gradlew"

echo "==> packaging"
mkdir -p "$DIST"
rm -f "$ARCHIVE"
( cd "$STAGE" && zip -qr "$ARCHIVE" . -x '.*' && zip -q "$ARCHIVE" .dockerignore )

echo
echo "$ARCHIVE"
unzip -l "$ARCHIVE" | tail -1
