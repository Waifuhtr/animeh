# WordPress Backend ve API

Aşama 3. Uygulamanın konuştuğu her şey: kullanıcılar, katalog, izleme geçmişi,
yönetim ve Tenrai. Hepsi aynı eklentinin içinde — brief §7 her şeyi tek bir
eklentide topluyor.

---

## 1. Neden custom tablo, neden post değil

Bir bölüm bir "yazı" değil. Sayısal bir sırası var, bir sezona bağlı, birkaç
medya kaynağı taşıyor ve her kullanıcı için ayrı bir oynatma konumu var. Bunu
post meta ile ifade etmek her liste sorgusunu bir self-join'e çevirir ve sıralama
için indeks bırakmaz.

Custom tablo bir kurulum betiği maliyeti getirir; doğru sorguları verir.

**Manga için:** tablolar `work` / `episode` diye adlandırıldı, `anime` diye değil,
ve `works.kind` sütunu var. §20'nin istediği genişleme bir migration değil, bir
satır.

### Tablolar

| Tablo | Ne tutuyor |
| --- | --- |
| `animeh_works` | anime (ileride manga) — başlık, poster, puan, tür, durum |
| `animeh_seasons` | sezonlar |
| `animeh_episodes` | bölümler — sıra, süre, jenerik işaretleri |
| `animeh_sources` | bölüme bağlı medya: video, altyazı, font |
| `animeh_history` | kullanıcı başına oynatma konumu |
| `animeh_library` | favoriler ve izleme listesi |
| `animeh_tokens` | verilmiş API token'ları (yalnızca hash) |
| `animeh_announcements` | uygulama içi duyurular |
| `animeh_logs` | yapılandırılmış hata kaydı |

`animeh_sources` tek tablo: bir video kalitesi, bir altyazı ve bir font, bölümle
ilişkileri bakımından aynı şey; yalnızca hangi sütunun anlamlı olduğu değişiyor.

**İşaretler `-1`, `0` değil.** Jenerik başlangıcı için `-1` "işaretlenmedi"
demek; `0` bölümün en başındaki gerçek bir işaret. İkisini karıştırmak ya
düğmeyi hiç göstermez ya da yanlış gösterir.

---

## 2. Kimlik doğrulama

WordPress tarayıcıyı çerez + nonce ile tanır. Native uygulamada ikisi de yok.
Bu yüzden uygulama **bearer token** taşıyor ve `determine_current_user` filtresi
bunu, herhangi bir `permission_callback` çalışmadan önce bir kullanıcı kimliğine
çeviriyor.

Bunun önemli sonucu: o noktadan sonra eklenti de WordPress de sıradan bir
oturum açmış kullanıcı görüyor. `current_user_can()` her yerde tek yetki
otoritesi kalıyor ve §8'in kuralı ikinci bir kod yolu olmadan sağlanıyor.

### Token neden JWT değil

JWT'nin satış argümanı sunucunun onu aramak zorunda olmaması. Ama çalınan bir
oturumu iptal etmek yine de bir engelleme listesi gerektirir — ve o liste zaten
aranmak zorundadır. Yani tasarruf hayali; maliyet ise yanlış yapılabilecek bir
imza şeması.

Bunun yerine: 256 bit rastgele, opak bir dizi, tabloda **yalnızca hash'i** ile.
Sonuçları:

- Veritabanı dökümü çalışan oturum vermez.
- Çıkış yapmak gerçekten çıkış yapar — satır gider.
- İptal edilecek bir `alg: none` alanı yok.

Token `ahp_` ile başlıyor: sızmış bir dizinin ne olduğu grep'lenebilir olsun diye.

### Oturum aileleri

Bir access token ile refresh token aynı `family` değerini taşıyor. Bir cihazdan
çıkış yapmak aileyi iptal ediyor — access token ile çıkış yapıp refresh token'ın
oturumu geri getirmesi mümkün değil.

**Refresh tek kullanımlık.** Kullanılan aile tamamen iptal ediliyor; bir refresh
token tekrar oynatılırsa zaten ölüdür, ve bu başarısızlık sızdığının işaretidir.

### Hız sınırı

`/auth/login` ve `/auth/register` 15 dakikalık pencerede IP başına sınırlı
(10 ve 5). Sabit pencere: sınır aşımı pencere sınırında en kötü 2× olur, ki
"kaç şifre denenebilir" için anlamlı bir zayıflama değil, ve zaman damgası
listesi yerine tek bir tam sayı tutuyor.

`X-Forwarded-For` **kullanılmıyor**. O başlığı istemci gönderir; ona güvenmek
saldırgana her istekte yeni bir kimlik verir ve sınırı tamamen etkisizleştirir.

