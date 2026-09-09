# Puanlar, Çerçeveler ve Sıralama

Bu belge üç şeyi anlatıyor: puan ekonomisinin kuralları, animasyonlu profil
çerçevelerinin **nerede durduğu ve neden orada durduğu**, ve sıralamanın nasıl
hesaplandığı. Sonunda **senin yapman gerekenler** var — çerçeveleri yüklemek
için tek gereken orası.

---

## 1. Puan

| | |
| --- | --- |
| Bitirilen her bölüm | **+20 puan** |
| Yönetim hediyesi | admin panelinden, tek seferde en fazla 100.000 |
| Çerçeve satın alma | eksi, çerçevenin fiyatı kadar |

"Bitirilen" tanımı profildeki sayaçla **aynı** tanım (`WatchProgress::is_complete`).
Yani cüzdandaki sayı ile profildeki "izlenen bölüm" sayısı hiçbir zaman
birbirini yalanlamaz.

### Aynı bölüm iki kez ödeme yapmaz

Ödeme, `wp_animeh_points` tablosunda `(user_id, award_key)` üzerinde **tekil
indeks** ile korunuyor; bir bölümün anahtarı `episode:412`. Önce okuyup sonra
yazmak yerine doğrudan yazılıyor, çünkü:

- İlerleme raporu birkaç saniyede bir geliyor.
- Birlikte izleme odasında iki cihaz aynı anda "bitti" diyebiliyor.

Okuyup-sonra-yazan bir kod bu durumda iki kez öderdi ve her testten geçerdi.
Tekil indeks, veritabanının kendisine "bunu bir kez kabul et" dedirtiyor.

Bakiye hiçbir yerde saklanmıyor; her seferinde `SUM(delta)` ile toplanıyor.
Saklanan bir bakiye, arkasındaki geçmişle çelişebilen bir sayıdır ve
çeliştiğinde hangisinin doğru olduğu anlaşılamaz.

### Çerçeve satın alırken

`spend()` işlemi bir transaction içinde, bakiyeyi `FOR UPDATE` ile okuyor. İki
ayrı çerçevenin aynı anda, sadece birine yetecek parayla alınması engellenmiş
oluyor. Aynı çerçevenin iki kez alınmasını ise zaten `frame:7` anahtarı
engelliyor.

---

## 2. Çerçeveler — neden çevrimiçi, uygulamanın içinde değil

Sen sordun: **online mı, uygulamaya gömülü mü?** Cevap: **online**, ve
dosyalar WordPress'in kendi `uploads` klasöründe. Üç sebep:

1. **APK boyutu.** 40 çerçeve × ~500 KB = 20 MB. Bunu herkes indirir, hiç
   kullanmayanlar dahil. Online olduğunda telefon yalnızca baktığı çerçeveyi
   indirir — ve Coil disk önbelleği sayesinde bir kez indirir.
2. **Yeni çerçeve = yeni derleme olurdu.** Gömülü olsaydı her yeni çerçeve
   için APK derleyip herkese kurdurman gerekirdi. Şimdi panelden yüklüyorsun,
   herkes bir sonraki bakışında görüyor.
3. **Başkalarının çerçevesini görmek.** Bir kullanıcının çerçevesi başka
   herkesin ekranında da çizilmeli. Gömülü olsa, eski sürümdeki bir uygulama
   yeni bir çerçevenin kimliğini tanımaz ve hiçbir şey çizemezdi.

### Neden B2 değil, WordPress uploads

B2'ye koymayı düşündüm ve **koymadım**: kova gizliyse imzalı URL bir süre
sonra ölür, ve o URL veritabanına yazılıp binlerce profil yanıtına giriyor.
`wp-content/uploads/animeh-frames/` kalıcı, herkese açık ve hiçbir ayar
gerektirmiyor — fontların durduğu yerin aynısı. Klasöre `index.php` ve
`.htaccess` konuyor (dizin listeleme kapalı, PHP çalıştırma yasak).

İleride B2'ye taşımak istersen: veritabanında URL sütunu var, uygulamada bir
şey değişmez.

