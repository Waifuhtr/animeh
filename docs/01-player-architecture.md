# Player Mimarisi

Bu belge Aşama 1'in (video player) mimarisini ve uygulanmış hâlini anlatır.
Anlatılan her şey `player/` altında çalışır durumdadır; henüz yapılmamış olanlar
"Sonraki adımlar" başlığı altında ayrıca belirtilmiştir.

## 1. Neden bu yapı

Player bu projenin kalbi ve en uzun ömürlü parçası. Aynı davranışın önce web'de
(WordPress test eklentisi), sonra Android'de (Media3) yaşaması gerekiyor. Bu
yüzden mimarinin ana kuralı şu:

> **Taşıma katmanı değiştirilebilir; durum modeli, altyazı hattı ve kontrol
> mantığı bize aittir ve iki platformda da aynıdır.**

hls.js ve libass birer *motor*. Player'ın gördüğü şey bu motorlar değil,
`MediaEngine` arayüzü ve `PlayerSnapshot`. Android'e geçerken değişen tek şey
motorun kendisi olacak.

## 2. Katmanlar

```
┌───────────────────────────────────────────────┐
│ PlayerUI                                      │  src/ui/
│  snapshot render eder, intent gönderir        │
│  kendi oynatma durumunu tutmaz                │
├───────────────────────────────────────────────┤
│ AnimehPlayer (controller)                     │  src/core/controller.ts
│  tek yetkili durum · komutlar · kurtarma      │
│  ┌──────────┬───────────┬──────────┬────────┐ │
│  │ Subtitle │ Font      │ Resume   │ Tele-  │ │
│  │ Engine   │ Registry  │ Store    │ metry  │ │
│  └──────────┴───────────┴──────────┴────────┘ │
├───────────────────────────────────────────────┤
│ MediaEngine                                   │  src/core/engine.ts
│   HlsEngine · MkvEngine                       │  src/engines/
├───────────────────────────────────────────────┤
│ NetworkMonitor · ThroughputEstimator · Policy │  src/net/
├───────────────────────────────────────────────┤
│ <video> + MediaSource                         │
└───────────────────────────────────────────────┘
```

UI ile motor birbirini hiç görmez. UI yalnızca `PlayerSnapshot` okur, controller'a
komut yollar. Bu ayrım sayesinde ekranda görünen durumun oynatılan durumla
çelişmesi mümkün değil.

## 3. MediaEngine arayüzü

Her motor aynı olayları konuşur (`src/core/engine.ts`):

| Olay | Anlamı |
| --- | --- |
| `ready` | manifest/başlık çözüldü, parçalar biliniyor |
| `qualitiesChanged` / `qualitySwitched` | kalite merdiveni ve aktif seviye |
| `audioTracksChanged` | ses parçaları |
| `subtitleTracksChanged` | konteyner içi altyazı parçaları |
| `subtitleHeader` | gömülü ASS başlığı (stiller) |
| `subtitleBlock` | tek bir altyazı olayı, akış sırasında |
| `fontsFound` | konteyner içindeki fontlar |
| `throughput` | ölçüm örneği (bayt / süre) |
| `loadingChanged` | motor veri çekiyor mu |
| `error` / `recovered` | hata ve kendiliğinden toparlanma |

Bu küme kasıtlı olarak Media3 listener'larına birebir oturacak şekilde seçildi:
`Player.Listener`, `Tracks`, `AnalyticsListener` karşılıkları var.

## 4. Durum modeli

`PlayerPhase` (`src/core/types.ts`) örnekteki dört durumdan daha ayrıntılı:

```
idle → loading → ready → playing ⇄ paused
                            ↓ ↑
                        buffering / seeking
                            ↓
                      reconnecting → playing
                            ↓
                          ended / error
```

Ayrım şuralarda önemli:

- **`buffering` ile `reconnecting` farklıdır.** Birincisi "tampon boşaldı,
  bekle"; ikincisi "bağlantı gitti, geri getirmeye çalışıyoruz". Kullanıcıya
  gösterilen şey de farklı: spinner ve uyarı bandı.
- **`loading` ayrı bir bayraktır, faz değil.** Motor ileriyi doldururken
  oynatma sürüyor olabilir; bu bir duraklama değildir.

UI durumu (kilit, tam ekran, açık menü) motor durumundan ayrı tutulur — brief'in
12. maddesindeki "UI state ile media engine state karıştırılmasın" kuralı.

