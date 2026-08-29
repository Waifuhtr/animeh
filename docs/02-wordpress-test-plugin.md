# WordPress Player Test Eklentisi

Aşama 2. Player'ı gerçek bir WordPress ortamında, gerçek kaynaklarla test etmek
için yönetim paneli; ve altyazıların ihtiyaç duyduğu fontları yönetmek için bir
kayıt defteri.

Eklenti **atılacak bir test aracı değil**: brief §7 her şeyi tek bir eklentide
topluyor. Yapı, Aşama 3'ün (anime/bölüm/kullanıcı backend'i) içine büyüyeceği
şekilde kuruldu; şimdilik yalnızca test yüzeyi dolduruldu.

## Kurulum

```
dist/animeh-0.1.0.zip
```

Eklentiler → Yeni Ekle → Eklenti Yükle. Etkinleştirdikten sonra yönetim
menüsünde **Animeh** görünür.

Etkinleştirme sırasında:
- iki tablo oluşturulur (`{prefix}animeh_fonts`, `{prefix}animeh_test_sessions`)
- `wp-content/uploads/animeh-fonts/` klasörü açılır
- `animeh_manage_player_tests` yetkisi `administrator` rolüne eklenir

Gereksinimler: WordPress 6.0+, PHP 8.0+.

Zip'i kendin üretmek istersen: `./tools/build-plugin.sh` (Node gerekir).

## Mimari

Bu ortamda WordPress kurулamıyor — `wordpress.org` ve `api.github.com` çıkış
politikasında engelli — dolayısıyla Composer da paket indiremiyor. Bu kısıt
mimariyi belirledi:

> **WordPress'e dokunan katman ince tutuldu; mantık, WordPress'e hiç ihtiyaç
> duymayan saf PHP sınıflarında yaşıyor.**

Bu zaten iyi mimari, ama burada aynı zamanda test edilebilirliğin tek yolu.

```
wordpress-plugin/animeh/
├── animeh.php              bootstrap, sürüm guard, autoloader
├── uninstall.php           silme sırasında temizlik
├── src/
│   ├── Plugin.php          tek hook kayıt noktası
│   ├── Support/            ← WordPress yok; burada test ediliyor
│   │   ├── FontFile.php        SFNT doğrulama + aile adı okuma
│   │   ├── AssScript.php       ASS'ten font ailesi çıkarımı
│   │   ├── PlaylistRewriter.php HLS URI yeniden yazımı
│   │   ├── UrlGuard.php        SSRF savunması
│   │   ├── Throttle.php        bayt/saniye hesabı
│   │   └── TestVerdict.php     ölçümlerden karar
│   ├── Storage/            $wpdb erişimi, şema
│   ├── Rest/               yetki + endpoint'ler
│   ├── Admin/              menü + asset yükleme
│   └── Media/ProxyHandler  Range destekli, hız sınırlı akış
└── assets/
    ├── player/             player kütüphane build'i (üretilir)
    ├── jassub/             libass worker + wasm (üretilir)
    └── admin/              panel JS/CSS (kaynak)
```

Panel arayüzü, player'ın kendi geliştirme harness'iyle **aynı widget'ları**
kullanır (`player/src/panel/`). İki kopyanın zamanla ayrışmaması için.

## Yetkilendirme

`animeh_manage_player_tests` yetkisi. `manage_options` yerine ayrı bir yetki,
çünkü altyazı işini bir editöre devretmek için tüm siteyi devretmek gerekmesin.

Her REST rotasında gerçek bir `permission_callback` var; `__return_true`
hiçbir yerde geçmiyor. Brief §8'in kuralı korunuyor: **yetkiyi sunucu belirler**,
istemcinin iddiası hiçbir şey açmaz. Panel `can_manage` bilgisini yalnızca hangi
kontrolü çizeceğine karar vermek için kullanır.

## REST sözleşmesi

Namespace `animeh/v1`. Tarayıcıdan çağrılırken `X-WP-Nonce` zorunlu.

| | |
| --- | --- |
| `GET /test/config` | panel açılış verisi |
| `GET/POST /test/presets`, `DELETE /test/presets/{id}` | kayıtlı kaynaklar |
| `POST /test/sessions` | koşu başlat |
| `PATCH /test/sessions/{id}` | ölçüm + log ekle |
| `GET /test/sessions`, `GET/DELETE /test/sessions/{id}` | geçmiş |
| `GET /fonts`, `POST /fonts`, `DELETE /fonts/{id}` | font kayıt defteri |
| `GET /fonts/resolve?family=` | tek font sorgusu → `{url}` veya 404 |

