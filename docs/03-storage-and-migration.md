# Depolama, Yükleme ve Site Taşıma

Aşama 2'nin ikinci yarısı: videoların nerede durduğu, uygulamadan oraya nasıl
gittiği, ve site ya da alan adı düşerse kütüphanenin nasıl ayakta kaldığı.

Üç ayrı iş gibi görünüyor ama tek bir karara bağlılar: **veri WordPress'te
değil, bucket'ta yaşar.** WordPress kataloğu tutar (hangi anime, hangi bölüm,
hangi altyazı); bucket dosyaları tutar; ve kataloğun kendisi de düzenli olarak
bucket'a yazılır. Böylece hosting kaybedildiğinde geriye kalan şey, kütüphaneyi
yeniden kurmaya yeten tek bir yer olur.

---

## 1. Bucket yapılandırması

**Animeh → Depolama.**

| Alan | Ne işe yarıyor |
| --- | --- |
| Bölge | `us-west-004` gibi. Endpoint boş bırakılırsa bundan türetilir. |
| Bucket adı | Backblaze'deki bucket. |
| S3 endpoint | `s3.us-west-004.backblazeb2.com`. Tam URL yapıştırırsan host'a indirgenir. |
| Uygulama anahtarı kimliği | Backblaze application key ID. |
| Uygulama anahtarı | Application key. **Tek yönlü**: kaydedilir, bir daha okunamaz. |
| Friendly URL tabanı | CDN veya özel alan adı. Boşsa yalnızca S3 adresi kullanılır. |
| Bucket herkese açık | Açıksa düz URL, kapalıysa her oynatma için imzalı URL. |
| İmzalı bağlantı ömrü | Presigned URL kaç saniye geçerli. |

### Anahtar nasıl saklanıyor

Uygulama anahtarı `SecretBox` ile AES-256-GCM kullanılarak şifrelenip
saklanıyor. Şifreleme anahtarı `wp-config.php` içindeki tuzlardan türetiliyor —
yani şifreli metin veritabanında, anahtar dosyada; ikisini birden almak
gerekiyor.

Anahtar **hiçbir endpoint'ten geri dönmüyor**. Panel yalnızca maske görüyor
(`K005…wxyz`). Formu boş bırakıp kaydedersen kayıtlı anahtar korunur, silinmez.
Böylece bucket adını değiştirmek için anahtarı tekrar yapıştırmak gerekmiyor.

OpenSSL yoksa değer işaretli düz metin olarak saklanır ve panel bunu açıkça
söyler — sessizce "şifreli" demez.

### Bağlantıyı Sına

**Bağlantıyı Sına** düğmesi bucket'a gerçek bir `ListObjectsV2` isteği atar ve
gecikmeyi ölçer. `HEAD` yerine liste isteği kullanılıyor, çünkü `HEAD` yalnızca
bucket'ın var olduğunu söyler; liste isteği anahtarın **okuma yetkisi olduğunu**
da söyler, ki asıl merak edilen odur.

Hata dönerse operatöre ne yapacağı söylenir, ham XML değil:

| Backblaze | Panelde |
| --- | --- |
| `SignatureDoesNotMatch` | Uygulama anahtarı ya da kimliği yanlış |
| `AccessDenied` | Anahtarın bu bucket'a yetkisi yok |
| `NoSuchBucket` | Bucket adı ya da bölge yanlış |
| `InvalidAccessKeyId` | Anahtar kimliği bu hesapta yok |
| `RequestTimeTooSkewed` | Sunucu saati kaymış |

---

## 2. Klasör düzeni

Backblaze'de gerçek klasör yok; anahtar tek bir düz metin ve `/` sadece
görüntüleme kuralı. Ama konsol bu ayraca göre gruplar, dolayısıyla düzen
operatörün elle bir şey bulup bulamayacağını belirler. Klasörlerin veritabanı
id'siyle değil anime adıyla adlandırılmasının tek sebebi bu.

```
anime/
└── shingeki-no-kyojin/
    ├── season-03/
    │   ├── episode-001.mp4
    │   ├── episode-001.tr.ass
    │   └── episode-002.mp4
    └── fonts/
        └── DejaVuSans.ttf
_animeh/
├── snapshots/
│   └── 2026-08-30T112230Z.json.gz
└── backend.json
```

- **Slug** başlıktan üretilir; Türkçe karakterler önce çevrilir (`ı→i`, `ş→s`,
  `ğ→g`), böylece "Şingeki" boşa düşmez. Tamamen Japonca bir başlık gibi
  çeviriden hiçbir şey kalmayan durumda `anime-{id}`'ye düşülür — boş ya da
  bozuk bir klasör adı üretmek yerine.
