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

### Kesme

Yükleme ekranında videonun uzunluğu boyunca iki tutamaklı bir çubuk var.
Dokunulmazsa videonun tamamı gider ve **yeniden kodlama hiç çalışmaz** —
kullanmadığın bir özellik için pil harcamıyorsun. Tutamaklardan biri
oynatıldığı anda kesme devreye giriyor.

Kesme `MediaItem.ClippingConfiguration` ile yapılıyor, yani oynatıcının
okuduğu aynı yapı. `startsAtKeyFrame` bilerek açılmadı: anahtar kareye
yuvarlamak daha ucuz olurdu ama telefon kayıtlarında anahtar kareler
saniyelerce aralıklı, ve tutamağı koyduğun yerden iki saniye uzakta biten
bir kesme senin istediğin kesme değil.

İki sonucu var:

* **Sınırlar kesilmiş uzunluğa bakıyor.** Dört dakikalık bir kaydın on beş
  saniyesini yükleyebilirsin; eskiden bütünüyle reddedilirdi. Boyut sınırı da
  aynı şekilde oranlanıyor — kesilen payın dosyanın kendi bit hızındaki
  karşılığı. Yeniden kodlama bunu daha da küçülttüğü için tahmin hep yukarı
  yanılıyor, ki bir sınır için doğru yön bu.
* **Kesme başarısız olursa yükleme durur.** Küçültme için "olmazsa orijinali
  gönder" doğru takas, kesme için değil: on beş saniye isteyip üç dakika
  yüklemiş olmayı ancak yayınladığın şeyi izleyerek fark ederdin.

Kapak karesi kesilmiş dosyadan alınıyor, orijinalden değil.

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

## 6.6 Ekrana yerleşme: yatay videonun yarısı neden kayboluyordu

İlk sürüm her videoyu `RESIZE_MODE_ZOOM` ile çiziyordu — yani ekranı
dolduruyor, taşanı kırpıyordu. Dikey bir video için doğru; yatay bir video
için görüntünün yarısını atmak demek.

Ama çözüm "her zaman sığdır" da değil. 9:16 bir klip 20:9 bir telefonda
genişliğinin yaklaşık beşte birini kenarlara veriyor, ve tam ekran izlensin
diye çekilmiş bir şeyi siyah çubuklara almak da yanlış cevap.

Bu yüzden sorulan soru **videonun ne kadarının kaybolacağı**, hangi yöne
uzun olduğu değil:

```
video  = yükseklik / genişlik
çerçeve = ekran yüksekliği / ekran genişliği

kayıp = 1 − min(video, çerçeve) / max(video, çerçeve)
```

`kayıp ≤ 0.25` ise doldur, değilse sığdır. Rakamlarla:

| video | 20:9 telefonda kayıp | sonuç |
| --- | --- | --- |
| 9:16 dikey | %20 | doldurur |
| 9:20 uzun dikey | %5 | doldurur |
| 1:1 kare | %55 | sığdırır |
| 16:9 yatay | %75 | sığdırır |

Boyutu bilinmeyen video sığdırılıyor: sunucunun ölçüsünü kaydetmediği bir
video 9:16 olmaktan çok alışılmadık bir şey olma ihtimalindedir, ve sığdırmak
hiçbir şeyi kesmeyen seçenek.

Kapak resmi de aynı kurala uyuyor, böylece ilk kare geldiğinde görüntü
yerinden oynamıyor.

### Yükleyenin seçimi

Bunun üstünde `fit_mode` sütunu var: `original` (varsayılan, yukarıdaki
kural) ya da `fill` (her zaman doldur, taşanı kes). Yükleme ekranında iki
çipli bir seçim.

**Piksellere dokunulmuyor.** Tercih videonun yanında duruyor ve oynatılırken
uygulanıyor — dosyaya işlenseydi fikir değiştirmenin bedeli videoyu yeniden
yüklemek olurdu. Tanımadığı bir değer `original`'a düşüyor: burada yanlış
tahmin etmenin bedeli kenarları kesilmiş bir video, o yüzden güvenli
varsayılan hiçbir şey kesmeyen.

Bu sütun `ShortsSchema::VERSION`'ı `1`'den `2`'ye çıkardı; eklenti yüklenince
`maybe_upgrade()` `ALTER TABLE` ile ekliyor.

---

## 6.7 İmzalı adresler ve hiç isabet etmeyen önbellek

6.5'te akışa 256 MB'lık bir disk önbelleği eklendi. **Bir kez bile isabet
etmedi.**

Videonun adresi imzalı bir bağlantı: imzalandığı anı ve o ana atılmış bir imzayı
taşıyor. Aynı akışı iki kez istediğinde her URL farklı geliyor — bayt bayt aynı
dosya, sadece sorgu dizesi oynuyor. Aşağıdaki her şey URL'e göre anahtarlanır:

* ExoPlayer'ın disk önbelleği → aynı videoyu iki kere yazdı, hiçbirini
  diskten servis etmedi.
* Coil'in görsel önbelleği → kapaklar her ekranda yeniden indi.
* Kovanın önündeki herhangi bir CDN → hiçbir şeyi tutamazdı.

İki yerden düzeltildi.

**Sunucu — imza saniyeye değil pencereye sabitlendi.** `S3Signer::anchor()`
saati aşağı yuvarlıyor ve ömrü aynı pencere kadar uzatıyor, böylece pencerenin
son saniyesinde üretilen bir bağlantı da istenen süreyi tam taşıyor. Pencere,
istenen ömrün dörtte biri: bir bağlantı ayarda yazandan en fazla çeyrek kadar
uzun yaşıyor, ayar ne olursa olsun. Varsayılan bir saatlik ömürde bu, aynı
videonun on beş dakika boyunca aynı adresi alması demek.