Koşunun **kararı sunucuda** verilir (`TestVerdict`), istemciden alınmaz: sonradan
güvenilecek kayıt odur ve tarayıcı istediğini gönderebilir.

## Font akışı (brief §14–15)

```
Player ASS'i parse eder
        ↓  eksik aile adları
Panel:  ✗ Animeh Gothic    bulunamadı  [Font Yükle]
        ↓  POST /fonts (multipart)
FontFile dosyanın kendisini doğrular:
  · sfnt sürüm etiketi (uzantıya değil içeriğe bakılır)
  · tablo dizini tutarlılığı — dosya sınırlarını aşan bir dizin, kırpılmış
    ya da uydurulmuş bir yüklemenin imzasıdır
  · `name` tablosundan aile adı
  · sha256 ile tekilleştirme
        ↓
Player resolver'a tekrar sorar → font gelir → libass'a eklenir
```

Fontlar `wp-content/uploads/animeh-fonts/` altında, içerik hash'iyle
adlandırılarak saklanır — diskteki ad hiçbir zaman yüklenen şeyden
etkilenemez. Klasöre `index.php` ve PHP çalıştırmayı reddeden bir `.htaccess`
konur.

**Sitenin genel `upload_mimes` ayarı değiştirilmez.** `.ttf`/`.otf`'yi tüm siteye
açmak, bu dosyalar yalnızca libass'a servis edilecekken gereksiz bir güvenlik
yüzeyi olurdu.

Eşleştirme **dosya adına göre değil aile adına göre** yapılır: `DejaVuSans.ttf`
dosyasının ailesi "DejaVu Sans"tır ve altyazı onu böyle ister. Yüklenen fontun
ailesi aranan aile değilse panel bunu açıkça söyler — sessizce kabul etmez.

## Hız kısıtlama proxy'si

`admin-post.php?action=animeh_media_proxy`. REST değil, çünkü REST katmanı
çıktıyı tamponluyor ve akış için uygun değil.

- `Range` isteklerini karşılar (MKV bunun üzerine kurulu)
- `?kbps=N` — bayt/saniye sınırı
- `?fail=N` — ilk N isteği düşürür, kurtarma merdivenini test etmek için

**HLS playlist'leri yeniden yazılır.** Bir playlist'in girdileri kendi adresine
görelidir; master playlist'i proxy üzerinden dokunmadan servis etmek,
tarayıcının `720p/index.m3u8`'i proxy'nin yoluna göre çözmesine ve hiçbir yere
gitmesine yol açar. `PlaylistRewriter` her URI'yi orijinal adrese göre çözer ve
kısıtlama ayarını koruyarak proxy'ye geri yönlendirir. (Bu, tarayıcı testinde
bulunup düzeltilen gerçek bir hataydı.)

### SSRF savunması

Bu, eklentinin en riskli parçası: yetkili bir kullanıcının rastgele URL
çektirebilmesi klasik bir saldırı yüzeyi. `UrlGuard`:

- yalnızca `http`/`https`
- URL içinde kimlik bilgisi reddedilir
- **çözümlenmiş IP'ye bakılır**, sadece hostname'e değil — DNS rebinding kapalı
- host'un çözümlendiği **her** adres kontrol edilir; biri özelse istek reddedilir
- bloklanan aralıklar: 127/8, 10/8, 172.16/12, 192.168/16, 169.254/16 (bulut
  metadata adresi), 100.64/10, ::1, fc00::/7, ve IPv4-eşlenmiş IPv6 karşılıkları
- yönlendirme takip edilmez — yönlendirme, allowlist'i aşmanın klasik yoludur
- varsayılan olarak ayarlardaki host izin listesi zorunlu

### Operasyonel not

Proxy, akış süresince bir PHP işçisini tutar. php-fpm işçi sayısı sınırlı bir
sitede aynı anda birkaç kısıtlı test çalıştırmak diğer istekleri bekletebilir.
Bu yüzden proxy yalnızca yetkili kullanıcılara açık ve kısıtlama kapalıyken
medya doğrudan kaynağından çekilir.

## Doğrulama