- **Sezon ve bölüm sıfır dolgulu** (`season-03`, `episode-001`), çünkü konsol
  metne göre sıralar ve dolgu olmadan 10. bölüm 2. bölümden önce gelir.
- **Fontlar bölüm başına değil anime başına**: aynı font sürekli tekrar
  yüklenmesin diye.
- `_animeh/` eklentinin kendi defteri; medyadan ayrı tutuluyor ki bucket
  başka bir şeyle paylaşılsa bile eklenti yalnızca kendi koyduklarını
  listeleyip temizleyebilsin.

---

## 3. Video yükleme

Uygulamadan bir bölüm yüklendiğinde dosya **WordPress'ten geçmez**. Geçseydi
paylaşımlı bir hostta `upload_max_filesize`, `max_execution_time` ve bellek
sınırlarının hepsine aynı anda çarpardı.

Bunun yerine:

```
Uygulama                WordPress                    Backblaze
   │                        │                            │
   ├─ POST /storage/uploads ┤                            │
   │   başlık, sezon, bölüm │                            │
   │   dosya adı, boyut     ├─ CreateMultipartUpload ───▶│
   │                        │◀── upload_id ──────────────┤
   │                        ├─ parça başına imzalı URL   │
   │◀─ {upload_id, parts[]} ┤                            │
   │                                                     │
   ├─ PUT parça 1 ──────────────────────────────────────▶│
   ├─ PUT parça 2 ──────────────────────────────────────▶│
   │◀─ ETag'ler ─────────────────────────────────────────┤
   │                        │                            │
   ├─ POST /uploads/complete┤                            │
   │   upload_id, ETag'ler  ├─ CompleteMultipartUpload ─▶│
   │◀─ {key, url}  ─────────┤                            │
```

Parçalar 32 MB. Kimlik bilgileri sunucuda kalır; uygulamaya yalnızca tek bir
parçayı, altı saat boyunca yazabilen imzalı URL'ler gider.

8 MB'ın altındaki dosyalar (altyazı, font, kapak) sunucu üzerinden de
geçebilir — o boyutta ayrı bir imza turu zahmete değmez.

`CompleteMultipartUpload` yanıtı **200 dönse bile gövdesi kontrol edilir**: S3
uyumlu API'ler bu çağrıda hatayı 200 içinde `<Error>` olarak döndürebilir, ve
buna bakmamak yarım yüklenmiş bir bölümü başarılı saymak demektir.

Yükleme yarıda kalırsa `POST /storage/uploads/abort` parçaları temizler;
bırakılan parçalar aksi hâlde depolama olarak faturalanır.

---

## 4. Oynatma adresi ve Friendly URL → S3 geçişi

`POST /storage/playback` bir anahtar alır, oynatılabilir adresleri döner:

```json
{
  "url": "https://cdn.ornek.com/anime/x/season-01/episode-001.mp4",
  "fallbackUrls": ["https://s3.us-west-004.backblazeb2.com/bucket/anime/x/..."],
  "expiresIn": 3600
}
```

- **Herkese açık bucket**: önce Friendly URL, yedek olarak S3 adresi.
- **Özel bucket**: yalnızca imzalı S3 adresi. Friendly URL özel bucket'ta
  zaten çalışmaz, listeye koymak yanıltıcı olurdu.

Player bu listeyi sırayla dener. Friendly URL yanıt vermezse — Backblaze'in CDN
katmanı ara sıra kararsız olabiliyor — **ilk kare gelmeden önce** sessizce bir
sonraki adrese geçilir ve panelde `sourceSwitched` olarak görünür. Video zaten
oynamaya başladıysa geçiş yapılmaz: o noktada sorun adres değil ağdır, ve
kaynağı değiştirmek izleyiciyi başa döndürmekten başka işe yaramaz.

Bu davranış tarayıcı testinde ölü bir birincil adresle doğrulanıyor
(`player/e2e/playback.mjs`, "Dead primary address, working fallback").

---

## 5. Site taşıma ve felaket kurtarma

**Animeh → Yedek ve Taşıma.**

Senin tarif ettiğin senaryo şuydu: eski sitede "veritabanını taşı" bölümü olsun,
yeni sitenin adresini gireyim, yeni siteye "admin veritabanını taşıyor, kabul
ediyor musun" bildirimi gelsin, kabul edince veri geçsin.

