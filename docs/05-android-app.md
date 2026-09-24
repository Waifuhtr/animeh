# Android Uygulaması

Aşama 4. Kotlin + Jetpack Compose, MVVM, Hilt, Media3. Yönetim paneli
uygulamanın içinde.

**Bu ortamda derlenemedi.** Sebebi ve ne yapılması gerektiği §8'de — önce ne
yazıldığı.

---

## 1. Yapı

```
android/app/src/main/java/com/animeh/app/
├── core/            AppError, AppResult, UiState
├── domain/          Work, Episode, Playback… + eşlemeler
├── data/
│   ├── remote/      Retrofit servisleri, DTO'lar, interceptor, hata eşleme
│   ├── local/       Room: çevrimdışı cache
│   ├── prefs/       SessionStore (şifreli), SettingsStore (DataStore)
│   └── repository/  Auth, Catalog, Library, Admin
├── di/              Hilt modülleri
├── player/          state, ABR politikası, controller, ASS
│   ├── ass/         AssParser, FontResolver, AssRenderer
│   └── ui/          PlayerActivity, kontroller, ayar sayfası
└── ui/
    ├── theme/       renk, tipografi, tema
    ├── components/  ortak durum ve kart bileşenleri
    ├── navigation/  rotalar, NavHost
    └── screens/     home, discover, detail, library, profile, settings,
                     auth, admin
```

61 Kotlin dosyası, ~11.000 satır.

---

## 2. Hata modeli (§25)

`AppError` kapalı bir küme. Ekran `messageRes`'i çiziyor — yani bir ekranın
yanlışlıkla exception basması **mümkün değil**, çünkü elinde bir exception yok.
`technical` alanı log ve admin ekranı için, kullanıcıya gösterilen o değil.

`AppResult<T>` Kotlin'in kendi `Result`'ı yerine kullanılıyor: `Result` bir
`Throwable` taşır, ve bu her imzaya exception tipi sokar. Bu ise ekranın
render edeceği hatayı taşıyor.

`UiState<T>.Success` bir `fromCache` bayrağı taşıyor. Bir haftalık veriyi
canlıymış gibi göstermek, kullanıcının bayat bölüm listesine güvenmeye
başlaması demektir.

---

## 3. Ağ katmanı

### Sunucu adresi çalışma anında değişebiliyor

Retrofit taban URL'sini kuruluşta sabitler. Bu, sunucu adresini değiştirmenin
tüm nesne grafiğini yeniden kurmasını gerektirir.

Bunun yerine Retrofit bir **placeholder** adrese kuruluyor
(`https://placeholder.animeh.invalid/`) ve `AuthInterceptor` her isteğin şema,
host, port ve yol önekini kullanıcının ayarladığı adresle değiştiriyor.

Bu, taşıma tasarımının uygulama tarafındaki karşılığı: WordPress taşınırsa
kullanıcı Ayarlar'a yeni adresi yazıyor, başka hiçbir şey değişmiyor.

### Token yenileme: bir kez, N kez değil

`TokenAuthenticator` OkHttp'nin `Authenticator`'ı — yani 401'den *sonra*
çalışıyor. Saati her istekten önce kontrol etmek, saati farklı bir sunucuyla
yarışır ve hâlâ geçerli token'ları yeniler.

Üçü de olağan hata olan üç şey:

1. **Tek yenileme.** Aynı anda beş istek 401 alırsa bir yenileme çağrısı olmalı.
   Mutex ve içindeki yeniden kontrol bunu sağlıyor; sonraki dördü yeni token'ı
   hazır buluyor.
2. **Sonsuz döngü yok.** Yeniden denenen istek tekrar 401 alırsa
   `responseCount` durduruyor.
3. **Yenileme kendi kendini yenileyemez.** Refresh çağrısı, authenticator'ı
   olmayan ayrı bir istemci kullanıyor.

Ve: **ağ hatası oturumu silmiyor.** Silseydi, sinyal her kesildiğinde kullanıcı
oturumu kapanırdı. Yalnızca sunucunun refresh token'ı *reddetmesi* oturumu
bitiriyor.

### Loglama

Debug derlemede `HttpLoggingInterceptor` **BASIC**, `BODY` değil. `BODY` her
giriş yanıtındaki access token'ı logcat'e basardı.

---

## 4. Oturum saklama

Refresh token uzun ömürlü bir kimlik bilgisi. §9 Android'in güvenli deposunu
istiyor: `EncryptedSharedPreferences`, anahtarı Android Keystore'da.

