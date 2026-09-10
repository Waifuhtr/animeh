#!/usr/bin/env bash
# What did *this change* break?
#
# The Android and Compose jars are not reachable here — Google's Maven is
# blocked — so a full compile is impossible and the compiler reports twelve
# thousand unresolved references for symbols it simply cannot see. Filtering
# those by shape was how five real errors reached a release build: three
# missing imports, a property that does not exist, and a string resource
# invented out of thin air.
#
# So instead of guessing which errors are real, compile the baseline too and
# report only what is new. Every one of those twelve thousand is in both runs;
# a mistake I just made is in exactly one.
#
# One thing it cannot do: an unresolvable receiver hides its members, so
# renaming one — `friend.avatarUrl` to `friend.avatar` — reads as a new error
# even when the new name is the right one. A line naming a member is worth
# checking against the type by hand; everything else on this list is real.
#
#   Usage: kotlin-new-errors.sh [<baseline-ref>]   (default: HEAD)
set -uo pipefail

ROOT="$(git -C "${PROJECT:-/home/user/animeh}" rev-parse --show-toplevel)"
BASE="${1:-HEAD}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

L=/opt/gradle-8.14.3/lib
CP="$L/kotlin-compiler-embeddable-2.0.21.jar:$L/kotlin-stdlib-2.0.21.jar:$L/kotlin-reflect-2.0.21.jar:$L/trove4j-1.0.20200330.jar:$L/annotations-24.0.1.jar:$L/kotlinx-coroutines-core-jvm-1.6.4.jar"

# Errors as "<file>:<line-independent message>", so moving code does not read
# as a new failure.
compile_tree() {
  local tree="$1" out
  out="$(mktemp -d)"
  java -cp "$CP" org.jetbrains.kotlin.cli.jvm.K2JVMCompiler \
    -no-stdlib -no-reflect \
    -classpath "$L/kotlin-stdlib-2.0.21.jar:$L/kotlinx-coroutines-core-jvm-1.6.4.jar:$L/annotations-24.0.1.jar" \
    -d "$out" -nowarn $(find "$tree" -name '*.kt') 2>&1 > /dev/null \
    | grep -E "error: " \
    | sed -E "s#^.*/app/src/#app/src/#; s#:[0-9]+:[0-9]+: error: #  #" \
    | sort -u
  rm -rf "$out"
}

echo "==> temel: $BASE"
mkdir -p "$WORK/base"
git -C "$ROOT" archive "$BASE" android | tar -x -C "$WORK/base"
compile_tree "$WORK/base/android" > "$WORK/base.txt"

echo "==> çalışma ağacı"
compile_tree "$ROOT/android" > "$WORK/head.txt"

echo
NEW="$(comm -13 "$WORK/base.txt" "$WORK/head.txt")"

if [ -z "$NEW" ]; then
  echo "Bu değişiklikle gelen yeni hata yok. (temel: $(wc -l < "$WORK/base.txt") satır)"
  exit 0
fi

echo "Bu değişiklikle GELEN hatalar:"
echo "$NEW" | sed 's/^/  /'
echo
echo "$NEW" | wc -l | xargs printf "%s yeni hata\n"
exit 1
