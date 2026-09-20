#!/usr/bin/env bash
# Runs the Kotlin unit tests that do not need Android.
#
# `./gradlew test` cannot run here: the Android and Compose jars come from
# Google's Maven, which this environment blocks. But part of this codebase is
# deliberately free of the framework — parsing, policy, state — and that part
# compiles and runs on a plain JVM with the compiler and JUnit that ship inside
# Gradle's own distribution.
#
# This is not a substitute for the real test task. It is the subset that can be
# proved here rather than hoped for, and the VAST parser is the reason it
# exists: the ad network's real response turned out to use a spelling of its
# tracking events that a parser written from the specification never looks at,
# and nothing short of running the parser against that response would have
# shown it.
set -uo pipefail

ROOT="$(git -C "${PROJECT:-/home/user/animeh}" rev-parse --show-toplevel)"
L=/opt/gradle-8.14.3/lib
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

COMPILER="$L/kotlin-compiler-embeddable-2.0.21.jar:$L/kotlin-stdlib-2.0.21.jar:$L/kotlin-reflect-2.0.21.jar:$L/trove4j-1.0.20200330.jar:$L/annotations-24.0.1.jar:$L/kotlinx-coroutines-core-jvm-1.6.4.jar"
DEPS="$L/kotlin-stdlib-2.0.21.jar:$L/annotations-24.0.1.jar:$L/junit-4.13.2.jar:$L/hamcrest-core-1.3.jar"

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

echo "==> derleniyor (${#FILES[@]} dosya)"
if ! java -cp "$COMPILER" org.jetbrains.kotlin.cli.jvm.K2JVMCompiler \
  -no-stdlib -no-reflect -classpath "$DEPS" -nowarn -d "$WORK/out" "${FILES[@]}" 2>"$WORK/compile.log"; then
  grep -E "error: " "$WORK/compile.log" >&2 || cat "$WORK/compile.log" >&2
  exit 1
fi

echo "==> koşuluyor"
java -Dfile.encoding=UTF-8 -cp "$WORK/out:$DEPS" org.junit.runner.JUnitCore "${CLASSES[@]}" \
  | grep -viE "^JUnit version|JAVA_TOOL_OPTIONS"
