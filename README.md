# Animeh

Anime streaming platform — Kotlin/Compose Android app, WordPress backend, Tenrai metadata,
Backblaze B2 storage, and a custom high-performance video player.

Development order (see `docs/`): **player first**, then the WordPress player-test plugin,
then the WordPress backend, then the Android app.

## Layout

| Path      | What                                                                 |
| --------- | -------------------------------------------------------------------- |
| `docs/`   | Architecture and design documents                                     |
| `player/` | `@animeh/player` — the custom player engine (TypeScript, no framework) |
| `tools/`  | Development helpers (test media generation, etc.)                      |

## Status

Phase 1 (player) is in progress. See `docs/01-player-architecture.md`.
