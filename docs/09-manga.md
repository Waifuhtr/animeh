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

Yeni sürümü kur. Şema 9'a çıkıyor; sütunlar ve tablolar kendiliğinden
oluşuyor.

### b) Köprüyü manga sitesine kur

`animeh-manga-bridge-1.0.0.zip` → **manga sitende** Eklentiler → Yeni Ekle →
Eklenti Yükle → Etkinleştir.

Sonra manga sitende **Ayarlar → Animeh Köprüsü**. İki değer var:

- **Köprü adresi** (`https://manga-siten.com/wp-json/animeh-bridge/v1`)
- **Anahtar**

İkisini de kopyala.

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
Kapalıyken arama yapmaz.

### e) Uygulamayı derle

Space'te **Derle**. Okuyucu, manga sekmesi, "Yeni Manga Bölümleri" rayı ve
manga yönetim ekranı o derlemeyle gelir.

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
  `MangaMapper` (iki kaynağın da aynı şekle çevrilmesi, +18 bayrağı) — 195
  birim testi geçiyor.
- Her PHP dosyası `php -l`.
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
  mapper iki şekli de kabul edecek şekilde yazıldı).

İlk gerçek çalıştırma sende. Bir şey patlarsa hatayı bana ilet.