### Dosya biçimi — ne yüklemelisin

| Biçim | Kalite | Boyut | Çalıştığı sürüm |
| --- | --- | --- | --- |
| **Animasyonlu WebP** ✅ | çok iyi, 8-bit alfa | küçük | Android 9+ (API 28) |
| APNG | çok iyi, 8-bit alfa | büyük | Android 9+ |
| GIF | 256 renk, 1-bit şeffaflık | orta | Android 7+ (hepsi) |

**Önerim: animasyonlu WebP, 288×288.** Parlayan bir halkanın yumuşak kenarı
GIF'te testere dişine dönüşür — GIF'in şeffaflığı ya var ya yok, arası yok.
Android 9 altındaki telefonlarda animasyonlu WebP **ilk karesiyle sabit**
görünür; kırılmaz, sadece durur. 2026'da bu telefonların oranı çok düşük.

Sunucu dosyayı **uzantısına değil içeriğine** bakarak doğruluyor
(`FrameFile::inspect`): PNG imzası ve `acTL` bloğu, GIF'te ikinci bir görüntü
tanımlayıcısı, WebP'de `VP8X` bayrağı ve `ANMF` bloğu. Kare olmayan dosya
reddediliyor — çerçeve yuvarlak bir fotoğrafın etrafına çizildiği için,
kare olmayan bir dosya esnetilir ve her profilde gözle görülür bir elips olur.

### Performans: hepsi birden oynamıyor

Senin sorduğun tam olarak buydu. Çözüm **iki ayrı resim yükleyici**:

- **Varsayılan yükleyici**: animasyon çözücüsü **yok**. Animasyonlu bir WebP
  ya da GIF buradan geçtiğinde ilk karesiyle, tek bir bitmap maliyetiyle
  çiziliyor. Listelerin hepsi bunu kullanıyor.
- **Animasyonlu yükleyici**: `newBuilder()` ile aynı yükleyiciden türetiliyor
  (aynı HTTP istemcisi, aynı disk önbelleği), üstüne çözücü ekleniyor.

Kural: **bir çerçeve yalnızca tek ve büyük olduğu yerde oynar.**

| Yer | Oynar mı |
| --- | --- |
| Kendi profil başlığın | ✅ |
| Başkasının profil başlığı | ✅ |
| Mağazada seçili olan (büyük önizleme) | ✅ |
| Sıralamada ilk üç | ✅ (üç tane) |
| Mağaza ızgarası | ❌ sabit |
| Sıralama listesi | ❌ sabit |
| Arkadaş listesi, oda, yorumlar | ❌ sabit |

Podyumdaki dönen halka ve parlama Compose ile **çizim aşamasında** yapılıyor
(`drawBehind` içinde `.value` okunuyor), yani animasyonun her karesi bir
yeniden-besteleme değil, sadece bir çizim.

---

## 3. Sıralama

Üç tablo, aynı geçmiş tablosundan:

| Sekme | Ölçüt |
| --- | --- |
| Bölüm | `SUM(completed)` |
| Süre | `SUM(watched_seconds)` |
| Anime | `COUNT(DISTINCT work_id)` |

Eşitlik, o skora **önce ulaşan** lehine bozuluyor (`MIN(updated_at)`) — yoksa
aynı sayıya sahip iki kişi her yenilemede yer değiştirir.

Sonuç **5 dakika** önbellekleniyor. Bu, eklentinin en pahalı sorgusu; sıralama
bakılan bir şey, saniyede bir yenilenen bir şey değil.

Listeye giremeyen kullanıcı da kendi sırasını görüyor: kaç kişinin önde
olduğu sayılıyor, yani 400. sıradaki biri de bir sayı görüyor.

---

## 4. Profil rengi

12 renk, hepsi ücretsiz. Slug sunucuda saklanıyor (`amethyst`, `ocean`, …),
renk değerinin kendisi **uygulamada**. Sebep: renk bir tasarım kararı; hex
değerini veritabanına yazsaydım sonradan ayarlamak için veri göçü gerekirdi,
ve bir istemci siyah zemine siyah yazı seçebilirdi. Sunucu tanımadığı slug'ı
varsayılana çeviriyor, hata vermiyor — profil çizerken bir renk yüzünden ekran
düşmemeli.