## 5. HLS

`HlsEngine` hls.js'i yalnızca taşıma olarak kullanır. Politika bizim:

- Başlangıç seviyesi ölçülen bant genişliğinden `pickStartLevel` ile seçilir
  (güvenlik katsayısı 0.6 — başlangıç tahminin en güvenilmez olduğu andır).
- `capLevelToPlayerSize` açık: gösterilmeyen piksel indirilmez.
- `abrBandWidthUpFactor` (0.7) `abrBandWidthFactor`'dan (0.9) düşük. Yukarı
  çıkmak aşağı inmekten risklidir, daha fazla kanıt ister.
- Retry bütçeleri manifest / playlist / fragment için ayrı ayarlanmıştır.
  Manifest ucuzdur, peşine düşülür; yüklenmeyen bir fragment yerine daha düşük
  bitrate'li olanı koymak daha iyidir.

## 6. MKV desteği

Tarayıcıların hiçbiri MKV'yi MSE'ye demux etmez. Bu yüzden konteyner
`src/mkv/` altında bizim tarafımızdan çözülür ve akış anında parçalı MP4'e
(fMP4) remux edilir.

```
MKV (HTTP Range)
   │
   ├─ EBML/Matroska demuxer ──┬─→ Tracks, Cues (arama indeksi)
   │   src/mkv/demuxer.ts     ├─→ Attachments  → FontRegistry
   │                          └─→ S_TEXT/ASS   → SubtitleEngine
   │
   └─ video/ses paketleri
         │
         ├─ codec eşleme (avcC/hvcC/vpcC/av1C, esds/dOps/dfLa)
         │   src/mkv/codecs.ts
         └─ fMP4 remux (moov + moof/mdat)  → SourceBuffer
             src/mkv/remuxer.ts
```

Bu yolun asıl kazancı oynatmanın ötesinde: **düzgün mux edilmiş bir MKV, ASS
altyazısını ve o altyazının kullandığı fontları kendi içinde taşır.** Aynı
demux'tan hem altyazı hem fontlar çıktığı için böyle bir dosya için font arama
sorunu tamamen ortadan kalkar.

### 6.1 Çözülen üç zor nokta

**Küme boyutu.** Mux'lar 5 saniyelik, 1080p'de birkaç MB'lık kümeler yazar. Tam
kümeyi beklemek ilk kare için megabaytlarca indirme demekti — tam da kaçınmaya
çalıştığımız açılış takılması. `ClusterStream` küme içinde nerede kaldığını
takip eder ve blokları geldikçe verir.

**Decode zaman damgaları.** Matroska kareleri decode sırasında saklar ama
*sunum* zamanıyla damgalar; MP4 ise decode zamanı + composition offset ister.
i'inci karenin decode zamanı, akıştaki i'inci en küçük sunum zamanıdır. Bu
sıralama **akış genelinde** yapılmak zorunda: her fragment'i kendi içinde
sıralamak, sınırda geri giden bir DTS üretir (bulundu ve düzeltildi).

**Negatif composition offset.** Yeniden sıralanan kareler için offset doğal
olarak negatiftir. `trun` v1 buna izin verir, ama negatif offset aynı zamanda
"mux DTS'i kaydırdı" sinyali olarak da okunur; demuxer'lar kaymayı geri
ekleyince tüm sunum zaman çizgisi 84 ms öteleniyordu. Çözüm: her offset'e sabit
1 saniyelik bir sapma eklemek ve `SourceBuffer.timestampOffset` ile geri almak.
Böylece medya zaman çizgisi konteynerin zaman çizgisiyle birebir aynı kalır —
arama, kaldığın yer ve altyazı senkronu için hiçbir yerde telafi gerekmez.

### 6.2 Desteklenen kodekler

| | Destekleniyor | Desteklenmiyor (açıkça raporlanır) |
| --- | --- | --- |
| Video | AVC, HEVC, VP9, AV1 | VP8 (MP4 karşılığı yok) |
| Ses | AAC, Opus, FLAC | AC3, E-AC3, DTS, TrueHD, Vorbis |
| Altyazı | ASS, SSA, SRT, WebVTT | VobSub, PGS (görüntü tabanlı) |