### Kullanıcı sayımı sızdırmama

- Yanlış şifre ve olmayan kullanıcı **aynı mesajı** döndürüyor.
- `/auth/password/forgot` hesap var da yok da aynı yanıtı veriyor.

İkisi de farklı yanıt verse, endpoint "bu adres kayıtlı mı" sorgusuna dönüşür.

---

## 3. Endpoint'ler

Namespace `animeh/v1`. **Her rotada gerçek bir `permission_callback`.**

### Açık (giriş gerektirmez)

| | |
| --- | --- |
| `POST /auth/register` | kayıt (site kaydı açıksa) |
| `POST /auth/login` | giriş |
| `POST /auth/refresh` | token yenileme |
| `POST /auth/password/forgot` | şifre sıfırlama |
| `GET /catalog/home` | ana sayfanın tüm rafları, **tek istekte** |
| `GET /catalog/works` | arama ve filtreleme |
| `GET /catalog/works/{id\|slug}` | anime detayı |
| `GET /catalog/works/{id}/episodes` | bölüm listesi |
| `GET /catalog/genres` | türler |
| `GET /announcements` | duyurular |

Katalog açık, çünkü üye olmadan görülemeyen bir katalog kimsenin üye olmadığı
kataloğdur. Hesap gerektiren şey **oynatma**.

### Oturum gerektirenler

| | |
| --- | --- |
| `POST /auth/logout` | bu cihazdan çık |
| `POST /auth/password/change` | şifre değiştir |
| `GET /auth/sessions` | açık oturumlar |
| `GET/PUT /me` | profil |
| `GET/POST /me/settings` | uygulama tercihleri |
| `GET /me/library` | favoriler / izleme listesi |
| `POST/DELETE /me/library/{workId}` | listeye ekle / çıkar |
| `GET/POST/DELETE /me/history` | geçmiş, ilerleme kaydı |
| `GET /me/continue` | kaldığın yerden devam |
| `GET /episodes/{id}/play` | **bir bölümü oynatmak için gereken her şey** |

### Yönetim (yetki gerektirir)

| | |
| --- | --- |
| `GET /admin/dashboard` | sayımlar, hatalar, depolama ve Tenrai durumu |
| `GET/POST /admin/works`, `PUT/DELETE /admin/works/{id}` | anime CRUD |
| `GET/POST /admin/works/{id}/episodes` | bölüm listesi ve ekleme |
| `GET/PUT/DELETE /admin/episodes/{id}` | bölüm CRUD |
| `GET/POST /admin/episodes/{id}/sources` | video / altyazı / font ekleme |
| `DELETE /admin/sources/{id}` | kaynak silme |
| `GET /admin/tenrai/search` | Tenrai'de ara |
| `POST /admin/tenrai/import` | içe aktar |
| `GET/POST /admin/tenrai/settings` | Tenrai yapılandırması |
| `GET /admin/users`, `POST /admin/users/{id}/role` | kullanıcılar |
| `GET/POST/DELETE /admin/announcements` | duyurular |
| `GET/DELETE /admin/logs` | sistem logları |

---

## 4. `/episodes/{id}/play` — tek istek

Bir bölümü başlatmak için gereken her şey tek yanıtta:

```json
{
  "episode":   { "id": 42, "number": 3, "duration_seconds": 1440, … },
  "work":      { "id": 7, "title": "…", … },
  "videos":    [ { "height": 1080, "url": "…", "fallback_urls": ["…"] }, … ],
  "subtitles": [ { "language": "tr", "url": "…", "is_default": true } ],
  "fonts":     [ { "family": "Open Sans", "url": "…" } ],
  "markers":   { "intro_start": 85, "intro_end": 175, "outro_start": 1320 },
  "next":      { "id": 43, … },
  "previous":  { "id": 41, … },
  "resume":    { "position_seconds": 412, "completed": false }
}
```

Sebep: bu uygulamanın hedeflediği bağlantıda, ilk kareden önce dört istek, dört
takılma ihtimali demek.

**Fontlar bölüm başına değil anime başına.** Bir seri baştan sona aynı yazı
tiplerini kullanır; bölüm başına listelemek uygulamayı her sonraki bölümde aynı
fontu tekrar indirmeye zorlar.

**İmzalı adresler istek başına üretiliyor** ve süreleri doluyor. Bir yanıttan
kazınan bağlantı çalışmayı durdurur — özel bucket'ın anlamı budur.

---

## 5. İlerleme kaydı: tek ifade