Bu, **planlı taşıma** için doğru bir akış. Ama asıl korunmak istenen durum —
hosting ya da alan adının düşmesi — bu akışın çalışamayacağı durum: eski site
gittiyse ondan hiçbir şey istenemez. Bu yüzden iki mekanizma var, ve senin
fikrin ikincisinin temeli olarak korundu; yalnızca yönü çevrildi.

### 5.1 Yedekler (asıl güvence)

Eklenti kendi tablolarını ve ayarlarını tek bir JSON zarfına yazıp bucket'a
koyar: `_animeh/snapshots/2026-08-30T112230Z.json.gz`.

- Elle **Şimdi Yedek Al**, ya da **günlük otomatik** (WP-Cron).
- Son 14 yedek saklanır, eskiler silinir.
- Gzip'lenir; yedeğin büyük kısmı tekrar eden JSON ve on kat küçülür.
- Tablo adları **öneksiz** yazılır. `wp_` sitesinden alınan yedek `wp_abc123_`
  önekli siteye geri yüklenir; öneki okuyan taraf koyar.
- Zarfın içinde bir **checksum** var: anahtarları her seviyede sıralanmış
  kanonik JSON üzerinden sha256. Sıralama şart, yoksa aynı veriden farklı özet
  çıkar ve doğrulama bozulmamış bir yedeği reddeder.
- Bir **format numarası** var. Daha yeni bir eklentinin yazdığı zarf, eski bir
  eklenti tarafından **reddedilir** — tanıdığı kısmı alıp gerisini atmak
  veriyi sessizce kaybetmek olurdu.

**Yedeğin içinde bucket kimlik bilgileri yoktur.** Bu bir eksiklik değil,
kural: yedek bucket'ın *içinde* duruyor, dolayısıyla bucket'ın anahtarlarını
içine koymak sızan tek bir nesneyi hesabın tamamına eşitlerdi. Zaten yedeği
okuyabilen bir sitenin o anahtarları alması gerekmiş demektir. Aynı sebeple
zarf içinde bu ayarların gelmesi **doğrulamada hata sayılır**, elle
düzenlenmiş bir dosya olsa bile.

### 5.2 Site kaybedildiğinde

```
1. Yeni bir WordPress kur, eklentiyi yükle.
2. Animeh → Depolama: aynı bucket bilgilerini gir.
3. Animeh → Yedek ve Taşıma: yedek listesi bucket'tan gelir.
4. "Bu yedeğe dön" → onayla.
5. "Bu Siteyi Aktif Backend Yap".
```

Eski siteden hiçbir şey istenmez. Elde olması gereken tek şey bucket
bilgileridir — ki videolar zaten orada olduğu için onlar zaten elde olmak
zorundadır.

### 5.3 Site hâlâ ayaktayken planlı taşıma

```
Eski site                          Yeni site
   │                                  │
   ├─ "Taşıma Kodu Üret"              │
   │   AYSKS-92HDZ-A2HBB-T3TYF        │
   │                                  ├─ eski sitenin adresi + kod
   │                                  ├─ "Veriyi Çek"
   │◀─ POST /migration/export ────────┤
   │   (kod doğrulanır)               │
   ├─ kütüphane ────────────────────▶ │
   │                                  ├─ doğrula, yaz, pointer'ı devral
```

Senin akışından tek farkı **yön**: veriyi eski site itmiyor, yeni site çekiyor.
Sebebi pratik — operatör yeni sitenin başında oturuyor, ve yeni site henüz NAT
arkasında ya da alan adı yönlenmemiş olabilir; bu durumların ikisinde de eski
sitenin yeni siteye ulaşma denemesi başarısız olur. Ters yönde ise böyle bir
sorun yok.

"Kabul ediyor musun" onayı kayboldu mu? Hayır — yerini **kod** aldı ve daha
güçlüsü oldu. Bir bildirime "evet" demek, bildirimi kimin gönderdiğini
doğrulamaz. Eski sitede üretilip yeni siteye elle yazılan bir kod ise iki
sitenin de aynı kişinin elinde olduğunu kanıtlar.

Kodun kuralları:

- 32 harfli Crockford alfabesi, **20 karakter = 100 bit**. Guessing söz konusu
  değil, ki bu önemli: kodu doğrulayan endpoint zorunlu olarak oturum
  gerektirmiyor.
- `I`, `L`, `O`, `U` alfabede yok — bir ekrandan okunup diğerine yazılırken
  karıştırılan harfler bunlar. Yine de biri `O` yazarsa `0` olarak okunur.
