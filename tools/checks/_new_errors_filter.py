#!/usr/bin/env python3
"""Drop the new-error lines that are only new because a name is new.

Every androidx and Compose symbol is unresolved here — Google's Maven is
blocked, so those jars are missing. A file that starts using one it never used
before therefore produces an error that is new and means nothing.

The rule: if the file imports that exact name from a package this project does
not own, the compiler was always going to fail on it and the line is noise.
A project symbol, or a name with no import at all, stays — those are the
mistakes worth seeing.

    Usage: _new_errors_filter.py <tree-root> < new-error-lines
"""

import pathlib
import re
import sys

LINE = re.compile(r"^\s*(\S+?)\s\s+(.*)$")
UNRESOLVED = re.compile(r"unresolved reference '([^']+)'")


def foreign_imports(path: pathlib.Path) -> set[str]:
    """Names this file imports from somewhere other than the project."""
    if not path.is_file():
        return set()

    names = set()
    for line in path.read_text(errors="ignore").split("\n"):
        if not line.startswith("import "):
            continue

        target = line[len("import "):].strip().rstrip(";")
        if target.startswith("com.animeh"):
            continue

        # `import a.b.C as D` introduces D, not C.
        if " as " in target:
            names.add(target.split(" as ")[-1].strip())
        else:
            names.add(target.split(".")[-1])

    return names


def main() -> int:
    root = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else ".")
    cache: dict[str, set[str]] = {}
    kept = []

    for raw in sys.stdin.read().split("\n"):
        if not raw.strip():
            continue

        match = LINE.match(raw)
        if not match:
            kept.append(raw)
            continue

        where, message = match.group(1), match.group(2)
        symbol = UNRESOLVED.search(message)

        if symbol:
            if where not in cache:
                cache[where] = foreign_imports(root / where)
            if symbol.group(1) in cache[where]:
                continue

        kept.append(raw)

    print("\n".join(kept))
    return 0


if __name__ == "__main__":
    sys.exit(main())