---

## 5. SENİN YAPMAN GEREKENLER

### a) Eklentiyi güncelle

`wordpress-plugin/animeh/` klasörünü siteye yükle (ya da yeni zip'i kur).
Yeni üç tablo (`animeh_points`, `animeh_frames`, `animeh_user_frames`)
`CatalogSchema::VERSION = '8'` ile **kendiliğinden** oluşuyor; elle bir şey
yapmana gerek yok. Klasör de (`uploads/animeh-frames/`) kendiliğinden açılıyor.

### b) Çerçeveleri hazırla

- **288×288**, kare.
- **Animasyonlu WebP** tercih (APNG ve GIF de kabul).
- Ortası şeffaf: fotoğraf, karenin **iç ~%74'ünü** dolduruyor. Çerçevelerin
  farklı orantıda çizildiyse söyle — `AvatarFrame.kt` içindeki `AVATAR_INSET`
  tek bir sayı, oradan ayarlarız.
- En fazla 3 MB (animasyonlu WebP'de bu çok geniş bir sınır).

### c) Yükle

Uygulama → **Yönetim Paneli → Çerçeveler → +**

Her çerçeve için:
- **Ad** (boş bırakırsan dosya adından türetilir)
- **Fiyat (puan)** — kutunun altında "yaklaşık kaç bölüme denk" yazıyor
- **Nadirlik** — sadece kartın kenar rengini seçiyor: Standart / Nadir /
  Destansı / Efsanevi

Yüklenen çerçeve anında mağazada. Fiyatını, adını, sırasını sonradan
değiştirebilirsin; "Mağazada görünsün" kapatılırsa yeni satışa kapanır ama
almış olanların üzerinde kalır.

**Silmeye dikkat:** bir çerçeveyi silmek, satın almış olan herkesin üzerinden
de kaldırır ve puanları geri vermez. Panel bunu silmeden önce yazıyor.

### d) Puan gönder

Uygulama → **Yönetim Paneli → Kullanıcılar** → kullanıcının satırındaki ⭐

- Miktar yaz, istersen "Puan geri al" anahtarını aç (eksi gönderir).
- **Not** alanı kullanıcının kendi puan geçmişinde görünür — "Çeviri katkısı
  için teşekkürler" gibi.

Bu düğme yalnızca **yöneticide** görünüyor. Moderatörde görünmüyor, çünkü para
basmak bir ekonomi kararı; sunucu da moderatörün isteğini zaten reddediyor.

### e) Fiyat önerisi

20 puan = 1 bölüm. Yani:

| Fiyat | Kaç bölüm |
| --- | --- |
| 200 | 10 |
| 500 | 25 |
| 1.000 | 50 |
| 2.500 | 125 |

İlk çerçeveleri 200–500 aralığında tutup, birkaç tanesini 2.000+ yaparsan
ekonomi hem erişilebilir hem de hedefli olur.

---

## 6. Burada doğrulanan / doğrulanamayan

**Doğrulandı** (bu ortamda gerçekten çalıştırıldı):

- `Points`, `ProfileTheme`, `FrameFile` ve `LeaderboardRepository::rank` —
  saf PHP birim testleri. WebP/GIF/APNG imzaları elle üretilen dosyalarla
  test ediliyor; GD gerekmiyor.
- Her PHP dosyası `php -l`.
- Kotlin: sınıf yolu olmadan derleyici taraması, alt paket import taraması,
  Activity metot çakışması taraması, XML iyi-biçimlilik.

**Doğrulanamadı** (WordPress kurulu olmadığı için):

- `dbDelta` ile üç tablonun gerçekten oluşması.
- Transaction'ın gerçek InnoDB üzerinde davranışı.
- Çoklu-parça (multipart) yüklemenin WordPress REST tarafında okunması.
- Yetki kontrollerinin gerçek kullanıcılarla davranışı.

İlk gerçek çalıştırma sende olacak. Bir şey patlarsa hatayı bana ilet.
