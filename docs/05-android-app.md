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
