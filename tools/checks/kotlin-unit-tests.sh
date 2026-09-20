#!/usr/bin/env bash
# Runs the Kotlin unit tests that do not need Android.
#
# `./gradlew test` cannot run in the development container: the Android and
# Compose jars come from Google's Maven, which that environment blocks. But
# part of this codebase is deliberately free of the framework — parsing,
# policy, state — and that part compiles and runs on a plain JVM with the
# compiler and JUnit that ship inside Gradle's own distribution.
#
# Nothing here is hardcoded to one machine, and that is the point: the first
# version of this script carried the container's own paths and passed every
# time it was run here, then failed on the first CI run for a reason that had
# nothing to do with the code it was testing. Both the repository root and the
# jars are discovered.
set -uo pipefail

# From this file rather than from a guessed path: the script knows where it
# is, and `git rev-parse` needs a working directory that already exists.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# --- Find the jars -------------------------------------------------------
#
# Gradle ships the Kotlin compiler and JUnit inside its own lib directory, and
# every machine keeps that somewhere different: a distribution under /opt, a
# package under /usr/share, or whatever the wrapper downloaded into the Gradle
# home. Globbed rather than listed, so a version bump does not break this.

find_jar() {
  local pattern="$1" candidate
  for candidate in \
    "${GRADLE_HOME:-}/lib" \
    /opt/gradle-*/lib \
    /usr/share/gradle-*/lib \
    "${GRADLE_USER_HOME:-$HOME/.gradle}"/wrapper/dists/*/*/gradle-*/lib \
    "$ROOT"/android/gradle/wrapper/dists/*/*/gradle-*/lib
  do
    # `ls` rather than a glob test: the candidate itself may contain a glob
    # that matched nothing, and then the path does not exist at all.
    local found
    found="$(ls "$candidate"/$pattern 2>/dev/null | head -1)"

    if [ -n "$found" ]; then
      echo "$found"
      return 0
    fi
  done

  return 1
}

# The wrapper's distribution is only on disk once it has been run. Doing that
# here costs seconds on a machine that already has it and is the difference
# between working and not on one that does not.
if ! find_jar 'kotlin-compiler-embeddable-*.jar' > /dev/null; then
  echo "==> Gradle dağıtımı indiriliyor (derleyici için)"
  ( cd "$ROOT/android" && ./gradlew --version > /dev/null 2>&1 ) || true
fi

COMPILER_JAR="$(find_jar 'kotlin-compiler-embeddable-*.jar')" || {
  echo "Kotlin derleyicisi bulunamadı. Gradle dağıtımı nerede?" >&2
  exit 1
}

LIB="$(dirname "$COMPILER_JAR")"

need() {
  local found
  found="$(ls "$LIB"/$1 2>/dev/null | head -1)"

  if [ -z "$found" ]; then
    echo "eksik jar: $1 ($LIB içinde)" >&2
    exit 1
  fi

  echo "$found"
}

STDLIB="$(need 'kotlin-stdlib-*.jar')"
REFLECT="$(need 'kotlin-reflect-*.jar')"
ANNOTATIONS="$(need 'annotations-*.jar')"
COROUTINES="$(need 'kotlinx-coroutines-core-jvm-*.jar')"
JUNIT="$(need 'junit-4*.jar')"
HAMCREST="$(need 'hamcrest-core-*.jar')"
TROVE="$(ls "$LIB"/trove4j-*.jar 2>/dev/null | head -1)"

COMPILER="$COMPILER_JAR:$STDLIB:$REFLECT:$ANNOTATIONS:$COROUTINES${TROVE:+:$TROVE}"
DEPS="$STDLIB:$ANNOTATIONS:$JUNIT:$HAMCREST"

# --- What to compile -----------------------------------------------------
#
# Framework-free pairs: source, then its test. Anything importing android.* or
# androidx.* does not belong here and will simply fail to compile.
SOURCES=(
  "android/app/src/main/java/com/animeh/app/player/ads/Vast.kt"
  "android/app/src/main/java/com/animeh/app/player/ads/AdSchedule.kt"
)
TESTS=(
  "android/app/src/test/java/com/animeh/app/player/ads/VastParserTest.kt"
  "android/app/src/test/java/com/animeh/app/player/ads/AdScheduleTest.kt"
)
CLASSES=(
  "com.animeh.app.player.ads.VastParserTest"
  "com.animeh.app.player.ads.AdScheduleTest"
)

FILES=()
for f in "${SOURCES[@]}" "${TESTS[@]}"; do
  if [ ! -f "$ROOT/$f" ]; then
    echo "eksik dosya: $f" >&2
    exit 1
  fi
  FILES+=("$ROOT/$f")
done

echo "==> derleniyor (${#FILES[@]} dosya, $LIB)"
if ! java -cp "$COMPILER" org.jetbrains.kotlin.cli.jvm.K2JVMCompiler \
  -no-stdlib -no-reflect -classpath "$DEPS" -nowarn -d "$WORK/out" "${FILES[@]}" 2>"$WORK/compile.log"; then
  grep -E "error: " "$WORK/compile.log" >&2 || cat "$WORK/compile.log" >&2
  exit 1
fi

echo "==> koşuluyor"
java -Dfile.encoding=UTF-8 -cp "$WORK/out:$DEPS" org.junit.runner.JUnitCore "${CLASSES[@]}" \
  | grep -viE "^JUnit version|JAVA_TOOL_OPTIONS"