**Uygulama — önbellek anahtarı imzayı yok sayıyor.** Varsayılan anahtar URL'in
tamamı; artık `host + yol`. Kovadaki bir nesne, orada durduğu sürece tek bir
önbellek girdisi. Bayt anahtarı doğrulanmıyor diye bir güvenlik kaybı yok:
sorgu dizesi *indirme* izninin kanıtı, ve o izin baytlar çekilirken kontrol
ediliyor — uygulamanın kendi yazdığı bir önbellekten okurken değil.

Kapak resmi de küçüldü: 1080p bir kareden çıkan JPEG çeyrek megabayttı ve grid
onu satırda üçe gösteriyordu. Artık videonun kendisiyle aynı kısa kenara (720)
sığdırılıyor — bir küçük resim, temsil ettiği videodan büyük olmamalı.

---

## 6.8 Grid sayfaları: sayıp göstermemek

Etiket sayfası başlıkta "2 video" deyip altında hiçbir şey göstermiyordu.

Sorgular suçlu değildi — `tests/sql/run.php` bunu kanıtlıyor: eklentinin kendi
tabloları kuruluyor, satırlar kendi deposuyla yazılıyor, sayım ve liste kendi
sorgularıyla okunuyor, ve her yazımda, her sayfa sınırında, yayından kaldırılan
ve silinen videolarla birlikte aynı sayıyı veriyorlar.

Boş kalabilmesinin geriye kalan yolları kapatıldı:

* **Kapaksız video artık boş bir dikdörtgen değil.** Kapak en iyi çaba —
  video kovaya girdikten sonra telefonda çekiliyor — ve gelmediğinde kare
  düz bir renkti. Bir grid dolusu düz renk, "video yok" gibi okunuyor.
  Artık simge ve açıklamayla dolu.
* **Başarısız yükleme artık "henüz video yok" demiyor.** Snackbar dört
  saniyede gidiyordu, geriye hiç kullanılmamış bir etiket gibi görünen bir
  sayfa kalıyordu. Artık "yüklenemedi" diyor ve tekrar deneme sunuyor.
* **Sayıp gösteremediğinde bunu söylüyor.** Başlık iki video sayıp grid
  boşsa sayfa kendi kendisiyle çelişiyor demektir; altına "henüz video yok"
  yazmak bunun üstüne yalan söylemek olur. Artık ne olduğunu söylüyor ve
  tekrar deneme sunuyor — ve bir daha olursa hangi tarafın yanıldığı
  ekrandan okunabiliyor.
* **Sayfalama eklendi.** Üç grid sayfası da ilk 21 videoyu alıp duruyordu;
  artık sona iki satır kala bir sonrakini istiyorlar.

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
- **Alan denetimi** — 519 DTO anahtarının hepsini sunucu gerçekten yazıyor
  (`tools/checks/dto_payload_keys.py`).
- **Sorgular gerçekten koşuldu** — `tests/sql/run.php`: eklentinin kendi
  tabloları SQLite'ta kuruluyor, satırlar kendi deposuyla yazılıyor, okumalar
  kendi sorgularıyla yapılıyor. 9 kontrol; `by_tag`'i yazılmış etikete
  bakacak şekilde bozmak ve silmenin etiket satırını bırakması, ikisi de
  düşürerek doğrulandı.
- **İmza penceresi** — 4 birim testi: aynı nesne pencere içinde aynı URL'i
  veriyor, pencere dolunca ilerliyor, sabitlenmiş bağlantı istenen ömrü tam
  taşıyor, çok kısa bağlantıya da taban pencere veriliyor. Sabitlemeyi
  kaldırarak düşürüldü. SigV4 çapraz kontrolünün 22 vektörü değişmedi:
  zaman damgasını açıkça veren bir çağrı hâlâ tam o anı imzalıyor.
- **Ekrana yerleşme tercihi** — iki duman adımı: `fit_mode` akışa geçiyor, ve
  tanımadığı bir değer (`''`, `zoom`, `crop`, `FILL`) kırpmayan moda düşüyor.
  İkisi de hatayı geri koyarak düşürüldü.
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
- Yeniden kodlama ve kesme. Transformer API'si belgelere karşı doğrulandı ama
  tek bir cihazda koşulmadı: hangi telefonun kodlayıcısının ne kabul ettiği
  burada denenemez. Küçültme başarısız olursa orijinal yükleniyor — en kötü
  durum bu adımın olmadığı hali. Kesme başarısız olursa yükleme hata veriyor,
  çünkü sessizce kesilmemiş videoyu göndermek daha kötü.
- Kesme çubuğunun ve `fit_mode` çiplerinin ekrandaki görünümü. Compose
  burada derlenemiyor; iki kontrol de projede zaten kullanılan bileşenlerden
  (`RangeSlider` imzası resmi API referansına karşı doğrulandı).
- `MediaMetadataRetriever` ile kare alma ve süre okuma: her içerik
  sağlayıcısının her alana cevap vermesi zorunlu değil, o yüzden her alan
  düşüyor ama hangi telefonun ne verdiği burada denenemedi.
- Backblaze'e çok parçalı doğrudan yükleme: aynı kod yolu bölüm yükleyicisinde
  çalışıyor, ama bu uçla birlikte hiç koşulmadı.