Desteklenmeyen bir **ses** parçası oynatmayı durdurmaz: video devam eder, hata
raporlanır. Bu, hangi parçanın yeniden kodlanması gerektiğini söyleyen bir
mesajdır — sessiz bir başarısızlık değil.

### 6.3 Arama

`Cues` öğesi zaman → küme ofseti indeksidir; SeekHead üzerinden ayrıca çekilir.
Arama, hedeften önceki en yakın cue'ya gidip demuxer ve remuxer'ı sıfırlar.
Cues yoksa arama sınırlıdır ve `seekable` false döner.

### 6.4 MKV'nin kabul edilen sınırı

Tek dosya MKV **tek renditiondır**; uyarlanabilir bitrate yoktur. Bağlantı
dosyanın bitrate'inin altına düşerse yapılabilecek tek şey tamponu korumaktır.
Bu yüzden production akış yolu HLS'tir; MKV doğrudan dosya kaynakları içindir.

## 7. Düşük bağlantı politikası

`src/net/policy.ts` bağlantıyı üç kademeye ayırır ve her şey bu değere bağlanır.

| | poor | moderate | good |
| --- | --- | --- | --- |
| Açılış tamponu | 2 sn | 3 sn | 4 sn |
| İleri tampon | 60 sn | 45 sn | 30 sn |
| Geri tampon | 15 sn | 30 sn | 90 sn |
| Yükseklik tavanı | 480p | 720p | — |

Sezgiye aykırı gelen kısım: **bağlantı ne kadar kötüyse ileri tampon o kadar
derin.** Zayıf bir hattı hızlandıramazsınız ama önden koşturabilirsiniz; gelen
her baytı biriktirmek doğru olan. Hızlı açılış ise kısa tampondan değil düşük
bitrate seçmekten gelir — 400 kbps'te bile az bayt hızlı gelir. Brief'in
istediği "startup buffering ile stall prevention arasındaki denge" tam olarak
bu ayrımdır.

`saveData` açıksa kademe ölçümden bağımsız `poor` kabul edilir ve tavan 360p'ye
iner: kullanıcı ne istediğini söylemiştir.

Bant genişliği tahmini iki farklı yarı ömürlü EWMA'nın **küçüğüdür**. Hızlı
ortalama tünele girildiğinde tepki verir, yavaş olanı tek bir yavaş segment
yüzünden bitrate'in yalpalamasını engeller. Küçüğünü almak tahmini karamsar
yapar — telefonda yanılmak istediğiniz yön budur.

## 8. Altyazı ve ASS

Render libass (WebAssembly) ile yapılır. Karaoke zamanlaması, `\pos`, döndürme,
glif kenarlıkları ve çizim komutları formatın varlık sebebidir; DOM tabanlı bir
yaklaşım bunları izleyicinin hemen fark edeceği şekilde yanlış yapar.

Bizim olan kısım libass'ın etrafı:

- **Gömülü altyazı akışı.** Matroska her olayı ayrı blokta, zamanlaması blok
  başlığında taşır. Bloklar libass'ın `ass_process_chunk`'ının beklediği formda
  olduğu için doğrudan iletilir; her satırda tüm script yeniden kurulmaz.
- **Başlangıç yarışı.** Bloklar oynatmayla birlikte, wasm render'ı açılmadan
  önce gelmeye başlar. Erken bloklar kuyruğa alınır ve track kurulunca
  boşaltılır — aksi hâlde bölümün ilk replikleri sessizce kaybolur (bulundu ve
  düzeltildi).
- **SRT ve WebVTT** minimal bir ASS'e çevrilir. Tek render yolu, tek konumlama
  ve ölçekleme davranışı demektir.

## 9. Font yönetimi

Bir script fontu **iki yerde** ister: stillerin `Fontname` sütunu ve replik
içindeki `\fn` override'ları. İkisini birden toplamayan bir tarama, bazı
satırların yanlış yüzle çizilmesine yol açar.

Çözüm sırası — güvenden başlayarak:

```
1. Zaten elimizde olan (cache)
2. Konteynerin taşıdığı (MKV attachment)      ← sürümün typeset edildiği yüz
3. Backend'in yayınladığı (WordPress registry)
4. Lisanslı bir genel kaynak (opsiyonel, varsayılan kapalı)
5. Bulunamadı → rapor
```