- Büyük/küçük harf, boşluk ve tireler önemsiz. `aysks 92hdz a2hbb t3tyf` de
  çalışır.
- **30 dakika** geçerli, **tek kullanımlık**.
- Veritabanında kodun kendisi değil **HMAC'i** durur; site tuzuyla anahtarlanır.
  Yani bir veritabanı okuması çalışan bir kimlik vermez, ve bir sitenin kodu
  başka bir siteyi açmaz.
- Karşılaştırma sabit zamanlı (`hash_equals`). Erken dönen bir karşılaştırma,
  yanıt süresini ölçebilen birine kodu karakter karakter söyler.

Kod doğrulaması `permission_callback` içinde yapılıyor, handler'da değil: kodu
geçemeyen bir çağrı için veri hiç toplanmıyor.

### 5.4 Uygulama yeni adresi nasıl buluyor

Her yedekten ve her "Bu Siteyi Aktif Backend Yap" işleminden sonra bucket'a
küçük bir işaret dosyası yazılır:

```json
_animeh/backend.json
{ "format": 1, "api_base": "https://yeni-site.com/wp-json/animeh/v1",
  "site_url": "https://yeni-site.com", "updated_at": "2026-08-30T11:22:30+00:00" }
```

Uygulama kayıtlı adrese ulaşamazsa bu dosyaya bakar ve orada yazan adrese
geçer. Elle yeni adres girmek de mümkün — senin istediğin gibi — ama gerekli
değil; kullanıcıların telefonundaki uygulama kendi kendine bulur.

Pointer **veri yerine oturduktan sonra** yazılır. Önce yazılsaydı uygulama,
kütüphaneyi henüz cevaplayamayan bir siteye yönlendirilmiş olurdu.

---

## 6. REST sözleşmesi

Hepsi `animeh/v1` altında ve hepsinde gerçek bir `permission_callback` var.

### Depolama

| | |
| --- | --- |
| `GET /storage/settings` | ayarlar (anahtar yok, maske var) |
| `POST /storage/settings` | kaydet (boş anahtar = kayıtlıyı koru) |
| `POST /storage/test` | bağlantı sınaması → `{bucket, endpoint, latency_ms}` |
| `POST /storage/uploads` | çok parçalı yükleme başlat → `{upload_id, parts[]}` |
| `POST /storage/uploads/complete` | tamamla → `{key, url}` |
| `POST /storage/uploads/abort` | iptal et, parçaları temizle |
| `GET /storage/objects` | önek altındaki nesneler |
| `POST /storage/playback` | oynatma adresleri → `{url, fallbackUrls, expiresIn}` |

### Taşıma

| | |
| --- | --- |
| `GET /migration/status` | ekranın açılış verisi |
| `GET /migration/snapshots` | bucket'taki yedekler |
| `POST /migration/snapshots` | şimdi yedek al |
| `POST /migration/schedule` | günlük yedeği aç/kapa |
| `POST /migration/restore` | `{key, confirm}` → geri yükle |
| `POST /migration/handoff` | taşıma kodu üret (kod yalnızca burada döner) |
| `DELETE /migration/handoff` | kodu geri çek |
| `POST /migration/export` | **kodla korunur**, oturum değil — kütüphaneyi verir |
| `POST /migration/pull` | `{source_url, code}` → eski siteden çek |
| `GET /migration/pointer` | bucket'taki işaret dosyası |
| `POST /migration/pointer` | bu siteyi aktif backend yap |

`/migration/pull` çağrısında verilen adres, medya proxy'siyle **aynı SSRF
kurallarından** geçer: yalnızca http/https, URL'de kimlik bilgisi yok,
çözümlenmiş IP özel bloklarda değil, yönlendirme takip edilmez. Yetkili bir
kullanıcının bir host adı yazmış olması, WordPress'in içinde bulunduğu özel ağı
yoklamasına izin vermek için yeterli bir sebep değil.

---

## 7. Doğrulama

### Bu ortamda gerçekten yapıldı

