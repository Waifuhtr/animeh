# AnimehTok

Kaydırmalı kısa video rafı. Aynı Animeh hesabı, aynı Backblaze kovası, ama
kendi tabloları ve kendi sayıları.

---

## 1. Neden kendi tabloları

Manga okuyucusu tersine kurulmuştu: bir bölüm bir `episodes` satırı, bir sayfa
bir saniye. Bu, kütüphaneyi, favorileri, geçmişi ve "devam et" özelliğini
bedavaya kazandırdı — ve her "izlenen" sayısını sessizce şişirdi, ta ki her
sorgu `kind` demeyi öğrenene kadar.

Aynı takas AnimehTok için ikinci kez teklif edildi. Cevap bu sefer hayır:

- Burada hiçbir şey `animeh_history`'ye yazmıyor.
- Burada hiçbir şey puan vermiyor.
- Burada hiçbir şey sıralama tablosuna girmiyor.
- Profilde **ayrı bir AnimehTok kartı** var: video sayısı, alınan beğeni,
  izlenme, takipçi. Kendi birimleriyle.

Bu bir yorum değil, **test edilen bir sözleşme**: duman koşucusunda bir kontrol
akış/video/görüntülenme uçlarını çalıştırıp `animeh_history`, `animeh_points`
ya da `animeh_user_lists` adı geçen tek bir sorgu veya yazma çıkarsa düşüyor.

---

## 2. Depolama düzeni

İstenildiği gibi:

```
animehtok/
└── kaan-7/                        ← kullanıcı adı + hesap kimliği
    ├── dans-videosu.mp4
    ├── dans-videosu-kapak.jpg
    ├── ikinci-video.mp4
    └── ikinci-video-kapak.jpg
```

Ad slug'a çevriliyor, çünkü bir görünen ad eğik çizgi taşıyabilir ve
anahtardaki bir eğik çizgi kimsenin kastetmediği bir klasördür. Hesap kimliği
ekleniyor, çünkü iki "kaan" aynı klasörü paylaşmamalı ve bir ad değişikliği
zaten yüklenmiş videoları öksüz bırakmamalı.

Kapak videonun yanında duruyor, ayrı bir klasörde değil: bir yaratıcının
klasörünü silmek kapaklarını da götürsün diye.

---

## 3. Yükleme yolu

Video **PHP'nin içinden geçmiyor**. Bölüm yükleyicisinin kanıtladığı aynı
şekil:

```
telefon                     eklenti                    Backblaze
   │                           │                           │
   ├─ POST /shorts/uploads ───►│                           │
   │  (ad, boyut, açıklama)    ├─ çok parçalı başlat ─────►│
   │◄── her parça için imzalı ─┤                           │
   │    URL + slug             │                           │
   │                           │                           │
   ├─ PUT parça 1 ─────────────┼──────────────────────────►│
   ├─ PUT parça 2 ─────────────┼──────────────────────────►│
   │                           │                           │
   ├─ POST /shorts/uploads/complete ──►│                    │
   │  (anahtar, upload_id, ETag'ler)   ├─ tamamla ─────────►│
   │◄── kaydedilmiş video ─────────────┤                    │
   │                                   │                    │
   ├─ POST /shorts/{id}/cover ────────►│  (küçük, JPEG)     │
```

Kırk megabaytlık bir video PHP'den geçerse başkasının sunucusunda sırasıyla
`upload_max_filesize`, bellek sınırı ve yürütme zaman aşımına çarpar.

**Tamamlama anahtarı doğruluyor.** Gelen anahtar hesabın kendi ön ekiyle
başlamıyorsa 403. Bu kontrol olmasa, giriş yapmış biri farklı bir anahtar
göndererek herhangi bir yaratıcının klasörüne yükleme bitirebilirdi.

Slug'ı **sunucu seçiyor**, telefon değil: slug hem kovadaki dosya adı hem de
videonun adresi, ve ikisi de istemcinin seçeceği şey değil.

### Sınırlar

| | |
| --- | --- |
| En uzun video | 3 dakika |
| En büyük dosya | 300 MB |
| En uzun açıklama | 2200 karakter |
| En uzun yorum | 1000 karakter |
| Bir açıklamadan alınan en fazla etiket | 20 |

Uzunluk ve boyut telefonda da kontrol ediliyor, yani üç saatlik bir film
üç yüz megabayt yüklendikten sonra değil, seçildiği anda reddediliyor.

---

## 4. Özellikler

Kaydırmalı akış, iki sekme: **Senİn İçin** ve **Takip**.

