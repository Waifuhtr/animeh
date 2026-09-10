# Manga

Bu belge mangaların uygulamaya nasıl girdiğini, hâlihazırda manga sitende olan
her şeyin nasıl kendiliğinden aktarıldığını, görsellerin neden ve nasıl bizim
kovamıza kopyalandığını ve bozuk bir "friendly URL" karşısında ne olduğunu
anlatıyor. Sonunda **senin yapman gerekenler** var.

---

## 1. Manga anime'nin yanına oturdu, altına değil

Yeni bir tablo, yeni bir ekran ailesi, ikinci bir katalog **yok**. Sebep basit:

| Anime | Manga |
| --- | --- |
| eser (`works`, `kind = anime`) | eser (`works`, `kind = manga`) |
| bölüm (`episodes`) | bölüm (`episodes`) |
| video/altyazı (`sources`, `kind = video`) | sayfa (`sources`, `kind = page`) |

Bu yüzden manga tarafı için **hiçbir şey yeniden yazılmadı**: kitaplık,
favoriler, izleme geçmişi, "devam et", **bölüm başına 20 puan**, sıralamalar,
bildirimler — hepsi satırlar var olduğu an çalışmaya başladı.

Gerçekten farklı olan iki şey vardı ve sadece onlar yazıldı: bir bölüm
oynatılmaz **okunur**, ve sayfaları başka bir yerden geldi, oradan alınması
gerekiyor.

### Bölüm numaraları

`10.5` gerçek bir bölüm ve `10`'dan farklı. Sütun artık `decimal(8,2)`.
Uygulamaya iki alan gidiyor: `number` (tam kısım, hep olduğu gibi — eski
ekranlar bozulmasın) ve `number_label` ("10.5"). Ekranda görünen ikincisi.

### Okuma ilerlemesi neden "saniye"

Geçmiş tablosu saniye sayıyor, çünkü bugüne kadar içindeki her şey izleniyordu.
Bir bölümün sayfası var. Ayrı bir okuma tablosu açmak yerine **bir sayfa bir
saniye** sayılıyor ve bölümün "süresi" sayfa sayısı oluyor.

Bu bir kılıf değil, kasıtlı: böylece bir manga bölümü bitirmek, bir anime
bölümü bitirmekle **aynı** tamamlanma testinden geçiyor — aynı geçmişe, aynı
"devam et"e, aynı 20 puana ve aynı sıralamalara giriyor. Tek görünür yan
etkisi profildeki "izlenen süre": 20 sayfalık bir bölüm 20 saniye ekliyor.
Saatlerle ölçülen bir toplamın yanında fark edilmez; alternatifi hiçbir şey
kazandırmayan bir okuyucuydu.

---

## 2. Var olan kütüphaneyi taşımak — köprü

Manga siten ayrı bir WordPress. Yüzlerce bölüm, on binlerce görsel. Bunu elle
girmek söz konusu değil, ve senin sitendeki `manga`/`chapter` post tiplerini
buradan doğrudan okumanın temiz bir yolu yok.

Çözüm: manga sitesine kurulan **tek dosyalık bir eklenti** —
`animeh-manga-bridge`. Yaptığı tek şey okumak ve JSON döndürmek. Hiçbir şey
yazmıyor, hiçbir şeyi değiştirmiyor, kendi post tiplerini kaydetmiyor.

### Görsel adreslerini neden o taraf çözüyor

Bir bölümün görsellerinin nerede olduğu, senin temanda üç ayardan ve iki eski
uyumluluk bayrağından (`is_b2_hosted`, `is_bunny_hosted`, `storage_provider`)
hesaplanıyor. O mantığı buraya kopyalasaydım, senin siten değiştikçe kopyanın
doğru kalmasını sonsuza kadar takip etmem gerekirdi. Köprü bu hesabı kendi
tarafında yapıp **çalışan tam adresleri** veriyor.

### Anahtar

Köprü bir anahtarla korunuyor (`hash_equals` ile karşılaştırılıyor — düz `===`
bir sırrın uzunluğunu ve başlangıcını ölçmeyi mümkün kılar). Anahtarı bilen
biri yalnızca zaten sitede herkese açık olan mangaların listesini okuyabilir;
yazamaz, silemez. Bu, koruduğu şeye orantılı bir güvenlik — ve senin bir
parolan burada hiç saklanmıyor.