**Yedek yol, mutlu yoldan daha önemli.** `EncryptedSharedPreferences`
başarısız *olabiliyor* — keystore'u sıfırlanmış bir cihaz, bozuk bir OEM
sağlayıcısı. Düz `SharedPreferences`'a düşmek her kullanıcının token deposunu
sessizce düşürmek olurdu.

Bunun yerine yedek yol **hiç kalıcı saklamamak**: oturum bu açılış boyunca
bellekte yaşıyor, kullanıcı bir dahakine tekrar giriş yapıyor. Daha kötü
deneyim; ama hiçbir zaman sessizce açık metin kimlik bilgisi yazmıyor.

Yedekleme de kapalı (`allowBackup=false`, `backup_rules.xml` her şeyi
dışlıyor): başka bir cihaza kopyalanan bir refresh token, devredilmiş bir
oturumdur.

---

## 5. Çevrimdışı (§26)

Room üç şeyi tutuyor: kapaklar ve metadata, bölüm bilgisi, ve **oynatma
konumu**. Video cache'i yok — §26 açıkça oynatıcı stabil olmadan onu
büyütmemeyi istiyor.

**Konum önce yerele yazılıyor**, sonra sunucuya. Sıralama kasıtlı: oynatıcı
birkaç saniyede bir konum bildiriyor ve zayıf bağlantıda bu çağrıların çoğu
başarısız olacak. Yerele yazmak, devam noktasının bu cihazda **her zaman**
doğru olması demek; `synced` bayrağı sunucuya borçlu olanları işaretliyor ve
bağlantı dönünce `syncPending()` yetişiyor.

"Kaldığın yerden devam et" çevrimdışıyken yerel tablolardan **tek sorguyla**
yeniden kuruluyor (`ContinueRow`), sunucunun kopyasından değil — çünkü yerel
kopya daha yenidir.

Kitaplık değişiklikleri iyimser: kalp hemen doluyor, sunucu reddederse geri
alınıyor. Sessizce kaydolmamış bir favori, görünür şekilde başarısız olandan
kötüdür.

**Çıkışta yerel veri siliniyor.** Paylaşılan bir telefonda bir sonraki
kullanıcının önceki kullanıcının geçmişini devralması, yalnızca o durumda
ortaya çıkan bir gizlilik hatası.

---

## 6. Oynatıcı

ExoPlayer **yalnızca medya motoru** olarak kullanılıyor (§1, §4): demux,
decode, render. Onun üstündeki her şey bizim.

`PlayerView` tam olarak tek rolde var: video yüzeyi. `useController = false`,
`subtitleView` gizli. Kontroller, seek bar, kalite menüsü, altyazı katmanı ve
jestler Compose bileşenleri.

### Üç şey stok bir oynatıcının yapmadığı

**1. Adres devri.** Her kaynak bir URL listesiyle geliyor (önce CDN, arkasında
S3). İlk kareden **önceki** bir hata sessizce sıradaki adrese geçiyor; sonraki
bir hata ağ sorunudur ve geri çekilmeli yeniden deneme alıyor — çünkü o noktada
adres çalıştığını kanıtlamıştır ve kaynak değiştirmek bölümü baştan başlatmaktan
başka işe yaramaz.

**2. Ağ farkındalıklı kalite.** İlk rendition, herhangi bir bant genişliği
ölçülmeden önce bağlantı sınıfından seçiliyor. Muhafazakâr: yüksek tahmin edip
yanılmak izleyiciye spinner izletir, düşük tahmin edip yanılmak birkaç saniye
sonra kimsenin fark etmediği bir kalite değişimidir.

**3. Kurtarma.** Kopan bağlantı bir hata diyaloğu değil,
`PlaybackPhase.Reconnecting` — üstel geri çekilmeyle, denemeler bitene kadar.
Ve telefon uçak modundaysa deneme bütçesi harcanmıyor.

### ABR kuralları

Yükselme için **hem** bant genişliği payı **hem** buffer mesafesi gerekiyor.
Yalnızca bant genişliğine bakmak bir oynatıcının salınmasının nedenidir:
yükselir, yüksek bit hızı buffer'ı boşaltır, hemen geri düşer.

Düşme için buffer'ın sağlıklı olması **beklenmiyor**: takılma bir çözünürlük
düşüşünden pahalıdır, ve buffer boşaldığında kaçınmak için çok geçtir.

Buffer profilleri bağlantıya göre: yavaş bağlantıda büyük buffer yanlış takas —
küçük bir buffer'ın zaten atlatacağı bir takılmaya karşı ilk kareyi geciktirir.