| | |
| --- | --- |
| Beğeni | Ekranda anında, sunucuya sonra. Reddedilirse geri alınıyor. |
| Yorum | Tek seviye yanıt, yorum beğenisi, kendi yorumunu silme |
| Kaydetme | Kendi rafın — beğeniden ayrı, herkese açık değil |
| Takip | **Tek yönlü**, onaysız. Uygulamanın karşılıklı arkadaşlığından ayrı bir tablo. |
| Ses | Her videonun bir sesi var; ses sayfası o sesi kullanan her videoyu listeliyor |
| Etiket | Açıklamadaki `#etiket`'ler ayrıştırılıp indeksleniyor; etiket sayfası ve öne çıkan etiketler |
| Arama | Video, kişi, etiket ve ses — tek istekte |
| Görüntülenme | Kişi başına bir kez sayılıyor |
| Moderasyon | Yönetim panelinde her video, yayınlanmamışlar dahil; silmek satırı, videoyu ve kapağı birlikte kaldırıyor |

**Olmayanlar** (istenmedi): indirme, paylaşma, video düzenleme, filtre, düet.

### Ses neden kendi dosyası değil

Bir ses, geldiği videonun sesidir. Ayrı bir kopya saklamak aynı baytlar için
faturayı ikiye katlardı. `origin_short_id` sesin çalındığı videoyu gösteriyor;
o video silinirse ses, kendisini kullanan bir sonraki videoya taşınıyor ve
başka kullanan kalmadıysa siliniyor.

### Türkçe etiketler

`Support/Hashtag` saf bir sınıf ve test edilmiş, çünkü aynı kuralı iki dil
uyguluyor: uygulama etiketin altını çizmek için, sunucu indekslemek için.

Var olma sebebi Türkçe: `strtolower("İZLE")` varsayılan Unicode kurallarıyla
birleşen noktalı bir dizge üretir ve `"izle"` ile **eşleşmez**. Yani etiket
sayfası, yazılışa göre bazen boş çıkardı. Sınıf noktalı/noktasız çifti açıkça
eşliyor, böylece `#İZLE`, `#izle` ve `#ızle` aynı sayfaya düşüyor.

---

## 5. Giriş noktası

AnimehTok bir sekme değil, bir **mod**. Ana sayfadayken **Ana Sayfa** sekmesine
tekrar basınca bir sayfa açılıyor:

> **AnimehTok**
> Kaydırarak izlenen kısa videolar. İzleme süresi ve puan buraya işlemez.
> [ Kısa video moduna geç ]
> [ Ana sayfada kal ]

Altıncı bir sekme olsaydı, hiç istemeyen birinin de her açılışta karşısında
olurdu. Profildeki AnimehTok kartından da açılıyor.

---

## 6. Oynatma

Akış tek bir ExoPlayer kullanıyor ve **tüm sayfa listesini ona çalma listesi
olarak veriyor**. Sayfa değişince `seekToDefaultPosition(sayfa)` çağrılıyor.

Bu, bir sonraki videonun anında başlamasının sebebi: ExoPlayer kendi
kendine ileriyi tamponluyor. Ayrıca bir telefonun kod çözücü sayısının, biri
bir dakikada yirmi videoyu geçerken ayakta kalabileceği tek düzen bu — her
sayfa için bir oynatıcı kurup yıkmak değil.

Döngü `REPEAT_MODE_ONE` ile **değil**, sona gelince başa sararak yapılıyor —
sebebi aşağıdaki 6.5'te. İlerlemek pager'ın işi, oynatıcının değil.

---

## 6.5 Hız: neden onlarca saniye sürüyordu

İlk sürümde videolar başlamadan önce onlarca saniye geçiyordu. Dört sebep
vardı ve en büyüğü hiç dokunmadığım bir varsayılandı.

### 1. ExoPlayer ilk kareden önce 2.5 saniyelik medya bekliyor

`bufferForPlaybackMs` varsayılanı 2500. Telefonla çekilmiş bir klip 10–25 Mbps
akıyor, yani 2.5 saniyesi **üç ila sekiz megabayt** — hiçbir şey görünmeden
önce yirmi saniyelik indirme. O varsayılan iki saatlik bir film için yazılmış:
jenerikten önceki fazladan bir saniye görünmez, ortadaki bir takılma görünür.
Kısa videoda denklem ters.

Artık **250 ms**. Kısa video on beş saniye ve kendi kendine baştan başlıyor;
korunacak derin bir ön yükleme yok.

### 2. `REPEAT_MODE_ONE` bütün ön yüklemeyi kapatıyordu

