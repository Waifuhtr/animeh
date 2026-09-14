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

### 5. Hızlı kaydırma ön yüklemeyi aşıyordu

ExoPlayer kendi kendine ileriyi tamponluyor ama yalnızca **bir sonraki** öğeye,
ve ancak o andaki öğe dolduktan sonra. Düzenli bir kaydırma için yeterli, hızlı
bir kaydırma için değil: iki saniyede üç fiske ve dördüncü video sıfırdan
başlıyor — bir akışın "anında" hissini kaybettiği an tam olarak burası.

Artık sonraki **üç** videonun ilk birer megabaytı, oynatıcının okuduğu aynı
önbelleğe doğrudan yazılıyor (`CacheWriter`). Bir megabayt, 720p'ye yeniden
kodlanmış bir kısa videoda birkaç saniye — oynatıcının ilk kareyi göstermek
için beklediği çeyrek saniyenin kat kat üstünde. Ekrandaki video ilk 800
milisaniye bağlantıyı kendine alıyor, ondan sonra diğerleri başlıyor, ve her
kaydırma önceki ön yüklemeyi iptal ediyor.

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

**Düzeltme tamamen istemcide.** Önbellek anahtarı artık URL'in tamamı değil:
video için `host + yol`, görsel için sorgu dizesi atılmış hâli. Kovadaki bir
nesne, orada durduğu sürece tek bir önbellek girdisi. İstek yine imzalı gidiyor
— değişen tek şey girdinin *adı*. Bir güvenlik kaybı yok: sorgu dizesi indirme
izninin kanıtı ve o izin baytlar çekilirken kontrol ediliyor, uygulamanın kendi
yazdığı bir önbellekten okurken değil.

### Sunucuda denenip geri alınan yol

Bir sürüm boyunca imza sunucuda pencereye sabitlenmişti: saat aşağı yuvarlanıp
ömür aynı kadar uzatılıyordu, böylece aynı nesne on beş dakika aynı adresi
alıyordu. Bütün önbelleklerin isabet etmesi için en temiz yol buydu ve **geri
alındı**: o sürümde videolar hiç açılmadı, ve imzanın `X-Amz-Date`'i geçmişe
taşıması tek makul şüpheliydi. AWS bunu kabul eder, Backblaze'in aynısını yapıp
yapmadığı buradan sınanamıyor — ve sınanamayan bir varsayım uğruna her medya
adresini riske atmaya değmez. İstemci tarafındaki anahtar aynı kazancı hiçbir
protokol riski olmadan veriyor.

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

## 6.9 Türkçe etiket yolda nasıl kayboluyordu

`#keşfet` etiketine basınca açılan sayfanın başlığı **`#kefet`** yazıyor ve
altında hiçbir şey olmuyordu. `ş` yolda yok olmuştu.

`sanitize_text_field()` bulduğu **her `%XX` dizisini siler**. Bir yol
segmentindeki `ş` `%C5%9F`'tir. Etiket sunucuya hâlâ kodlanmış hâlde ulaştığında
sanitize onu `kefet`e çeviriyor, ve `kefet` kimsenin yazmadığı bir kelime —
sayfa doğru çalışıp doğru cevabı veriyordu: sıfır video.

Kodlamanın iki katmanı vardı: rota `Uri.encode` ile bir segment yapıyor,
Retrofit yol segmentini bir kez daha kodluyor. İkisi de düzeltildi:

* **İstemci** — `ShortTagViewModel` etiketi okurken `Uri.decode` ediyor. Zaten
  çözülmüş bir etikette hiçbir şey yapmıyor; bir etiket harf, rakam ve alt
  çizgiden ibaret olduğu için içindeki `%` her zaman bir kodlamadır.
* **Sunucu** — `Hashtag::from_path()` önce çözüyor (en fazla iki kat, ve ancak
  sonuç geçerli UTF-8 ise), sonra ayrıştırıcının kullandığı karakter sınıfını
  uyguluyor. Böylece `keşfet`, `ke%C5%9Ffet` ve `ke%25C5%259Ffet` aynı sayfaya
  çıkıyor.

Duman koşucusunun `sanitize_text_field` taklidi sadece `trim` yapıyordu, yani
bu hatayı asla gösteremezdi; artık gerçeğinin yaptığı gibi octet siliyor ve
`ShortsController` de rota kayıt listesinde — AnimehTok'un kırk küsur rotası o
listede hiç yokmuş.

---

## 6.10 Sessiz siyah ekran

Bir video açılmadığında akış hiçbir şey söylemiyordu: kovanın reddettiği bir
bağlantı, bu telefonun çözemediği bir kodek ve hâlâ inmekte olan bir video
ekranda birbirinin aynı siyah dikdörtgen. "Hiç yüklenmiyor" raporunun
arkasında hangisinin olduğunu anlamanın yolu yoktu.

Artık sebep ekranda, **HTTP kodu dahil**, ve yanında tekrar deneme var.

---

## 6.11 Paylaşmanın yavaşlığı

Yükleme parça boyutu 32 MB. İki gigabaytlık bir bölüm için doğru sayı — uzun
bir yüklemeyi on bin parça tavanının altında tutuyor. Bir kısa video için
**bütün dosyayı tek parça** yapıyordu: tek bir PUT, tek bağlantı, ve sıfırda
duran sonra bir anda dolan bir çubuk. Bir telefonun yükleme hattı tek
bağlantının taşıyabileceği kadar değil, ve tek bir parçanın paralelleştirilecek
hiçbir yanı yok.