### ASS altyazı (§13)

Bölünme şu: ExoPlayer'ın SSA/ASS extractor'ı **ayrıştırıcı** — stilleri,
konumlandırmayı, hizalamayı, kenar boşluklarını ve satır içi override'ları
çözüp `Cue` üretiyor. `AssRenderer` ise **çizici** — o cue'ları,
`FontResolver`'ın bulduğu yazı tipleriyle, script'in istediği konuma koyuyor.

Media3'ün `SubtitleView`'ı yerine çizmenin sebebi özel fontlar: `SubtitleView`'a
"bu script bu aileyi istedi" denemiyor.

**Kapsanan:** stiller, boyut, renk, hizalama, mutlak konumlandırma, satır
yerleşimi, kenar boşlukları, çoklu stil, zamanlama.

**Kapsanmayan:** karaoke (`\k`), dönüşüm animasyonları (`\t`), vektör çizim
(`\p`). Bunlar tam bir libass gerektiriyor. Zamanlaması olmadan çizilen bir
karaoke satırı burada düz şarkı sözü olarak çıkıyor — dürüst bozulma budur.
libass'i NDK üzerinden eklemek genişleme noktası, ve `SubtitleLayer`'ın imzası
o geldiğinde değişmiyor.

### Font çözümleme (§14)

Sıra tam olarak brief'in istediği gibi: uygulama cache'i → backend'in bu iş için
sunduğu fontlar → altyazıya gömülü → lisanslı kaynak. Sonra **duruyor**.

**Kazıma yok.** Ve eşleşmeyen bir aile, benzer görünen bir fontla
**değiştirilmiyor** — yerine konan bir font, dizgicinin seçtiği zamanlamayı ve
konumlandırmayı sessizce bozar. Bulunamayan aile rapor ediliyor, §15'in akışı bu.

### Boşluğa basınca durmuyor

Tek dokunuş **yalnızca** kontrolleri gösterip gizliyor. Duraklatma yalnızca
duraklat düğmesinde. Telefonu elinde çevirirken yanlışlıkla dokunmak bölümü
durdurmamalı.

Çift dokunuş, dokunulan yarıya göre 10 saniye ileri/geri sarıyor.

---

## 7. Yönetim paneli (§22)

Uygulama içinde: Panel, Anime Yönetimi, Bölümler, Video Kaynakları, Tenrai,
Fontlar, Kullanıcılar, Duyurular, Sistem Logları.

**Yükleme telefondan çalışıyor.** Bitmiş bir encode'u olan bir operatör
masaüstü olmadan bölüme ekleyebiliyor: dosya WordPress'ten geçmiyor, 32 MB'lık
imzalı parçalarla doğrudan bucket'a gidiyor. Yalnızca sonuçtaki anahtar
uygulamadan geçip bölüme kaydediliyor.

Bölüm listesi her bölümün kaç video ve kaç altyazı kaynağı olduğunu gösteriyor,
ve **sıfır video kırmızı** — operatörün bu listede aradığı şey odur.

Panelde ayrıca WordPress ekranındaki ile aynı **depolama sınama** düğmesi var:
oynatma sorununu araştıran biri, bucket'ın cevap verip vermediğini öğrenmek için
uygulamadan çıkmak zorunda kalmasın.

`is_admin` yalnızca sekmeyi çiziyor. Her yönetim endpoint'i sunucuda yetkiyi
yeniden kontrol ediyor — §8. Bayrağı zorla `true` yapan bir istemci bu ekranlara
ulaşır ve her işlemde 403 görür; kastedilen sonuç tam olarak budur.

---

## 8. Derleme — bu ortamda yapılamadı

```
$ gradle wrapper …
Plugin [id: 'com.android.application', version: '8.7.3'] was not found

$ curl https://dl.google.com/dl/android/maven2/…/8.7.3.pom
CONNECT tunnel failed, response 403
```

`dl.google.com` bu ortamın çıkış politikasında **403**. Ve `maven.google.com`
oraya yönlendiriyor — yani engelli olan yalnızca Android SDK değil, **AGP,
AndroidX, Compose, Hilt'in Android kısmı ve Media3'ün tamamı**.

Politikayı dolanmadım: aynı içeriği başka bir aynadan çekmek tam olarak
engellenen şeyi yapmak olurdu.

### Düzeltme (14 Eylül 2026): "bu ortam" gerçekten tek bir ortam