ExoPlayer zaman çizelgesindeki *bir sonraki pencereye* doğru tamponluyor.
Repeat-one altında bir sonraki pencere mevcut pencerenin kendisi — yani
oynatıcı sonraki videoya **hiç dokunmuyordu**. İkinci, üçüncü, onuncu videonun
da yavaş olmasının sebebi buydu; "yavaş" değil "bozuk" hissettiren kısım da bu.

Repeat artık kapalı; döngü sona gelince başa sarılarak yapılıyor. Çalma
listesi ileriye tamponlamakta serbest.

### 3. Hiçbir şey saklanmıyordu

Geri kaydırmak az önce izlenen videoyu yeniden indiriyordu. 256 MB'lık disk
önbelleği geri dönmeyi anlık yapıyor.

### 4. Akış yükü video başına beş sorgu yapıyordu

Ses, etiket, takip, yaratıcı ve sesin çalındığı video — hepsi döngünün içinde,
her video için ayrı. On videoluk bir sayfa, telefona tek bayt video verilmeden
önce elli sorgu ve kırk imza. Hepsi sayfa başına tek sorguya indi.

Ölçüldü: **1 video 4 sorgu, 8 video 4 sorgu.** Geri aldığımda 1 video 6,
8 video 20 oluyor. Duman koşucusundaki kontrol mutlak sayıyı değil, video
sayısıyla artan sorgu sayısını arıyor.

### Ve asıl tavan: bit hızı

Yukarıdakiler ilk kare için gereken **bayt miktarını** düşürüyor ama **bit
hızını** düşürmüyor. 20 Mbps'lik bir dosya hiçbir ayarla mobil bağlantıda
akıcı olmaz — baytlar orada.

Bu yüzden video artık **yüklenmeden önce telefonda yeniden kodlanıyor**: kısa
kenar 720'ye kapatılıyor, yani 1080×1920 bir kayıt 720×1280 oluyor. Üç kez
ödüyor: izleyici baytların bir kısmını bekliyor, yükleyen yüklemenin bir
kısmını bekliyor, ve kova depolamanın ve çıkış trafiğinin bir kısmı için
faturalandırılıyor.

Kısa kenar, yükseklik değil. Telefon videosu dikey, yani yüksekliği uzun
kenarı: "720 yüksek" istemek 1080×1920'yi **405×720** yapardı — herkesin 720p
dediği şeyin dörtte bir genişliği, ve gözle görülür bulanık.

Kodlayıcı ayarlarına kasıtlı olarak dokunulmuyor. Media3 bit hızını çıktı
çözünürlüğünden türetiyor; belirli bir bit hızı istemek, başarısızlığı
cihaza özel bir dışa aktarma hatası olan ikinci bir API demek. İşi çözünürlük
yapıyor.

Her şey **en iyi çaba**: kodlayıcısı reddeden bir cihaz, beklenmedik bir
codec, muxer'ın kabul etmediği bir dosya — hepsi orijinali yüklemeye geri
düşüyor, yani bu adım var olmadan önce ne oluyorsa o.

---

## 7. REST yüzeyi

Namespace `animeh/v1`. **Her rotada gerçek bir `permission_callback`.**

### Giriş gerektirmeyenler

| | |
| --- | --- |
| `GET /shorts/feed?tab=foryou\|following` | akış |
| `GET /shorts/{id}` | tek video |
| `GET /shorts/{id}/comments` | yorumlar (`parent` ile yanıtlar) |
| `GET /shorts/tags` | öne çıkan etiketler |
| `GET /shorts/tags/{tag}` | etiket sayfası |
| `GET /shorts/sounds/{id}` | ses sayfası |
| `GET /shorts/users/{id}` | yaratıcı sayfası |
| `GET /shorts/search?q=` | arama |
| `POST /shorts/{id}/view` | görüntülenme |

Katalog gibi açık: ilk videonun önündeki duvar, hesap açma sebebinin önündeki
duvardır. Beğeni düğmesi hesap istiyor.

### Oturum gerektirenler

| | |
| --- | --- |
| `POST /shorts/uploads` | parçaları imzala |
| `POST /shorts/uploads/complete` | tamamla ve kaydet |
| `POST /shorts/{id}/cover` | kapak (ham JPEG gövde) |
| `PUT/DELETE /shorts/{id}` | açıklamayı değiştir / sil |
| `POST/DELETE /shorts/{id}/like` | beğeni |
| `POST/DELETE /shorts/{id}/save` | kaydetme |
| `POST /shorts/{id}/comments` | yorum |
| `DELETE /shorts/comments/{id}` | yorum sil |
| `POST/DELETE /shorts/comments/{id}/like` | yorum beğenisi |
| `POST/DELETE /shorts/users/{id}/follow` | takip |
| `GET /me/shorts`, `/me/shorts/saved`, `/me/shorts/stats` | kendi rafın |