---

## 3. Görselleri kendi kovamıza kopyalamak

İçe aktarma iki ayrı iş:

**Aktarma (sync)** — köprüyü okur, satırları yazar. Hızlı, ucuz, tekrar
çalıştırılabilir: her yazma karşı sitenin post id'siyle eşleştiriliyor, ikinci
çalıştırma çoğaltmıyor güncelliyor.

**Kopyalama (mirror)** — her sayfayı indirip **bizim B2 kovamıza** koyar ve
satırı kopyaya çevirir. Yavaş, pahalı, ve bütün bunların asıl sebebi: bu
bittikten sonra manga siten yarın kapanabilir, uygulama fark etmez.

Kopyalanmadan önce de sayfalar çalışıyor — senin sitenden servis ediliyorlar.
`external_url` sütunu tam olarak bunun için.

Anahtar düzeni: `anime/{slug}/chapter-{numara×10, 5 hane}/{sayfa}.{uzantı}`.
Numara ona çarpılıyor ki 10.5 bölümü konsolda 10 ile 11 arasına düşsün, 105'ten
sonraya değil.

İkisi de **partiler hâlinde** çalışıyor (aktarmada 3 manga, kopyalamada 25
sayfa), çünkü paylaşımlı hosting'in ters proxy'si 30 saniye civarında dinlemeyi
bırakıyor. Panel bu partileri arka arkaya çağırıyor; her partinin cevabı aynı
zamanda ilerleme raporu. Kopyalamayı durdurmak hiçbir şey kaybettirmiyor —
kopyalanan her sayfa kayıtlı, yeniden başlatınca kalınan yerden devam ediyor.

---

## 4. Friendly URL bozulursa

Backblaze aynı dosyayı iki adresten veriyor:

```
friendly : https://f005.backblazeb2.com/file/{kova}/{yol}
S3       : https://{kova}.s3.{bölge}.backblazeb2.com/{yol}
```

Friendly olan kendi kendine, dakikalarca bozulabiliyor — sen de bunu yaşamışsın
ve temanda bunun için bir JS yedeği yazmışsın. Uygulamada aynı fikir, ama
sunucu tarafından kurulmuş bir sırayla:

Her sayfa şu listeyi taşıyor:

1. **bizim kovamız / friendly** — kopyalandıysa dosyanın gerçekte durduğu yer
2. **bizim kovamız / S3** — aynı nesne, öteki kapı
3. **manga siten / friendly** — henüz kopyalanmadıysa
4. **manga siten / S3** — senin `b2_s3_endpoint` ayarından türetiliyor
   (köprünün el sıkışmasında okunup saklanıyor, ikinci kez sormuyoruz)

Okuyucu bir adres başarısız olunca **beklemeden** sıradakine geçiyor: ikinci
adresin anlamı zaten farklı bir sunucu olması, sormayı geciktirmek sadece
sayfayı bekletir. Hepsi tükenirse sayfa "dokunup tekrar dene" diyor ve baştan
başlıyor — dördü birden başarısızsa sebep genelde telefonun tünelde olması.

Bu dönüştürme (`B2Url`) saf PHP ve **test edilmiş**: eğik çizgiler kodlanmıyor
(kodlansa adı içinde eğik çizgi olan bambaşka bir nesne adreslenirdi), sorgu
dizesi anahtarın parçası sayılmıyor, ve endpoint bilinmiyorsa ikinci adres
uydurulmuyor.

---

## 5. İkinci veri kaynağı

Senin Jikan sınıfın zaten **önce Tenrai, olmazsa Jikan** deniyordu — yani
Tenrai içerideydi. Uygulamadaki Tenrai istemcisine manga uçları eklendi
(`/manga/{id}/full`, `/manga?q=`), aynı istemci, aynı önbellek, aynı hız
sınırı.

Tenrai dışındaki ikinci araç **galeri kaynağı** (nhentai v2). Portlandı ve:

- **İsimle aranmıyor, numarayla bulunuyor.** Kaynağın v2 API'sinde arama ucu
  yok — senin kendi sınıfın da yalnızca `/galleries/{id}` ve `/cdn` çağırıyor.
  İlk sürüm olmayan bir `search` ucuna gidiyordu ve bu yüzden her denemede
  hata veriyordu. Artık kutuya **galeri numarasını** (`177013`) ya da
  **galerinin adresini** (`https://nhentai.net/g/177013/`) yazıyorsun.
- **Varsayılan olarak kapalı.** Yalnızca yetişkin içerik veren bir kaynağın
  kurulumla birlikte açık gelmesi doğru değil.
- Görsel sunucusunu API'den soruyor ve 12 saat önbellekliyor (servis
  sunucularını değiştiriyor; dünkü adrese kurulmuş bir kapak bozuk kapaktır).
- Dakikada 20 istekle sınırlı — 429 yiyip bir saat yasaklanmak beklemekten çok
  daha pahalı.
- Buradan gelen **her eser +18 işaretleniyor.** Tahmin değil: kaynağın kendisi
  bu. Uygulama da her zamanki sorusunu oynatmadan önce soruyor.

---

## 6. SENİN YAPMAN GEREKENLER

### a) Animeh eklentisini güncelle

Yeni sürümü kur. Şema 10'a çıkıyor; sütunlar ve tablolar kendiliğinden
oluşuyor.

> **9'da kalmış kurulumlar için:** o sürümde `works` tablosuna `author`
> sütunu eklenmiyordu (aşağıda anlatılan `dbDelta` hatası). Sürüm 10 hem
> sütunu ekliyor hem de bundan sonra her yükseltmede eksik sütun kalıp
> kalmadığını kontrol ediyor. Elle bir şey yapman gerekmiyor; eklentiyi
> güncellemen yeterli. Sonra manga ekranında **Baştan başlat** de.

### b) Köprüyü manga sitesine kur

`animeh-manga-bridge-1.0.1.zip` → **manga sitende** Eklentiler → Yeni Ekle →
Eklenti Yükle → Etkinleştir.

> **1.0.0 kuruluysa mutlaka güncelle.** O sürüm her isteğe 500 dönüyordu:
> anahtar kontrolü `bridge_key()` yerine PHP'nin kendi `key()` fonksiyonunu
> çağırıyordu. Uygulamada `Manga sitesi 500 döndürdü.` diye görünen şey buydu.

Sonra manga sitende **Ayarlar → Animeh Köprüsü**. İki değer var:

- **Köprü adresi** (`https://manga-siten.com/wp-json/animeh-bridge/v1`)
- **Anahtar**

İkisini de kopyala. Adres kısmında **sitenin kendi adresi de yeter**
(`https://manga-siten.com`): sunucu gerisini kendi tamamlıyor.

### c) Uygulamada bağla

Uygulama → **Yönetim Paneli → Manga**

1. İki değeri yapıştır → **Bağlan ve test et**. Alt satırda sitenin adı ve kaç
   manga/bölüm olduğu görünür.
2. **Mangaları içe aktar** → parti parti çalışır, bitince "İçe aktarma
   tamamlandı" der. Tekrar basmak zararsız: yeni eklenenleri alır, olanları
   günceller.
3. **Görselleri kovamıza kopyala** → uzun sürer. Ekranı açık tut; istediğin
   zaman durdurup sonra devam edebilirsin.

> Kopyalama için **Depolama ayarlarının yapılmış olması** gerekiyor (Yönetim
> Paneli → Sunucu/Depolama). Yapılmadıysa kopyalama başlamaz ve bunu söyler.

### d) İsteğe bağlı: galeri kaynağı

Yönetim Paneli → Manga → arama bölümünde kaynağı **Galeri** yap ve anahtarı aç.
Kapalıyken sormaz, "bu kaynak kapalı" der.

Arama kutusuna **numara** yaz (`177013`) ya da galerinin adresini yapıştır.
İsim yazarsan kaynağa boşuna gidilmez; ekran ne istediğini söyler.

### e) Uygulamayı derle

Space'te **Derle**. Okuyucu, manga sekmesi, "Yeni Manga Bölümleri" rayı ve
manga yönetim ekranı o derlemeyle gelir.

---

## 6.5 Bir şey olmazsa: artık sebebini yazıyor