Yukarıdaki ölçüm doğru ama bir süre yanlış genellendi — "her oturumda, her
modelde aynı" diye. Değil. **Ağ politikası ortam başına belirleniyor.**

Aynı depo, aynı dal, aynı gün, iki ortam:

| Ortam | `dl.google.com` | `./gradlew assembleDebug` |
| --- | --- | --- |
| `Default` | CONNECT 403 | AGP çözülemedi, ilk adımda düştü |
| `rpgmaker` | 200 | **BUILD SUCCESSFUL** — 20.3 MB APK, `0.1.0-debug` |

İkincisinde Android SDK kurulu değildi, oturum onu Google'ın kendi
sunucusundan kurdu ve derleme tamamlandı. Tek gerçek pürüz Maven Central'dan
gelen 429 "Too Many Requests" oldu — geçici bir hız sınırı, engel değil, ve
tekrar denemekle değil beklemekle açıldı.

Yani bu bölümdeki "yapılamadı", **o oturumun ortamı** için doğru; proje için
bir kısıt değil. Derlemeye ihtiyaç duyan bir oturum, Google Maven'a izinli bir
ortamda açılmalı.

### Yine de doğrulanabilen

Maven Central erişilebilir olduğu için standalone Kotlin derleyicisi indirildi
ve **framework'e bağlı olmayan katman** gerçekten derlenip test edildi:

