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
| `wordpress-plugin/`  | WordPress eklentisi (backend, API, panel, B2, taşıma)        |
| `android/`           | Kotlin/Compose uygulaması, uygulama içi yönetim paneli       |
| `tools/`             | Geliştirme yardımcıları (test medyası, eklenti paketleme)    |

## Durum

**Aşama 1 (player) çalışıyor.** HLS, MKV ve progressive (MP4/WebM) oynatma, ASS
altyazı, font çözümleme, özel kontroller, zayıf bağlantı politikası ve yedek
adrese otomatik geçiş — 30 birim testi, Chromium'da 88 oynatma kontrolü.

**Aşama 2 (WordPress eklentisi) çalışıyor.** Player test paneli, font kayıt
defteri ve eksik font yükleme akışı, Range destekli hız kısıtlama proxy'si;
Backblaze B2 depolama, çok parçalı video yükleme, Friendly URL → S3 geçişi;
bucket'a yedekleme ve site taşıma.

**Aşama 3 (backend + API) yazıldı.** Katalog şeması, bearer token kimlik
doğrulama, kullanıcı/katalog/yönetim endpoint'leri, Tenrai istemcisi ve cache —
103 PHP birim testi, 22 SigV4 çapraz kontrolü, tarayıcıda 27 + 29 panel
kontrolü, 39 dosya lint temiz.

**Aşama 4 (Android) yazıldı, bu ortamda derlenemedi.** 61 Kotlin dosyası:
özel oynatıcı (Media3 motor + kendi UI/state/ABR'ı + ASS), çevrimdışı cache,
tüm kullanıcı ekranları ve uygulama içi yönetim paneli. Framework'e bağlı
olmayan katman derlendi ve 32 birim testi geçiyor; Android katmanı
doğrulanamadı çünkü `dl.google.com` bu ortamın çıkış politikasında 403.

| | |
| --- | --- |
| Player mimarisi | [`docs/01-player-architecture.md`](docs/01-player-architecture.md) |
| Player kullanımı | [`player/README.md`](player/README.md) |
| Eklenti | [`docs/02-wordpress-test-plugin.md`](docs/02-wordpress-test-plugin.md) |
| Depolama ve taşıma | [`docs/03-storage-and-migration.md`](docs/03-storage-and-migration.md) |
| Backend ve API | [`docs/04-backend-api.md`](docs/04-backend-api.md) |
| Android uygulaması | [`docs/05-android-app.md`](docs/05-android-app.md) |

Kurulabilir eklenti paketi: `./tools/build-plugin.sh` → `dist/animeh-<sürüm>.zip`

Sıradaki: APK derlemesi (bu ortamda `dl.google.com` engelli — bkz.
[`docs/05-android-app.md`](docs/05-android-app.md#8-derleme--bu-ortamda-yapılamadı)),
sonra Aşama 5 — manga.
