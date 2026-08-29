# Animeh

Anime izleme platformu — Kotlin/Compose Android uygulaması, WordPress backend,
Tenrai metadata, Backblaze B2 depolama ve özel yüksek performanslı video
oynatıcı.

Geliştirme sırası: **önce player**, sonra WordPress player test eklentisi,
sonra WordPress backend, sonra Android uygulaması.

## Yapı

| Dizin                | İçerik                                                     |
| -------------------- | ---------------------------------------------------------- |
| `docs/`              | Mimari ve tasarım belgeleri                                 |
| `player/`            | `@animeh/player` — özel oynatıcı (TypeScript, framework'süz) |
| `wordpress-plugin/`  | WordPress eklentisi (player test paneli, font kayıt defteri) |
| `tools/`             | Geliştirme yardımcıları (test medyası, eklenti paketleme)    |

## Durum

**Aşama 1 (player) çalışıyor.** HLS ve MKV oynatma, ASS altyazı, font
çözümleme, özel kontroller ve zayıf bağlantı politikası uygulanmış ve
doğrulanmış durumda — 30 birim testi, Chromium'da 75 oynatma kontrolü.

**Aşama 2 (WordPress test eklentisi) çalışıyor.** Player test paneli, font
kayıt defteri ve eksik font yükleme akışı, Range destekli hız kısıtlama
proxy'si — 35 PHP birim testi, tarayıcıda 27 panel kontrolü.

| | |
| --- | --- |
| Player mimarisi | [`docs/01-player-architecture.md`](docs/01-player-architecture.md) |
| Player kullanımı | [`player/README.md`](player/README.md) |
| Eklenti | [`docs/02-wordpress-test-plugin.md`](docs/02-wordpress-test-plugin.md) |

Kurulabilir eklenti paketi: `./tools/build-plugin.sh` → `dist/animeh-<sürüm>.zip`

Sıradaki: Aşama 3 — WordPress backend (anime, bölüm, kullanıcı, API).