| | |
| --- | --- |
| `QualityPolicy`, `AssParser`, `PlayerState`, `AppError`, `AppResult`, `domain/Models` | derleniyor |
| Birim testleri | **32/32 geçiyor** |
| Tüm ağaçta sözdizimi | **hata yok** (61 dosya parser'dan geçti) |
| `R.string` referansları | **133/133 çözülüyor** |
| Kendi sembollerime kırık referans | **yok** |

Bu bir gerçek hata yakaladı: `nextHeight` yükselirken azalan sıralı listede
`lastOrNull` kullanıyordu, yani **en düşük** uygun kaliteyi seçiyordu.
Oynatıcı tepeye ulaşmak için birkaç geçiş turu harcayacaktı — ve her geçiş
media item'ı yeniden açıyor. `firstOrNull` ile düzeltildi.

### Doğrulanamayan

Android çerçevesine dokunan her şey: Compose'un derlenmesi, Hilt'in grafiği
kurması, Room'un DAO'ları üretmesi, Retrofit'in arayüzleri bağlaması, Media3'ün
davranışı, ve elbette gerçek bir cihazda oynatma.

Bunlar dikkatle yazıldı ama **ilk gerçek derleme sende olacak.**

### Derlemek için

**Seçenek 1 — Hugging Face Space.** `dist/animeh-apk-builder-space.zip` içindeki
her dosyayı bir Docker Space'e yükle, **Derle**'ye bas, APK'yı indir. Ayrıntı:
[`docs/06-apk-builder-space.md`](06-apk-builder-space.md).

**Seçenek 2 — kendi makinende.**

```bash
cd android
./gradlew assembleDebug        # veya Android Studio'da aç
```

Gereken: JDK 17, Android SDK 35, ve `dl.google.com`'a erişim.

`local.properties` içine (git'e girmez):

```properties
sdk.dir=/path/to/Android/sdk
ANIMEH_API_BASE=https://siten.com/wp-json/animeh/v1/
```

`ANIMEH_API_BASE` yalnızca varsayılan; kullanıcı Ayarlar'dan değiştirebiliyor.
Release imzalama için `ANIMEH_KEYSTORE`, `ANIMEH_KEYSTORE_PASSWORD`,
`ANIMEH_KEY_ALIAS`, `ANIMEH_KEY_PASSWORD` — keystore yoksa release yapılandırması
hiç oluşturulmuyor, böylece temiz bir kopyada debug derlemesi keystore sormuyor.

---

## 9. Benzer yapımlar, önbellekten açılan ana sayfa, tema rengi

### Aynı türden ilgini çekebilir

Anime sayfasının en altında, yorumların altında — oraya kadar okumuş biri
başka bir sayfaya geçmeye hazır olan kişidir.

Sıralama **paylaşılan tür sayısına** göre. Bu, `?genre=` sorgusunun
yapabildiği şeyden farklı: o sorgu üç türü de paylaşan bir yapımla yalnızca
birini paylaşanı ayırt edemez, ikisini de aynı listede döndürür.

Türler ayrı bir tabloda değil, sütunun içinde bir JSON dizisi olarak duruyor;
yani `COUNT(*) … GROUP BY` yok. Bu yüzden iş ikiye bölündü:

| Kim | Ne soruyor |
| --- | --- |
| SQL | *Hangi satırlar ilgili olabilir* — indeksle ve `LIKE` ile, ucuz |
| `Support\Similarity` | *Hangisi daha iyi öneri* — iki tür listesi yan yana, aritmetik |

İkincisi kendi evinde, veritabanı olmadan test edilebiliyor. Altı kontrol,
hepsi gözle görülmeyen bir yanlışın karşılığı: çok tür paylaşanın öne geçmesi,
eşitlikte geliş sırasının (yani puan sırasının) korunması, hiç tür
paylaşmayanın listeye girmemesi, aynı türü tekrarlayan bozuk bir listenin üç
tür paylaşıyormuş gibi görünmemesi, sınır, ve bozuk JSON'un sayfayı kırmaması.
Sıralamayı kaldırarak düşürülerek doğrulandı.

Boş liste gerçek bir cevap: türü olmayan bir yapımın komşusu yoktur, sinyali
olmayan bir telefonun da. İkisinde de bölüm hiç çizilmiyor — öneri vaat eden
bir başlığın altındaki boş raf, başlığın hiç olmamasından kötü.

### Ana sayfa artık beklemiyor

Önbellek zaten vardı ama yanlış sıradaydı: **önce ağ, hata olursa önbellek.**
Yani her açılış bir gidiş-dönüş bekliyordu — görülen bekleme oydu.

Artık saklanan kopya önce çiziliyor, istek arkadan gidiyor ve geldiğinde
yerine geçiyor. İyi bağlantıda değişim ilk sıra okunmadan oluyor.

Yalnızca ekranda hiçbir şey yokken: aşağı çekip yenilemek canlı rafları atıp
bir saniyeliğine dünküleri geri koymamalı.

### Uygulama rengi

Değişen **yalnızca vurgu** — düğmeleri, seçimleri ve vurguları taşıyan renk.
Yüzeyler yerinde kalıyor. İki sebep: ürün varsayılan olarak değil, tasarım
gereği koyu; ve okunamaz bir ekran üretebilen bir renk seçici seçenek değil,
tuzaktır.

Palet, profillerin zaten kullandığı on iki renk. Yanına ikinci bir liste
yazmak, bir tasarımın kazara gibi görünmeye başlamasının yoludur. Varsayılan
`amethyst`, ki bugünkü mor rengin ta kendisi — kimsenin uygulaması kendiliğinden
değişmiyor.

Seçim `DataStore`'da bir *slug* olarak duruyor, hex olarak değil: tasarım
"Okyanus"un ne demek olduğunu sonra ayarlayabilsin ve her telefon eski bir
değeri saklamasın.

Oynatıcı kendi etkinliği ve kendi kompozisyonu olduğu için rengi ayrıca
okuyor; arkasındakinden miras almıyor.

## 10. Reklam güvenilirliği, profil ayrımı, genel sayfa önbelleği (20 Eylül 2026)

### Reklam: tek başarısız istek kalıcı kayıptı

Bulgu kullanıcıdan geldi: başlangıç reklamı bazen çıkmıyordu, 4 dakikalık
reklam bazen "yükleniyor" yazıp hemen kayboluyordu.

Kök neden `AdBreakController.dueNow()`'da: bir kırılımın gösterilmesine karar
verilir verilmez `lastPlayed` o an güncelleniyordu — `VastClient` ağ isteğini
daha göndermeden. `VastClient.request()` ise tek deneme yapıyordu (5 sn
bağlantı / 8 sn toplam, kasıtlı olarak retry'sız — izleyiciyi bekletmemek
için). Yani tek bir zaman aşımı: kırılım "gösterildi" sayılıp o oturum için
kalıcı olarak kayboluyordu — üstelik en olası an tam da oynatmanın başladığı
an, yani bölümün kendi ağ isteklerinin bağlantıyı en çok kullandığı an.

Düzeltme `VastClient.fetchWithRetry()`: tek retry, aralarında 1.5 saniye.
`dueNow()`'a dokunulmadı — "gösterilecek" kararı hâlâ erken veriliyor, ama
artık tek bir kötü milisaniyenin kırılımı kaybetmesi için iki şansı var. Ölü
bir sunucu hâlâ hızlı vazgeçiyor (izleyici yine beklemiyor), sadece geçici bir
takılma artık kalıcı kayıp olmuyor.

Test: `VastClientTest.kt`, gerçek bir `MockWebServer` ile — 500 sonra başarı
bir kez daha denendiğini, üst üste iki başarısızlığın vazgeçtiğini, temiz bir
ilk yanıtın retry'ı hiç harcamadığını, gerçek bir "boş yanıt"ın (no fill)
retry'ye sebep olmadığını doğruluyor.

### Herkese açık profil: izlenen/okunan ayrımı, manga istatistikleri

Sunucu zaten anime/manga istatistiklerini ayrı hesaplıyordu
(`UserDataRepository::stats()`) — sorun Android tarafındaydı: herkese açık
profilin `ProfileStatsDto`'sunda `manga` alanı hiç yoktu (kendi profildeki
`UserStatsDto`'da vardı), veri geliyor okunmadan atılıyordu. `watched_works()`
de anime/manga ayrımı yapmadan tek liste dönüyordu, satırlarda `kind` bile
yoktu.

İki düzeltme:

- `ProfileStatsDto`'ya `manga: MangaStatsDto` eklendi, herkese açık profile
  "sayfa okundu / bölüm okundu / biten manga" kartları geldi (manga hiç
  yoksa kartlar görünmüyor).
- `watched_works()`'e `w.kind` eklendi, `recent_works` Android'de iki raya
  bölündü: "Son izledikleri" / "Son okudukları". Sunucu tarafında da her
  `kind` **kendi başına** en fazla 12 satır alıyor (`SocialController.php`) —
  tek 12 sınırı paylaşılsaydı, günlük izleyen ama ara sıra okuyan biri
  mangasını rayda hiç göremezdi.

### Diğer sayfalar için genel önbellek

`CatalogRepository.cachedHome()` ana sayfaya özeldi — `WorkEntity`'nin `rail`
sütunu üstüne kurulu, rafların satır satır çizildiği bir yapı. Profil, cüzdan,
sıralama, arkadaşlar, odalar, bildirimler gibi ekranlar tek bir yanıt
nesnesinden kendini çiziyor; her biri için ayrı bir Room şeması kurmak beş
şemanın aynı şeyi söylemesi olurdu.

Onun yerine `SnapshotCache` (`data/local/SnapshotCache.kt`): tek tablo
(`snapshots`, `key`/`json`/`cachedAt`), `kotlinx.serialization` ile herhangi
bir `@Serializable` tipi bir anahtar altında saklıyor. Okuma, JSON artık
uymuyorsa (uygulama güncellendi, alan şekli değişti) sessizce `null` dönüyor
— `Json.coerceInputValues` çoğunu zaten emiyor, geri kalanı burada yakalanıyor.

Desen her ekranda aynı: `cachedX()` ağa hiç dokunmadan son kaydı okuyor,
`x()`'in başarı yolu aynı anahtara yazıyor. ViewModel tarafında da tek kural:
**ekranda zaten bir şey varsa (önbellekten ya da önceki yüklemeden), yeni bir
yükleme onu ne yükleniyor ekranıyla ne de bir hatayla değiştirmiyor** — sadece
başarıyla değişiyor. Oda listesi ve sıralama ekranları bunu zaten "liste
boşsa" koşuluyla yapıyordu; profil ve arkadaşlar ekranları `UiState`
üzerinden aynı kurala getirildi.

Kapsam dışı bırakılan, kasıtlı: anime/manga katalog-keşfet-arama listeleri
(büyüyen bir arşiv sınırsız önbellek demektir) ve player/izleme partisi gibi
zaten anlık olması gereken ekranlar. Ayarlar sayfası da dokunulmadı —
zaten tamamen yerel (`DataStore`), önbelleklenecek bir ağ isteği yok.

Bildirim çanı (`ShortNotificationsViewModel`) bir istisna: "yükleniyor"
durumu, listenin açılması bildirimleri okundu işaretlediği an olduğu için
(`LaunchedEffect(state.loading)`), erkenden kapatılamıyor — kapatılsaydı
sunucu henüz neyin okunmadığını söylemeden bir şey okundu sayılırdı. Orada
önbellek yalnızca **başarısız bir yenilemede** son bilinen listeye düşüyor,
"hiç bildirim yok" yerine.

### Oturum kapatınca temizlenen

`AuthRepository.clearLocalData()`'ya `snapshotDao().clear()` eklendi.
İzlenen bölümler ve kitaplık zaten temizleniyordu aynı sebepten — bir cüzdan
bakiyesi ya da profil istatistiği de tam olarak o kadar kişisel, paylaşılan
bir telefonda bir sonraki kişiye miras kalmamalı.