Üç yerden düzeltildi:

* **Sunucu** — `B2Client::part_size()` çağıranın istediği boyutu kabul ediyor,
  iki uçtan da sınırlayarak: S3 son parça dışında beş megabaytın altını
  reddeder, ve parça sayısı istenen ne olursa olsun protokol tavanının altında
  tutulur. AnimehTok beş megabayt istiyor.
* **Uygulama** — parçalar artık **dörder dörder** gidiyor. Okuma tek iş
  parçacığında ve sırayla kalıyor (bir içerik sağlayıcısının konumlanabilir
  olması gerekmiyor, ve disk zaten yavaş taraf değil); her parça boşta olan
  yükleyiciye veriliyor. Bellek, havada olan parça kadar: üretici okumadan
  önce yer bekliyor.
* **Sıkıştırma atlanabiliyor** — yeniden kodlamanın amacı piksel değil,
  saniye başına bayt. Zaten hafif bir dosyayı yeniden kodlamak yükleyenin bir
  dakikasına ve bir nesil kaliteye mal olur, karşılığında hiçbir şey vermez.
  Ölçülen bit hızı üç megabitin altındaysa dosya olduğu gibi gidiyor.

Bir yan fayda: altı parça, çubuğu altı kez ilerletiyor — öncekinden beş fazla.

---

## 6.12 Keşfet ne öneriyor

İstenen kural tekti: **bir video ne kadar çok izleniyorsa o kadar çok
önerilsin.** Sıralama artık üç kademe:

1. **Görülmemişler önce.** Kaydırıp geçtiğin bir video yarın yeniden karşına
   çıkmıyor.
2. **Son üç günün videoları önce.** Yalnızca popülerliğe göre sıralanmış bir
   akış, hiçbir yeni şeyin popüler olamayacağı bir akıştır — çünkü hiçbir yeni
   şey gösterilmez. İlk yükleme ancak bu pencere sayesinde görülüyor.
3. **Sonra puan:** `izlenme + beğeni × 4 + kaydetme × 6 + yorum × 8`.

İzlenme ilk sırada çünkü sorulan soru o. Diğerleri bir izlenmeden ağır, çünkü
vermesi daha pahalı: bir izlenme kıpırdamayan bir parmak, bir beğeni bir karar,
bir yorum bir cümle.

Doğrusal ve açık yazılmış, logaritma değil: bu kodun çalıştığı her veritabanı
toplama yapabiliyor, ve okunabilen bir formül itiraz edilebilen bir formüldür.

---

## 6.13 Çan ve profil

**Çan türetilmiş, saklanmıyor.** Bir bildirim tablosu her takip, beğeni ve
yorumda bir yazma, her geri almada bir yazma daha isterdi — ve bir yol onu
güncellemeyi ilk unuttuğunda sessizce yanlışa düşerdi. Cevabı veren satırlar
zaten burada, her biri ne zaman olduğunu üstünde taşıyarak. Üç okuma ve bir
sıralama, doğruluğu kanıtlanamayan bir tablodan ucuz.

Okundu durumu tek bir zaman damgası: çanın en son ne zaman açıldığı,
kullanıcı meta'sında. Satır başına tutulacak bir şey yok, ve liste açıkken
gelen bir bildirim işaretten yeni olduğu için okunmamış kalıyor.

**Profil kendi profili.** Birinin kısa video izleyicisi için yazdığı şey anime
tarafı için yazdığı şey değil, ve ikisi birbirinin üstüne yazmamalı — bu yüzden
bio ve bağlantı AnimehTok'a ait iki ayrı meta alanında.

Bağlantı tek dikkat isteyen yer: yabancılara gösteriliyor ve bir kısmı ona
dokunuyor. Yalnızca `http` ve `https` hayatta kalıyor — bir profil alanındaki
`javascript:` bir profil alanının saldırıya dönüşme şekli — ve URL olmayan bir
şey metin olarak saklanmak yerine hiç saklanmıyor. Çıplak bir alan adı
tamamlanıyor, çünkü insanların yazdığı şey o.

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
- **Yoldan gelen etiket** — 3 birim + 1 duman testi: `keşfet`, `ke%C5%9Ffet` ve
  `ke%25C5%259Ffet` aynı anahtara düşüyor, çözülen `%2F` etikette kalmıyor, ve
  rotanın gerçekten bu geri çağrıyı kullandığı kontrol ediliyor.
  `sanitize_text_field`'a geri dönerek düşürüldü — ve duman koşucusundaki
  taklidi artık gerçeğinin yaptığı gibi octet siliyor, yoksa bu hatayı
  gösteremezdi.
- **`ShortsController` rota kaydı** — kırk küsur rota artık duman koşusunda
  gerçekten kaydediliyor; listede hiç yokmuş.
- **Öneri sıralaması** — 2 SQL kontrolü gerçek satırlarla: aynı yaştaki iki
  videodan izleneni öne geçiyor, ve bugün yüklenen bir video eski bir hitin
  arkasında kalmıyor. Skoru ve tazelik penceresini ayrı ayrı kaldırarak
  düşürüldü.
- **Profil bağlantısı** — `javascript:`, `intent:`, `data:` ve `ftp:` şemaları
  boşa düşüyor; çıplak alan adı tamamlanıyor.
- **Çan** — üç tabloyu da sorduğu ve kendi eylemlerini dışarıda bıraktığı
  kontrol ediliyor.
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
