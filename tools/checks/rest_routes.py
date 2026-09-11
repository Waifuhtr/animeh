#!/usr/bin/env python3
"""
Every path the Android app calls, against every route the plugin registers.

The bug this exists for: the app shipped a call to `GET /admin/works/{id}` one
release before the plugin registered that route, and the only symptom was an
edit form where every field was empty and a `rest_no_route` line at the bottom
of the log. Nothing in either build could have noticed — Retrofit's path is a
string, and `register_rest_route` is a string, and they are in different
languages in different repositories.

Both directions are reported. A registered route with no caller is usually
fine (the admin panel's own JavaScript calls some of them, and a few exist for
the site rather than the app) so those are listed as a note, not a failure.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
API = ROOT / "android/app/src/main/java/com/animeh/app/data/remote/AnimehApi.kt"
REST_DIR = ROOT / "wordpress-plugin/animeh/src/Rest"

RETROFIT = re.compile(r'@(GET|POST|PUT|PATCH|DELETE)\(\s*"([^"]+)"\s*\)')
# The namespace argument is spelled three ways across the controllers —
# `$namespace`, `self::NAMESPACE`, a literal — so match anything up to the
# comma rather than guessing which.
REGISTER = re.compile(r"register_rest_route\(\s*[^,]+,\s*'([^']+)'")
# Retrofit's {id} and WordPress's (?P<id>\d+) name the same hole.
BRACE = re.compile(r"\{[^}]+\}")
NAMED = re.compile(r"\(\?P<[^>]+>[^)]*\)")
# Routes the site's own WordPress admin pages call, not the app. Kept out of
# the note so that anything left in it is a real question — "did the app lose
# this call, or was it never written?"
BROWSER_ONLY = {
    "/test/",           # the player test harness
    "/migration/",      # moving the whole site, done from the browser
    "/storage/images",  # storage settings live on the WordPress page
    "/storage/objects",
    "/storage/playback",
    "/storage/settings",
    "/auth/sessions",   # the session list, shown in the browser panel
    "/admin/bans",      # the app reaches bans through /admin/users/{id}/ban
    "/admin/users/",    # …/role, likewise: the app uses /admin/moderators
}


def shape(path: str) -> str:
    """One spelling for a path, whichever language wrote it.

    The named groups come first: `(?P<id>\\d+)` contains a `?`, so trimming a
    query string before collapsing it cuts the route in half.
    """
    path = NAMED.sub("{}", path.strip("/"))
    path = BRACE.sub("{}", path)
    return path.split("?", 1)[0]


def app_paths() -> dict[str, list[str]]:
    found: dict[str, list[str]] = {}
    for verb, path in RETROFIT.findall(API.read_text(encoding="utf-8")):
        found.setdefault(shape(path), []).append(f"{verb} {path}")
    return found


def server_paths() -> dict[str, list[str]]:
    found: dict[str, list[str]] = {}
    for php in sorted(REST_DIR.glob("*.php")):
        for path in REGISTER.findall(php.read_text(encoding="utf-8")):
            found.setdefault(shape(path), []).append(f"{php.name}: {path}")
    return found


def main() -> int:
    app = app_paths()
    server = server_paths()

    unrouted = sorted(key for key in app if key not in server)
    uncalled = sorted(
        key for key in server
        if key not in app
        and not any(("/" + key).startswith(prefix) for prefix in BROWSER_ONLY)
    )

    calls = sum(len(labels) for labels in app.values())
    routes = sum(len(labels) for labels in server.values())
    print(f"{calls} app calls against {routes} registered routes")

    if uncalled:
        print(f"\nnote — {len(uncalled)} routes no app call reaches:")
        for key in uncalled:
            for label in server[key]:
                print(f"  {label}")

    if unrouted:
        print(f"\n{len(unrouted)} app calls with no route:")
        for key in unrouted:
            for label in app[key]:
                print(f"  {label}")
        return 1

    print("\nevery app call has a route")
    return 0


if __name__ == "__main__":
    sys.exit(main())