İlk sürümde manga ekranındaki her hata "Bir şeyler ters gitti." diye
görünüyordu. Sebep sunucuda yazılıyordu ama uygulama onu atıyordu: yalnızca
400'lük yanıtların cümlesi ekrana geliyordu, 401 / 404 / 502 hepsi aynı
görünüyordu. Artık sunucunun kendi cümlesi geliyor ve içe aktarma hatası
kartın üstünde de duruyor. Muhtemel cümleler ve anlamları:

| Ekranda | Anlamı |
| --- | --- |
| Manga köprüsü henüz ayarlanmadı | Adres/anahtar boş. |
| Köprü adresi yanıt vermiyor. Manga sitesinde eklenti etkin mi? | Köprü eklentisi kurulu/etkin değil ya da adres yanlış. |
| Köprü anahtarı kabul edilmedi | Anahtar eşleşmiyor. Manga sitesindeki ekrandan tekrar kopyala. |
| Manga sitesine ulaşılamadı: … | Sunucun o siteye çıkamıyor (DNS, güvenlik duvarı, SSL). |
| Manga sitesi 500 döndürdü | Köprü eklentisi 1.0.0 ise **onu güncelle** — o sürümün anahtar kontrolü fatal veriyordu. Güncelse sorun o taraftaki sitede. |
| Manga sitesinden gelen yanıt okunamadı | Gelen şey JSON değil — genelde adres REST ucu değil. |
| Bu kaynakta arama yok… | Galeri kaynağına isim yazılmış; numara ya da adres yaz. |
| Bu kaynak kapalı | Galeri anahtarı kapalı. |
| Depolama ayarlanmadan kopyalama yapılamaz | Backblaze ayarları eksik. |
| Unknown column '…' in 'INSERT INTO' | Şema yarım kalmış. Eklentiyi 0.3.4+ sürümüne güncelle; yükseltme eksik sütunları kendisi ekler. |

---

### Kopyalarken "SocketTimeoutException / timeout"

Bu telefonun zaman aşımıydı, sunucunun değil. Bir kopyalama isteği 25 sayfayı
tek seferde indirip yüklüyordu; uygulamanın okuma zaman aşımı ise 30 saniye.
Sunucu hâlâ çalışırken telefon vazgeçiyor, kopyalama döngüsü de duruyordu.

Üç yerden birden düzeltildi:

1. **Sunucu partiyi saate göre bölüyor.** 20 saniye geçtiyse yeni görsele
   başlamıyor, o ana kadar kopyaladıklarını `partial: true` ile bildiriyor;
   uygulama zaten aynı döngüde tekrar soruyor. Barındırmanın kendi
   `max_execution_time` değeri daha darsa bütçe ona göre küçülüyor (30 sn'lik
   bir hostta 10 sn), ve önce `set_time_limit(0)` denenip daha fazla yer
   isteniyor.
2. **Uygulama bu uçlarda daha sabırlı.** Kopyalama, içe aktarma ve sayfa
   yükleme için okuma/yazma zaman aşımı 120 saniye. Katalog isteği için 30
   saniye doğru, bunlar için değildi.
3. **Kopyalanamayan parti sonsuza kadar denenmiyor.** Hiçbir sayfası
   kopyalanamayan ve süresi de dolmamış bir parti, tekrar sorulunca aynı
   satırları geri verir — döngü bitmezdi. Artık durup sebebini yazıyor.

---

### DNS: ilk denemede "Resolving timed out"

Sunucunun ilk dış isteği bazen isim çözerken 10 saniyede düşüyor, hemen
sonraki aynı ismi anında çözüyor — soğuk DNS önbelleği. Hem köprü hem galeri
istemcisi artık **yalnızca hiç yanıt gelmediğinde** bir kez daha deniyor.
Gelen bir yanıt (500 dâhil) bilgidir, ikinci kez sorulmaz.

Bu tekrar tekrar oluyorsa sorun barındırmanda: sunucunun dışarı çıkışı ya da
DNS'i yavaş. Hosting'e "outbound HTTP/DNS" diye sorman gerekir.

---

### `Unknown column 'author'` — ve içe aktarmanın boş "tamamlandı"sı

