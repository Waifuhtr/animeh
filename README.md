# Animeh

Anime izleme platformu — Kotlin/Compose Android uygulaması, WordPress backend,
Tenrai metadata, Backblaze B2 depolama ve özel yüksek performanslı video
oynatıcı.

Geliştirme sırası: **önce player**, sonra WordPress player test eklentisi,
sonra WordPress backend, sonra Android uygulaması.

## Yapı

| Dizin     | İçerik                                                            |
| --------- | ----------------------------------------------------------------- |
| `docs/`   | Mimari ve tasarım belgeleri                                        |
| `player/` | `@animeh/player` — özel oynatıcı (TypeScript, framework'süz)        |
| `tools/`  | Geliştirme yardımcıları (test medyası üretimi)                      |

## Durum

**Aşama 1 (player) çalışıyor.** HLS ve MKV oynatma, ASS altyazı, font
çözümleme, özel kontroller ve zayıf bağlantı politikası uygulanmış ve
doğrulanmış durumda — 26 birim testi, Chromium'da 63 oynatma kontrolü.

Ayrıntı: [`docs/01-player-architecture.md`](docs/01-player-architecture.md) ·
Kullanım: [`player/README.md`](player/README.md)

Sıradaki: Aşama 2 — WordPress player test eklentisi.