### Bu ortamda gerçekten yapıldı

| | |
| --- | --- |
| PHP lint | 21 dosya, temiz |
| PHP birim testleri | **35/35** geçiyor |
| Panel tarayıcı testleri | **27/27** geçiyor |
| Zip bütünlüğü | açılıp lint ediliyor, testler dışarıda |

Birim testleri (`tests/run.php`, bağımlılıksız) gerçek dosyalar üzerinde çalışır:
`media/fonts/*.ttf` üzerinde aile adı okuma, kırpılmış dosyanın reddi, SSRF
kuralları, playlist yeniden yazımı. `AssScript` testleri player'ın TypeScript
testleriyle **aynı beklenen çıktıyı** doğrular, iki uygulamanın ayrışmadığını
görmek için.

Tarayıcı testleri (`tests/e2e/panel.mjs`) eklentinin **gerçek** admin JS'ini
`tests/stub-server.php` karşısında sürer — bu stub, aynı REST sözleşmesini
konuşur ve doğrulama ile font okuma için eklentinin kendi sınıflarını çağırır.
Kapsanan: panel açılışı, geçersiz URL reddi, HLS oynatma, dört eksik fontun
raporlanması, font yükleme → anında çözümlenme, yanlış font uyarısı, koşunun
kaydedilmesi, 700 kbps kısıtlı koşu (ölçülen: 699 kbps) ve font kütüphanesi
ekranı.

```bash
php wordpress-plugin/animeh/tests/run.php            # birim testleri
wordpress-plugin/animeh/tests/stub-server.sh start   # stub sunucu
node wordpress-plugin/animeh/tests/e2e/panel.mjs     # tarayıcı testleri
wordpress-plugin/animeh/tests/stub-server.sh stop
```

### Bu ortamda yapılamadı

WordPress kurulamadığı için **WordPress API'lerine dokunan PHP çalışma anında
doğrulanmadı**: hook'ların tetiklendiği, `register_rest_route`'un davranışı,
`dbDelta` migrasyonu, gerçek kullanıcılarla yetki kontrolleri, `admin-post.php`
akışı. Bunlar dikkatle yazıldı ama ilk gerçek çalıştırma senin kurulumunda
olacak.

Riski küçültmek için mantığın ağırlığı `Support/` ve panel JS'inde — ikisi de
burada test edildi. WordPress katmanı kasıtlı olarak ince: hook kaydı, `$wpdb`
çağrıları ve şema tanımı.

## Sorun giderme

**Menüde "Animeh" görünmüyor.** Yetki eksik olabilir. Eklentiyi devre dışı
bırakıp yeniden etkinleştir; `animeh_manage_player_tests` yetkisi `administrator`
rolüne o sırada eklenir. Panel ayrıca `manage_options` olanları da kabul eder.

**"Oynatıcı paketi yüklenemedi."** `assets/player/` ve `assets/jassub/`
klasörleri eksik kopyalanmış. Zip'i yeniden yükle; toplam ~5.5 MB olmalı.

**Tablolar oluşmadı.** Şema sürümü her yüklemede kontrol edilir (dosya kopyalayarak
güncellenen bir eklenti etkinleştirme hook'unu hiç çalıştırmaz). Bir admin
sayfası açmak yeterli. Olmadıysa `wp-config.php` içinde `WP_DEBUG` açıp
`$wpdb->last_error` değerine bak.

**"Bu adres özel bir ağa işaret ediyor."** Kaynak yerel bir ağda. Test için
`animeh_settings` seçeneğindeki `allow_any_host` açılabilir, ama bunu üretimde
açık bırakma.

**Font yüklenmiyor.** `wp-content/uploads/` yazılabilir olmalı. PHP'nin
`upload_max_filesize` ve `post_max_size` değerleri font boyutundan büyük olmalı.

**Kısıtlı test çok yavaş veya takılıyor.** Proxy her akış için bir PHP işçisi
tutar. Paylaşımlı bir hostta işçi sayısı azsa aynı anda tek test çalıştır.

## Kapsam dışı (Aşama 3)

Anime/sezon/bölüm CRUD, kullanıcı kayıt/giriş, Tenrai entegrasyonu ve cache,
Backblaze B2 yükleme, izleme geçmişi ve favoriler, Android admin endpoint'leri.
Tablolar ve namespace bunları alacak şekilde adlandırıldı.