Oynatıcı birkaç saniyede bir konum bildiriyor. Okuyup-sonra-yazmak yarış
durumu yaratır: aynı bölümü iki cihazda oynatan iki istek iki `INSERT` üretir
ve unique key ikincisini reddeder.

Bunun yerine tek `INSERT … ON DUPLICATE KEY UPDATE`:

```sql
completed = GREATEST(completed, VALUES(completed))
```

`completed` yapışkan: bir bölümü bitirip geri sarmak onu izlenmemiş yapmamalı.

"Kaldığın yerden devam et" sorgusu, iç `SELECT` ile **iş başına tek satır**
alıyor. Olmazsa elli bölüm izlenmiş bir seri rafın tamamını doldurur.

---

## 6. Tenrai

Uygulama Tenrai ile **hiç konuşmuyor**. §5 sunucu anahtarının APK'ya
girmemesini istiyor; bunun tek güvenilir yolu isteği hiç uygulamadan
yapmamaktır.

```
Android → WordPress → Tenrai
```

- Anahtar sunucuda, **şifreli** saklanıyor (bucket anahtarıyla aynı mekanizma).
- Yanıtlar cache'leniyor; TTL uç noktaya göre değişiyor (bitmiş bir serinin
  detayı değişmez, sezonluk liste değişir).
- **Dakikada 30 istek tavanı** — §24 üst kaynağın limitlerini kötüye kullanacak
  bir tasarım istemiyor; tavan, kaç admin aynı anda içe aktarırsa aktarsın
  geçerli.
- Üst kaynak çökerse **bayat cache** servis ediliyor (bir haftaya kadar).
  Bir günlük metadata boş ekrandan iyidir.

### Şema

Tenrai v1, Jikan v4 uyumluluk şemasını takip ediyor (brief §5). `TenraiMapper`
buna göre yazıldı ve iki şeyi baştan varsayıyor, çünkü uyumluluk şemalarının
ikisi de doğrudur:

1. **Alanlar yok değil, `null`.** String bekleyen bir mapper ilk `null`'da ölür.
2. **Aynı değer birden fazla yerde.** `title` ile `titles[]`, `images.jpg` ile
   `images.webp`. Hepsine bakılıyor, dokümanın gösterdiği tek yere değil.

Yıl üç yerden okunuyor: `year`, `aired.prop.from.year`, ve `aired.from` metni.

> **Not:** Bu ortamın çıkış politikası `api.tenrai.org`'u 403 ile engelliyor, bu
> yüzden canlı şema doğrulanamadı. Mapper brief'in belirttiği Jikan v4 şemasına
> göre yazıldı ve kaydedilmiş payload'lara karşı test edildi. Şema farklıysa
> değişecek tek dosya `TenraiMapper.php` — API tabanı da ayar, sabit değil.

### Bir hata, testin yakaladığı

`status` eşlemesi ilk yazıldığında `airing` kontrolü `finished` kontrolünden
önceydi. Jikan'ın en yaygın değeri **"Finished Airing"** — içinde "airing"
geçiyor. Yani biten her seri "şu anda yayında" olarak işaretlenecekti. Test
yakaladı; sıra düzeltildi ve yorumda sebebi yazıyor.

---

## 7. Loglar ve gizli veri

`animeh_logs` yapılandırılmış: seviye, §25'in kodu, mesaj, JSON bağlam.

Bağlam yazılmadan önce **redaksiyondan** geçiyor: adı `token`, `secret`,
`password`, `key`, `authorization`, `cookie` veya `signature` içeren her anahtar
`[redacted]` oluyor, ve bu **özyinelemeli** — çünkü ilginç olan her zaman
iç içedir: bir istek dökümünün içindeki başlık torbası.

Loglar 30 gün sonra siliniyor; süresi dolmuş token'lar 7 gün sonra. İkisi de
günlük cron ile.

---

## 8. Doğrulama

| | |
| --- | --- |
| PHP lint | 39 dosya, temiz |
| PHP birim testleri | **103/103** |
| SigV4 çapraz kontrolü | 22/22 |
| Panel tarayıcı testleri | 27/27 |
| Taşıma tarayıcı testleri | 29/29 |

Yeni testler: `ApiToken` (8), `RateLimit` (6), `TenraiMapper` (13).

### Yapılamayan

WordPress kurulamadığı için `$wpdb`, `dbDelta`, `register_rest_route`,
`determine_current_user` ve gerçek kullanıcılarla yetki kontrolleri burada
çalıştırılamadı. Mantığın ağırlığı yine `Support/` içinde ve orada doğrudan
test edildi; WordPress katmanı kasıtlı olarak ince.
