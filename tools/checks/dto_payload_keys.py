#!/usr/bin/env python3
"""
Every JSON key an Android DTO expects, against every key the plugin emits.

The bug this exists for: every field in every DTO has a default, so a key the
server never sends does not fail — it silently becomes 0, "" or an empty list,
and the screen shows a plausible wrong answer. `page_payload` sent the height
column under the key `width` for exactly this reason and nothing complained.

Heuristic on purpose. It reads @SerialName / property names out of the Kotlin
data classes and `'key' =>` out of the PHP, so it cannot see a key assembled at
runtime. A name here is a question to answer, not a proven defect.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DTO_DIR = ROOT / "android/app/src/main/java/com/animeh/app/data/remote/dto"
PHP_DIRS = (
    ROOT / "wordpress-plugin/animeh/src",
    # The bridge answers /ping with its own keys, and BridgeRemoteDto reads them.
    ROOT / "wordpress-plugin/animeh-manga-bridge",
)

SERIAL = re.compile(r'@SerialName\(\s*"([^"]+)"\s*\)')
PROP = re.compile(r'^\s*(?:@[A-Za-z]\w*(?:\([^)]*\))?\s*)*(?:val|var)\s+([A-Za-z_][A-Za-z0-9_]*)\s*:')
# `val any: Boolean get() = …` is computed in Kotlin and never arrives as JSON.
# The getter may also sit on the line below its `val`, indented further.
COMPUTED = re.compile(r'\bget\(\)')
# Only a @Serializable class is a wire shape. ChatMessageUi lives beside the
# DTOs because that is where anyone looks for "what does a room look like", but
# chat never goes through REST and its fields are not keys.
SERIALIZABLE = re.compile(r'^\s*@Serializable\s*$')
CLASS = re.compile(r'^\s*(?:data\s+)?class\s+([A-Za-z_]\w*)')
# Both shapes the plugin writes a key in: inside an array literal, and as a
# later assignment onto one — `$payload['is_favorite'] = …` is how every
# signed-in-only field gets added.
PHP_KEY = re.compile(r"'([a-z0-9_]+)'\s*(?:=>|\])")
SQL_ALIAS = re.compile(r'\bAS\s+([a-z0-9_]+)')
# Keys the app sends up rather than reads down; a request body is not a payload.
REQUEST_FILES = {"RequestDtos.kt"}


def kotlin_keys() -> dict[str, set[str]]:
    found: dict[str, set[str]] = {}
    for path in sorted(DTO_DIR.glob("*.kt")):
        if path.name in REQUEST_FILES:
            continue
        keys: set[str] = set()
        lines = path.read_text(encoding="utf-8").splitlines()
        # A @SerialName renames the wire key, so wherever one appears — on the
        # property's own line or on the line above it — the Kotlin name is not
        # what arrives over the wire and must not be counted as a key.
        renamed = False
        serializable = False
        pending = False
        for index, line in enumerate(lines):
            if SERIALIZABLE.match(line):
                pending = True
                continue
            if CLASS.match(line):
                serializable = pending
                pending = False

            serial = SERIAL.search(line)
            prop = PROP.match(line)
            if serial:
                if serializable:
                    keys.add(serial.group(1))
            if prop:
                following = lines[index + 1] if index + 1 < len(lines) else ""
                computed = COMPUTED.search(line) or COMPUTED.search(following)
                if serializable and not serial and not renamed and not computed:
                    keys.add(prop.group(1))
                renamed = False
            elif serial:
                renamed = True
        if keys:
            found[path.name] = keys
    return found


def php_keys() -> set[str]:
    keys: set[str] = set()
    for directory in PHP_DIRS:
        for path in directory.rglob("*.php"):
            keys.update(PHP_KEY.findall(path.read_text(encoding="utf-8")))
            # A SELECT alias becomes a key the moment the row is serialised —
            # `… ) AS episode_count` is how a season carries its own count.
            keys.update(SQL_ALIAS.findall(path.read_text(encoding="utf-8")))
    return keys


def main() -> int:
    emitted = php_keys()
    missing: list[tuple[str, str]] = []
    total = 0
    for name, keys in sorted(kotlin_keys().items()):
        for key in sorted(keys):
            total += 1
            if key not in emitted:
                missing.append((name, key))

    print(f"{total} DTO keys checked against {len(emitted)} keys the plugin emits")
    if not missing:
        print("no unmatched keys")
        return 0

    print(f"\n{len(missing)} DTO keys the plugin never writes:")
    for name, key in missing:
        print(f"  {name}: {key}")
    return 1


if __name__ == "__main__":
    sys.exit(main())
