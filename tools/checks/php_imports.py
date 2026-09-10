#!/usr/bin/env python3
r"""Class references in a namespaced PHP file that nothing imports.

`php -l` only parses. A `Foo::bar()` with no `use Vendor\Foo;` above it is
perfectly valid syntax that resolves to `Current\Namespace\Foo` and fatals the
moment the line runs — which, if the line sits behind `if (count($rows))`, is
the moment real data arrives and never in an empty test database.

This is the check that would have caught the `ChapterNumber` miss: an edit
anchored on an import that was not there, so the `use` was silently not added
while the call site was.
"""
import os, re, sys

USE_RE = re.compile(r"^\s*use\s+([\\\w]+)(?:\s+as\s+(\w+))?\s*;", re.M)
NS_RE = re.compile(r"^\s*namespace\s+([\\\w]+)\s*;", re.M)
DECL_RE = re.compile(r"^\s*(?:final\s+|abstract\s+)*(?:class|interface|trait|enum)\s+(\w+)", re.M)
# `Foo::CONST`, `Foo::method()`, `new Foo(`, `Foo::class`
STATIC_RE = re.compile(r"(?<![\\\w$>-])([A-Z]\w*)::")
NEW_RE = re.compile(r"\bnew\s+([A-Z]\w*)\s*\(")
TYPE_RE = re.compile(r"(?:^|[\s(,|?])([A-Z]\w*)\s+\$\w+")

# Resolved by PHP itself or by the language, never by a `use`.
BUILTIN = {
    "self", "static", "parent", "Closure", "Generator", "Throwable", "Exception",
    "Error", "TypeError", "ValueError", "ArgumentCountError", "RuntimeException",
    "LogicException", "InvalidArgumentException", "OutOfRangeException",
    "ArrayObject", "ArrayIterator", "Iterator", "IteratorAggregate", "Countable",
    "JsonSerializable", "Stringable", "DateTime", "DateTimeImmutable",
    "DateTimeInterface", "DateInterval", "DateTimeZone", "SplFileInfo",
    "SplObjectStorage", "RecursiveDirectoryIterator", "RecursiveIteratorIterator",
    "ZipArchive", "PDO", "PDOException", "ReflectionClass", "SensitiveParameter",
    "Attribute", "Override", "ReturnTypeWillChange", "AllowDynamicProperties",
    # WordPress globals that live in the root namespace and are always
    # imported explicitly where used — flagged if they are not, which is right.
}

def plugin_root(path):
    """The directory holding the plugin's `src/`, walking up from a file."""
    current = os.path.dirname(os.path.abspath(path))
    while current != "/":
        if os.path.isdir(os.path.join(current, "src")):
            return current
        current = os.path.dirname(current)
    return os.path.dirname(os.path.abspath(path))


def check(root):
    problems, scanned = [], 0

    for dirpath, _dirs, files in os.walk(root):
        for name in sorted(files):
            if not name.endswith(".php"):
                continue

            path = os.path.join(dirpath, name)
            src = open(path, encoding="utf-8").read()

            ns = NS_RE.search(src)
            if not ns:
                # No namespace: every unqualified name is already global.
                continue

            scanned += 1

            imported = set()
            for spec, alias in USE_RE.findall(src):
                imported.add(alias or spec.rsplit("\\", 1)[-1])

            declared = set(DECL_RE.findall(src))

            body = re.sub(r"/\*.*?\*/", " ", src, flags=re.S)
            body = re.sub(r"(?m)^\s*//.*$", " ", body)
            body = re.sub(r"(?m)^\s*use\s+[\\\w]+.*$", " ", body)

            used = set()
            used |= set(STATIC_RE.findall(body))
            used |= set(NEW_RE.findall(body))

            for symbol in sorted(used):
                if symbol in BUILTIN or symbol in imported or symbol in declared:
                    continue
                # Fully qualified at the use site is fine.
                if re.search(r"\\" + symbol + r"\b", body):
                    continue
                # Something in the same namespace resolves without a `use`.
                # Follow the plugin's own autoload rule rather than guessing:
                # `Animeh\A\B` is `<plugin>/src/A/B.php`.
                parts = ns.group(1).split("\\") + [symbol]
                candidates = [
                    os.path.join(dirpath, symbol + ".php"),
                    os.path.join(plugin_root(path), "src", *parts[1:]) + ".php",
                ]
                if any(os.path.exists(c) for c in candidates):
                    continue

                problems.append(
                    f"{path}: '{symbol}::' used but never imported "
                    f"(resolves to {ns.group(1)}\\{symbol})"
                )

    return scanned, problems

if __name__ == "__main__":
    scanned, problems = check(sys.argv[1] if len(sys.argv) > 1 else ".")
    for p in problems:
        print(p)
    print(f"\n{scanned} namespaced file(s) scanned, {len(problems)} problem(s).")
    sys.exit(1 if problems else 0)