Attachment'lar dosya adıyla değil, **fontun kendi `name` tablosundan okunan aile
adıyla** eşleştirilir (`src/fonts/sfnt.ts`): `DejaVuSans.ttf` dosyasının ailesi
"DejaVu Sans"tır ve script onu böyle ister.

İki kural bilinçli:

- **Gömülü font, backend fontunu ezer.** Sürümün kendi yüzü, benzer isimli bir
  ikameden her zaman doğrudur.
- **Varsayılan genel font çözücü yoktur.** Rastgele bir siteden font çekmek hem
  lisans riski hem de daha kötüsü: yanlış ama *mevcut* bir font, eksik olandan
  çok daha zor fark edilir. Brief'in 14. maddesinin kuralı.

Rapor, fontlar indirilmeden **önce** yayınlanır. Hangi fontun eksik olduğu
çözümleme biter bitmez bilinir; raporu megabaytlık bir indirmenin arkasına
koymak, admin panelini tam da bağlantının yavaş olduğu anda boş bırakırdı.

Eksik font çıktısı doğrudan brief'in 15. maddesindeki panele beslenir:

```
⚠ Eksik fontlar
  Animeh Nonexistent Gothic          [Font Yükle]
```

## 10. Hata yönetimi

`PlayerError` teknik ayrıntı ile kullanıcı mesajını ayrı tutar. Arayüz yalnızca
`userMessage` gösterir; `code` destek talebinde neyin bozulduğunu söylemek için
vardır. Kodlar brief'in 25. maddesindeki kümedir (`NETWORK_ERROR`, `AUTH_ERROR`,
`VIDEO_ERROR`, `SUBTITLE_ERROR`, `FONT_MISSING`, `STORAGE_ERROR`, …) artı
konteyner/kodek ayrımı için `CONTAINER_ERROR`, `MEDIA_UNSUPPORTED`.

Kurtarma merdiveni:

1. **İstek düzeyi** — `fetchBytes` üstel geri çekilme + jitter ile yeniden
   dener. Yalnızca denemeye değer olanı: 408, 429, 5xx, timeout. İmzalı bir
   URL'deki 403 sekiz saniye sonra da 403'tür; hemen başarısız olup çağıranın
   token yenilemesine izin verir.
2. **Motor düzeyi** — HLS'te ağ hatası `startLoad`, medya hatası
   `recoverMediaError`, ikincisinde codec takası. MKV'de okuma ofseti zaten
   ilerlemiş olduğundan pompayı yeniden çalıştırmak yeterlidir.
3. **Controller düzeyi** — kurtarılabilir bir hata `reconnecting` fazına geçirir
   ve izleyici hiçbir şey görmez. Yalnızca kurtarılamayan bir hata ekrana çıkar.

Ağ geri geldiğinde (`online` olayı) kurtarma otomatik tetiklenir.

## 11. Arayüz

Framework'süz düz DOM. Aynı işaretleme bir WordPress admin sayfasında, bağımsız
test panelinde ve ileride bir WebView'da çalışmak zorunda; hiçbirinin bir
framework üzerinde anlaşması gerekmemeli.

Hazır bir streaming arayüzü kopyalanmadı; konsept görsel yönlendirme olarak
kullanıldı. İçerik:

- Üst: başlık, sezon/bölüm satırı, ses / altyazı / hız / kalite menüleri
- Orta: önceki bölüm, 10 sn geri, oynat-duraklat, 10 sn ileri, sonraki bölüm
- Alt: zaman, jenerik işaretli ilerleme çubuğu, ses, kilit, tam ekran
- Üstüne binenler: spinner, çevrimdışı/veri tasarrufu bandı, "Jeneriği atla",
  hata ekranı, hata ayıklama katmanı (`d`)

Kontroller **viewport'a değil player kutusuna** göre boyutlanır (CSS container
query). Bir telefonda satır içi 16:9 player'ın yüksekliği ~200 pikseldir ve
pencere genişliğine bakan bir media query bunu göremez; kutu kısaldığında
hedefler küçülür, bölüm satırı kalkar, orta sıra iki bar arasına sıkışır.

Klavye: `space`/`k` oynat, `←`/`→` 10 sn, `↑`/`↓` ses, `m` sessiz, `f` tam
ekran, `c` altyazı döngüsü, `d` hata ayıklama, `Escape` menü kapat.

## 12. Kaldığın yerden devam