| | |
| --- | --- |
| PHP lint | 32 dosya, temiz |
| PHP birim testleri | **76/76** |
| SigV4 çapraz kontrolü | **22/22** (npm `aws4` ve AWS'nin kendi vektörü) |
| Panel tarayıcı testleri | **27/27** |
| Taşıma tarayıcı testleri | **29/29** (iki site, tek bucket) |

Taşıma testi iki stub sunucusunu aynı anda çalıştırır — eski site 8765, yeni
site 8766, ortak bir bucket dizini — ve gerçek panel JavaScript'iyle şunları
sürer: yedek alma, listeleme, günlük zamanlamanın kalıcılığı, kod üretimi ve
geri sayım, **süreç sınırını gerçekten aşan** bir çekme, küçük harfle ve
boşlukla yeniden yazılmış kodun kabulü, kullanılmış kodun reddi, yanlış kodun
reddi, pointer devri, bucket'tan geri yükleme, önek dışı bir anahtarın reddi,
onaysız geri yüklemenin reddi ve kodsuz export'un reddi.

```bash
php wordpress-plugin/animeh/tests/run.php
node wordpress-plugin/animeh/tests/sigv4-crosscheck.mjs

STUB_PORT=8765 wordpress-plugin/animeh/tests/stub-server.sh start
STUB_PORT=8766 STUB_LOG=/tmp/animeh-stub-b.log wordpress-plugin/animeh/tests/stub-server.sh start
node wordpress-plugin/animeh/tests/e2e/panel.mjs
node wordpress-plugin/animeh/tests/e2e/migration.mjs
STUB_PORT=8765 wordpress-plugin/animeh/tests/stub-server.sh stop
STUB_PORT=8766 wordpress-plugin/animeh/tests/stub-server.sh stop
```

### Bu ortamda yapılamadı

**Backblaze'e tek bir gerçek istek atılmadı.** Bu ortamın çıkış politikası
Backblaze'i 403 ile engelliyor, ve bunu dolanmak doğru değil. Dolayısıyla imza
doğruluğu bağımsız bir uygulamayla (npm `aws4`) ve AWS'nin yayımladığı test
vektörüyle çapraz kontrol edildi; ama gerçek bir bucket'a ilk istek senin
kurulumunda atılacak. **Bağlantıyı Sına** düğmesi tam olarak bunun için var.

Aynı şekilde WP-Cron'un yedeği gerçekten tetiklediği, `$wpdb->insert`'in her
sütunu kabul ettiği ve `dbDelta`'nın tabloları kurduğu burada koşturulamadı —
WordPress kurulamıyor. Mantığın ağırlığı bu yüzden `Support/` içinde ve orada
doğrudan test ediliyor.

---

## 8. Sorun giderme

**"Bucket bilgilerini gir" diyor ama girdim.** Beş alan da dolu olmalı: bölge,
bucket, endpoint, anahtar kimliği, anahtar. Endpoint boşsa bölgeden türetilir —
ama bölge de boşsa türetilecek bir şey yok.

**Bağlantı sınaması `SignatureDoesNotMatch` diyor.** Anahtar yanlış ya da
eksik yapıştırılmış. Backblaze application key'i yalnızca oluşturulurken bir
kez gösterir; emin değilsen yenisini üret.

**Yedek alınmıyor, "bucket bilgileri eksik" diyor.** Yedek bucket'a yazılır;
bucket yapılandırılmadan yedek alınamaz.

**Günlük yedek çalışmıyor.** WP-Cron siteye trafik geldiğinde tetiklenir.
Ziyaret almayan bir sitede gerçek bir cron kurmak gerekir:
`wp-config.php` içine `define('DISABLE_WP_CRON', true);` ve sunucuda
`*/15 * * * * curl -s https://siten.com/wp-cron.php?doing_wp_cron > /dev/null`.

**Taşıma kodu "geçersiz ya da süresi dolmuş" diyor.** Üç ihtimal: 30 dakika
geçmiş, kod zaten bir kez kullanılmış, ya da eski sitede kod üretildikten sonra
yenisi üretilmiş (yalnızca sonuncusu geçerlidir). Yeni kod üret.

**"Eski siteye ulaşılamadı."** Adres yanlış, site kapalı, ya da adres özel bir
ağa çözümleniyor. Panelde eski sitenin **ana sayfa adresi** yeterli;
`/wp-admin` ya da `/wp-json` yazmana gerek yok, ama yazarsan da kabul edilir.

**Geri yükleme "yedek bozulmuş görünüyor" diyor.** Checksum tutmuyor: dosya
elle düzenlenmiş ya da yarım inmiş. Listeden bir öncekini dene.

**"Bu yedek daha yeni bir eklenti sürümüyle alınmış."** Yedeği alan site
eklentinin daha yeni bir sürümünü çalıştırıyordu. Önce eklentiyi güncelle,
sonra geri yükle.