Aynı sebebin iki yüzüydü. `dbDelta()` SQL'i ayrıştırmaz: girdiyi **`;`
karakterinden böler** ve sütun listesini tek bir regex ile okur. `author`
sütununun üstündeki yorum satırında *"makes a manga; the two do not fit in one
column"* yazıyordu — oradaki noktalı virgül tabloyu ikiye biçti. O noktadan
sonraki dokuz sütun (`genres`, `author`, `total_episodes`, `duration_seconds`,
`published`, `adult`, `created_by`, `created_at`, `updated_at`) dbDelta için
hiç var olmadı, `author` eklenmedi — ama yükseltme kendini "tamamlandı" diye
işaretledi.

Sonuç:

- Galeriden **Ekle** → `Unknown column 'author' in 'INSERT INTO'` → 500.
- **Mangaları içe aktar** → her satır aynı sebeple reddedildi, importer bunu
  sessizce atladı, dokuz sayfa dönüp "tamamlandı" dedi, 0 manga.

Üç şey değişti:

1. dbDelta'ya giden ifadeden SQL yorumları çıkarılıyor. Yorumlar kodda
   sütunların yanında duruyor; yalnızca gönderirken siliniyorlar. (Bir `--`
   satırı noktalı virgül içermese bile zararlı: dbDelta onu `--` adlı bir
   sütun sanıp ayrıştırılamayan bir ALTER üretiyor.)
2. Her yükseltmeden sonra **sütunlar sayılıyor**: tabloda olmayan her sütun
   doğrudan `ALTER TABLE … ADD COLUMN` ile ekleniyor. dbDelta'nın sessizce
   atladığı hiçbir şey artık yarım kalmıyor.
3. İçe aktarma artık yazamadığında duruyor ve sebebini söylüyor. Boş bir
   "tamamlandı" bir daha çıkmayacak.

---

## 6.9 Uygulamadaki manga arayüzleri

**Yönetim Paneli** ikiye ayrıldı:

- **Manga Kütüphanesi** — bütün mangalar. Yeni manga ekleyebilir, düzenleyebilir,
  bölümlerine girebilirsin. Anime listesi artık yalnızca anime gösteriyor.
- **Manga Kaynakları** — köprü, kopyalama ve iki veri kaynağı (eskiden "Manga"
  diyen ekran).

**Bölümler.** Bir bölüm bir anime bölümü değil: süresi, videosu, intro işareti
yok ve numarası 10.5 olabiliyor. Kendi ekranı var. Bölüm satırındaki
**Sayfalar** düğmesinden:

- **Görseller** — çoklu seçim, telefondaki klasörden.
- **Zip** — bölümün indirildiği arşivi olduğu gibi.

İkisi de tek istekte gidiyor. **Sıra dosya adına göre**, sayı olarak: `1.jpg,
2.jpg, 10.jpg` → 1, 2, 10. Seçim sırası ya da metin sıralaması değil.

**Manga sayfası.** Ekteki konsept uygulandı: kapak, "Manga" rozeti, puan /
bölüm sayısı / çizer satırı, tür rozetleri, "Hakkında", bölüm listesi ("Oku"
düğmeleriyle), eleştiriler ve alttaki "Oku" çubuğu. Bölüm listesi varsayılan
olarak **ilk bölümden** başlıyor; "Son önce" ile ters çevirebilirsin.

**Arkadaşına öner.** Manga (ve anime) sayfasındaki uçak simgesi: arkadaş seç,
istersen not yaz, gönder. Karşı tarafa **bildirim** olarak düşüyor ve dokununca
eseri açıyor. Sadece arkadaşlara — sunucu bunu ekrana güvenmeden kendisi
kontrol ediyor.

**Türler.** Düzenleme formunda virgülle ayrılmış tür alanı var, hem anime hem
manga için. Türün ekranda hangi isimle görüneceği eskisi gibi **Terimler**
ekranından.

---

## 7. Ekstra: çerçeveleri toplu yükleme

Aynı sürümde: Yönetim Paneli → Çerçeveler → **+** artık **birden çok dosya**
seçiyor. Kırk çerçeve tek istekte gidiyor, her biri kendi dosya adından
adlanıyor, seçim sırası mağazadaki sıra oluyor. Biri reddedilirse diğerleri
yine ekleniyor ve reddedilen dosyanın adı ile sebebi dönüyor.