`ResumeStore` bir arayüzdür. Geliştirmede `localStorage`, production'da
WordPress'in izleme geçmişi endpoint'i — ilerlemenin cihazlar arasında takip
etmesi gerektiği için. 5 saniyede bir ve duraklama/bitiş anlarında yazılır.
Sondan 30 saniye "tamamlandı" sayılır, böylece jeneriğin ortasına dönülmez.

## 13. Test altyapısı

Test medyası `tools/make-test-media.sh` ile üretilir (git'e girmez):
4 kaliteli H.264/AAC HLS merdiveni, gömülü ASS ve 3 attached font taşıyan MKV,
Opus varyantı ve telifsiz kodeklerle VP9+Opus kopyaları.

Dev sunucusu gerçek HTTP Range destekler (MKV bunun üzerine kurulu) ve
**kasıtlı olarak bozulabilir**: `/media/__throttle?kbps=700` bant genişliğini
sınırlar, `?fail=N` ilk N isteği düşürür. Tamponlama ve kurtarma davranışı hızlı
bir yerel bağlantıda değerlendirilemez.

Mevcut durum:

| | |
| --- | --- |
| Birim testleri | 26 / 26 geçiyor |
| Tarayıcı kontrolleri | 63 / 63 geçiyor |

Birim testleri gerçek dosyalar üzerinde çalışır: MKV başlık çözümleme, parçalı
küme okuma, ve remux edilen çıktının ffprobe tarafından **kare kare** decode
edilmesi + her sunum zaman damgasının korunması. Tarayıcı kontrolleri beş
senaryoyu kapsar: temiz MKV, temiz HLS, 3 Mbps MKV, 700 kbps HLS (ABR 360p'ye
iniyor), ve ilk iki isteği başarısız olan HLS.

### Ortam notu

Playwright'ın Chromium'u açık kaynak derlemedir; **H.264 ve AAC decode etmez.**
Bu yüzden tarayıcı doğrulaması VP9+Opus korpusuyla yapılır. H.264/AAC yolu
ffprobe turu ile ayrıca doğrulanmıştır. Gerçek Chrome/Edge ve Android
(Media3) her iki kodeği de destekler.

## 14. Sonraki adımlar

### Aşama 2 — WordPress test eklentisi

Player zaten framework'süz ve statik URL'lerle çalıştığı için eklentiye
taşınması bir paketleme işi:

- `player/dist/` çıktısı eklenti içinde `wp_enqueue_script` ile yüklenir
- `src/demo/` içindeki panel yerleşimi admin sayfasına dönüşür (aynı düzen)
- `FontReport.missing` doğrudan "Font Yükle" akışını besler
- Ölçümler (`stats()`) log endpoint'ine yazılır

### Aşama 4 — Android

Karşılıklar:

| Web | Android |
| --- | --- |
| `MediaEngine` | `ExoPlayer` + `MediaSource.Factory` |
| `PlayerSnapshot` | `StateFlow<PlayerSnapshot>` |
| `PlayerUI` | Compose, aynı snapshot'tan render |
| `policy.ts` | `DefaultLoadControl` + `AdaptiveTrackSelection` |
| MKV demux/remux | **gereksiz** — Media3'ün Matroska extractor'ı var |
| libass (wasm) | libass (NDK) veya eşdeğer gerçek ASS renderer |
| `ResumeStore` | Room + WordPress senkronu |

MKV tarafı Android'de çok daha kolay: ExoPlayer Matroska'yı, gömülü ASS'i ve
font attachment'larını zaten okur. Bu belgedeki demuxer'ın *bilgisi* yine de
işe yarar — hangi kodeklerin desteklendiği, font eşleştirmesinin nasıl
yapılacağı ve altyazı bloklarının hangi biçimde geldiği aynıdır.

Android build bu oturumda **yapılamadı**: `dl.google.com` bu ortamın çıkış
politikasında engelli, dolayısıyla Android SDK ve Google Maven (androidx/media3)
erişilemez durumda.

## 15. Bilinen sınırlar

- MKV'de mid-file ses parçası değiştirme uygulanmadı (yeniden remux gerektirir);
  arayüz bunu sessizce yapmak yerine açıkça söyler.
- Görüntü tabanlı altyazılar (VobSub, PGS) desteklenmiyor.
- MKV için `Cues` yoksa arama sınırlı.
- Genel font çözücü arayüzü var ama hiçbir sağlayıcı bağlı değil (bilinçli).