### Yönetim

| | |
| --- | --- |
| `GET /admin/shorts` | her video, yayınlanmamışlar dahil |
| `DELETE /admin/shorts/{id}` | kaldır — satır, video ve kapak birlikte |

---

## 8. Akış nasıl sıralanıyor

Bir öneri motoru değil ve öyleymiş gibi de yapmıyor.

Yeniden eskiye, ama **izleyicinin daha önce gördüğü her şey görmediklerinin
arkasına** itiliyor, ve her grubun içinde beğeni sayısı eşitliği bozuyor. Bu
büyüklükte bir katalogda herhangi bir puandan daha iyi bir akış, ve önemli
olan özelliği taşıyor: kaydırmak, az önce geçtiğin videoyu geri getirmiyor.

"Görüldü" satırları otuz gün sonra budanıyor — silinmeden tutulsa küçük bir
katalogda akış döner, sonsuza kadar tutulsa tablo anlattığı videoları aşar.

---

## 9. Bildirimler

Mevcut push altyapısı üzerinden, yalnızca üç durumda:

- videonu biri beğendi
- videona biri yorum yaptı
- biri seni takip etmeye başladı

Kendi videonu beğenmen bildirim üretmiyor.

---

## 10. Burada doğrulanan / doğrulanamayan

**Doğrulandı:**

- `Support/Hashtag` — 8 birim testi: sırayla çıkarma, tekrarları saymama,
  boşluk ve noktalamada bitme, Türkçe `İ`/`ı` katlaması, sayı ve uzunluk
  sınırları.
- `StorageKey` AnimehTok düzeni — 5 birim testi: klasör şekli, iki aynı adlı
  hesabın ayrılması, `../../gizli` gibi bir adın klasörden kaçamaması,
  tanınmayan uzantının mp4 sayılması, adsız hesap.
- **Uçlar gerçekten koşuldu** — duman koşucusunda akış (iki sekme), tek video,
  404, etiket sayfası, ses sayfası, yaratıcı sayfası, yorumlar ve arama sahte
  satırlarla baştan sona çalışıyor.
- **Sözleşme kontrolü** — yukarıdaki, `animeh_history` / `animeh_points` /
  `animeh_user_lists` sözü geçen tek bir sorgu ya da yazma çıkarsa düşen bir
  adım.
- **Tablolar dbDelta'nın gözünden** — AnimehTok'un dokuz tablosu kataloğunkiyle
  aynı üç kontrolden geçiyor: her sütun dbDelta'ya görünüyor mu, sütun olmayan
  bir şey sütun sanılıyor mu, ifadenin içinde yorum ya da noktalı virgül var mı.
- **Yol denetimi** — uygulamanın çağırdığı 149 yolun hepsinin eklentide bir
  rotası var (`tools/checks/rest_routes.py`).
- **Alan denetimi** — 518 DTO anahtarının hepsini sunucu gerçekten yazıyor
  (`tools/checks/dto_payload_keys.py`).
- Kotlin fark taraması: bu değişiklikle gelen 223 hatanın tamamı, aynı hata
  metninin halihazırda çalışan dosyalarda da çıktığı gösterilerek androidx'in
  bu ortamda görünmemesine bağlandı. İki tanesi **gerçekti** ve düzeltildi:
  `androidx.annotation.OptIn`'in üyesi `markerClass` adında olduğu için
  konumsal argüman derlenmezdi (ve media3'ün opt-in'i zaten uyarı seviyesinde,
  yani hiç gerekmiyordu), ve genel `UiState.Success` için tip argümansız bir
  `as?` dönüşümü.

**Doğrulanamadı — ilk gerçek çalıştırma sende olacak:**

- Gerçek bir cihazda kaydırma akıcılığı ve ilk kare süresi. Tampon eşiği,
  ön yükleme ve önbellek doğru düzen ama burada ölçülemedi.
- Yeniden kodlama. Transformer API'si belgelere karşı doğrulandı ama tek bir
  cihazda koşulmadı: hangi telefonun kodlayıcısının ne kabul ettiği burada
  denenemez. Başarısız olursa orijinal yükleniyor, yani en kötü durum bu
  adımın olmadığı hali.
- `MediaMetadataRetriever` ile kare alma ve süre okuma: her içerik
  sağlayıcısının her alana cevap vermesi zorunlu değil, o yüzden her alan
  düşüyor ama hangi telefonun ne verdiği burada denenemedi.
- Backblaze'e çok parçalı doğrudan yükleme: aynı kod yolu bölüm yükleyicisinde
  çalışıyor, ama bu uçla birlikte hiç koşulmadı.