Tek dosya seçersen isim alanı yine çıkıyor; çokta çıkmıyor, çünkü kırk
çerçeveye aynı adı vermek onları ayırt edilemez yapardı.

---

## 8. Burada doğrulanan / doğrulanamayan

**Doğrulandı:**

- `B2Url` (iki adres arası dönüşüm, kodlama, sorgu dizesi, eksik parça),
  `ChapterNumber` (10.5 ≠ 10, virgüllü yazım, başlık içinden sayı),
  `MangaMapper` (iki kaynağın da aynı şekle çevrilmesi, +18 bayrağı),
  `GalleryRef` (numara, `#numara`, yapıştırılan adres, isim → 0) — 199
  birim testi geçiyor.
- Her PHP dosyası `php -l`.
- **Şema, dbDelta'nın gözünden doğrulanıyor.** Koşucu `install()`'ı çalıştırıp
  dbDelta'ya giden her ifadeyi yakalıyor ve "yazdığım sütunlar" ile "dbDelta'nın
  gördüğü sütunlar" kümelerini karşılaştırıyor. Hatayı geri koyunca tam olarak
  şunu diyor: `wp_animeh_works: dbDelta görmüyor — genres, author,
  total_episodes, …`. Ayrıca WordPress'in **gerçek** `dbDelta()` fonksiyonu
  indirilip bu ifadeye karşı koşuldu; düzeltmeden önce `ADD COLUMN author`
  üretmiyordu, sonra üretiyor.
- **Manga uçları gerçekten çalıştırıldı.** `tests/smoke/` içindeki koşucu artık
  `wp_remote_get`'i de karşılıyor, yani içe aktarma, kopyalama, iki kaynakta
  arama ve okuyucu ucu sahte bir köprü/kaynak yanıtıyla baştan sona koşuyor —
  112 kontrol. Galeri aramasının olmayan bir uca gitmesi tam olarak burada
  yakalandı; ilk sürümde bu yollardan hiçbiri hiç çalıştırılmamıştı.
- **Köprü eklentisi de çalıştırıldı.** Kendi koşucusu var
  (`animeh-manga-bridge/tests/smoke.php`, 12 kontrol): rotalar kaydoluyor mu,
  doğru anahtar kabul mü ediliyor, `/ping`, `/manga` ve `/manga/{id}/chapters`
  gerçek gövde üretiyor mu, sayfa sıralaması doğal mı (`1, 2, 10`), b2/bunny
  bayrakları hangi sırayla kazanıyor. 500'ü bu yakaladı — ve hatayı geri koyup
  koşucunun gerçekten düştüğü doğrulandı.
- **`tools/php-call-check.php`**: her PHP dosyasında, çağrılan yerleşik
  fonksiyona verilen argüman sayısı imzasıyla karşılaştırılıyor. `key()`
  hatası tam olarak bu şekilde görünür oluyor (`en az 1 argüman ister, 0
  verilmiş`). İkisi de `tools/build-plugin.sh` içinde koşuyor, yani paket
  ancak bunlar geçerse çıkıyor.
- Kotlin: sınıf yolu olmadan derleyici taraması (üç bilinen artefakt), alt
  paket import taraması, Activity metot çakışması taraması, XML.
- Yeni model alanları (`isManga`, `isChapter`, `numberLabel`, `pageCount`)
  **ayrıca izole olarak derlendi** — tam ağaç taramasında bunlar sınıf yolu
  gürültüsüne karışıyordu, o yüzden `Models.kt` tek başına, çağrı şekilleriyle
  birlikte derlenip tip kontrolünden geçirildi.

**Doğrulanamadı** (WordPress ve gerçek veri olmadan):

- `dbDelta`'nın `number` sütununu `smallint`'ten `decimal`'e gerçekten
  çevirmesi.
- Köprünün senin sitendeki gerçek `chapter_image_list` verisiyle davranışı.
- Kopyalamanın gerçek B2 hesabına yazması.
- Galeri kaynağının şu anki API şeması (servis şemasını değiştirebiliyor;
  mapper iki şekli de kabul edecek şekilde yazıldı). Uçlar senin kendi
  `class-api-nhentai.php` dosyandan alındı — çalıştığını bildiğimiz tek
  referans o.

İlk gerçek çalıştırma sende. Bir şey patlarsa hatayı bana ilet.
