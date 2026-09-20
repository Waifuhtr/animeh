# 11. GitHub Actions derlemesi ve uygulama tanıtım sayfası

İki parça, tek amaca hizmet ediyor: **uygulamanın dışarıdan bakan birine
kanıtlanabilir olması.** Reklam ağının doğrulama ekibi uygulamayı indirip
bakacak; indireceği bir adres ve okuyacağı bir sayfa olmadan başvuru
ilerlemiyor.

---

## 1. Neden HuggingFace Space yetmiyordu

Şimdiye kadar APK, HF Space'teki "Derle" düğmesiyle üretiliyordu. Bu geliştirme
için yeterli ama doğrulama için değil:

- Space'in çıktısı kalıcı bir genel adres değil.
- Üçüncü bir tarafa "şu düğmeye bas" denemiyor.
- Derlemenin hangi commit'ten çıktığı dışarıdan görünmüyor.

GitHub Actions üçünü de çözüyor: derleme deponun kendi kaynağından çıkıyor,
çıktı commit'e bağlanabiliyor, ve Release varlığı **girişsiz** indiriliyor.

---

## 2. `.github/workflows/android.yml`

Üç iş, sırayla bağlı:

| İş | Ne yapıyor |
| --- | --- |
| `kontroller` | `php -l`, üç test koşucusu, altı statik denetim, eklenti zip'i |
| `android` | Debug APK derler, `animeh.apk` adıyla yapıt olarak yükler |
| `yayin` | APK'yı sabit etiketli bir Release'e yazar — genel indirme adresi |

### Neden ayrıca Release

İş akışı **yapıtları** (`upload-artifact`) indirmek GitHub girişi ister ve
yapıtlar süresi dolunca silinir. Doğrulama ekibinin hesabı yok; siteden gelen
bir ziyaretçinin de yok. Release varlıkları girişsiz indiriliyor, o yüzden
genel indirme oradan veriliyor:

```
https://github.com/Waifuhtr/animeh/releases/download/latest/animeh.apk
```

**Etiket sabit.** Sürümden sürüme değişmiyor, çünkü bu adres bir kez bir forma
yazılıp unutuluyor. Her derlemede varlık aynı yere `--clobber` ile yazılıyor —
silip yeniden oluşturmak yerine, çünkü silme ile oluşturma arasındaki
saniyelerde adres 404 verirdi.

Release notlarına her derlemede commit, dal, SHA-256 ve tarih yazılıyor; yani
indirilen dosya, çıktığı kaynağa bakılarak doğrulanabiliyor.

### `yayin` ne zaman koşar

Yalnızca **varsayılan dalda** ya da **elle tetiklendiğinde**
(`workflow_dispatch`). Her dalın her itişinin genel indirmeyi değiştirmesi
istenmiyor. `kontroller` ve `android` her dalda koşuyor.

### Debug, release değil

`release` varyantı bu depoda kasıtlı olarak bulunmayan bir keystore istiyor.
`debug` ismine rağmen debuggable değil — `build.gradle.kts` içinde
kapatılmış, yani ART metotları derleyebiliyor ve Compose'un baseline
profilleri okunuyor. Telefona kurulan yapı zaten bu.

Bunun görünür bir sonucu var ve tanıtım sayfasında olduğu gibi yazılıyor:
paket adı `com.animeh.app.debug`, çünkü `applicationIdSuffix` öyle diyor.

---

## 3. Tanıtım sayfası — `/app`

`src/Rest/AppPage.php`. `RoomLinkPage` ile aynı deseni izliyor: rewrite
kuralı, sorgu değişkeni, `template_redirect` üstünde render.

### İngilizce, ve çevrilmiyor

Eklentideki her dize `__()` içinden geçiyor ve site Türkçe. Bu sayfa
çevrilmiyor — kasıtlı. Sayfayı okuyacak kişi Türkçe bilmiyor; sitenin dilinde
render edilen bir sayfa, var olduğu tek okuyucu için işe yaramaz olurdu.

### Ne yazıyor, ne yazmıyor

Yazdığı: uygulamanın ne olduğu, hangi arka uca bağlandığı, nereden
indirileceği, paket adı, en düşük Android sürümü, içerik ve yaş beyanı,
toplanan veri, ve **planlanan** reklam yerleşimi.

Yazmadığı: kurulum sayısı, aktif kullanıcı, gösterim — ölçülmemiş hiçbir sayı.
Bir sayfayı doldurmak için uydurulan rakam, sonradan şikâyete dönüşür.

Reklam bölümü açıkça **plan** olarak işaretli: bu satırların yazıldığı anda
bağlantıdaki yapıda reklam kodu yok. Henüz yapılmamış bir şeyi yapılmış gibi
yazmak, doğrulama ekibinin ilk kontrolünde düşen türden bir yalandır.

### Kapalı doğuyor — ve bunu ilk sürüm yanlış yapıyordu

Ayar açılana kadar adres 404 veriyor. Hazır olmayan bir sayfanın
bulunabilmesindense bulunamaması yeğ.

0.4.7 bunu söylüyordu ama yapmıyordu. Rewrite kuralı, ayar kapalıyken de
kuruluyordu; `/app` eşleşiyor, işleyici "yayında değil" deyip dönüyor, ve
WordPress elinde **hiçbir yazıyı adlandırmayan bir sorgu** kalıyordu. WordPress
o sorgu için ana sayfayı çiziyor. Yani 404 yerine **200 ve sitenin ana
sayfası** — ki bu, sayfanın yok olduğunu değil bozuk olduğunu düşündürüyor.

0.4.8'de üç yerde düzeltildi:

1. **Kural yalnızca sayfa yayındayken kuruluyor.** Eşleşip sonra çizmeyi
   reddeden bir kural, hiç olmayan bir kuraldan kötüdür.
2. **Reddetmek artık gerçek bir 404.** Bir kural ayarın ardından sağ kalırsa
   (önbellek, ya da yapılmamış bir yenileme) `set_404()` çağrılıp tema kendi
   404'ünü çiziyor.
3. **Kaydetmek anında etki ediyor.** Kural kümesi `init` sırasında, kaydetmeden
   **önceki** ayara göre kuruluyor; kuralı eklemeden yenilemek onu taşımayan
   bir küme yazardı ve sayfa bir sonraki yüklemeye kadar 404 vermeye devam
   ederdi.

### Yönetim ekranı artık canlı durumu söylüyor

Ayarın açık olması ile adresin çalışması aynı şey değil: düz bağlantı kullanan
bir sitede rewrite kuralı hiç çalışmaz, ve bir kural yedekten dönüşte ya da bir
önbellek eklentisinden sonra kaybolabilir. Ekran ikisini ayrı ayrı gösteriyor
ve uyuşmadıklarında ne yapılacağını yazıyor — yönlendirmesiz de çalışan
`?animeh_app_page=1` adresi dahil.

### Ayarlar — Animeh → Entegrasyonlar

| Alan | Boşken ne olur |
| --- | --- |
| Yayın | Kapalı; `/app` 404 |
| Yayıncı adı | Sayfada hiç yazılmıyor |
| İletişim e-postası | İletişim bölümü hiç çizilmiyor |
| İndirme adresi | Yukarıdaki sabit Release adresine düşer |
| Kaynak kod adresi | Depo adresine düşer |

İkisi de uydurulmuyor. Bilinmeyen bir şey, yerine bir şey konularak değil,
yazılmayarak gösteriliyor.

### İki savunma

**Adres şeması.** İndirme ve kaynak adresleri operatörün düzenlediği alanlar ve
genel bir sayfada `href` oluyorlar. Yalnızca `http` ve `https` kabul ediliyor;
`javascript:` bir yapıştırma, saklanmış XSS olurdu. Kabul edilmeyen bir değer
hata vermiyor, yerleşik adrese düşüyor — yani sayfada çalışan bir indirme
düğmesi her hâlükârda kalıyor.

**Sessizce değişen e-posta.** `sanitize_email()` reddetmiyor, **düzenliyor**:
adreste bulunamayacak her karakteri siliyor ve kalanı geri veriyor. Yani
`javascript:a@b.com` geriye `javascripta@b.com` olarak dönüyor — kusursuz
biçimli, kaydedilmiş, ve kimsenin okumadığı bir adres. Güvenlik açığı değil
(sonuç iki durumda da zararsız bir `mailto:`) ama reddetmekten **daha kötü**
bir başarısızlık: operatör kaydedilmiş bir alan görüyor, doğrulama ekibi bir
iletişim satırı görüyor, posta hiçbir yere gitmiyor.

Bu yüzden sanitize edicinin **değiştirmek zorunda kaldığı** her şey, adres
sayılmıyor ve yönetim ekranında söyleniyor.

---

## 4. Burada doğrulanan / doğrulanamayan

**Doğrulandı:**

- `php -l` tüm eklentide temiz; üç koşucu 231 + 11 + 148 geçiyor.
- **Adres şeması** — `javascript:`, `ftp:`, `data:` ve boş değer yerleşik
  adrese düşüyor, geçerli `https` tutuluyor. Şema kontrolünü kaldırarak
  düşürülerek doğrulandı.
- **İletişim adresi** — `javascript:a@b.com`, `bozuk`, `@yok.com`, `a@b`,
  `a b@c.com` kaydedilmiyor; geçerli adres tutuluyor. Eşitlik kontrolünü
  kaldırarak düşürülerek doğrulandı.
- **Kapalı doğuyor** — ayar yokken `enabled` false, ve **kapalıyken hiçbir
  rewrite kuralı kurulmuyor**. İkincisi 0.4.7'nin gerçek hatasıydı: kuralı
  koşulsuz kurmaya geri dönerek düşürülerek doğrulandı.
- **Kaydetmek anında etki ediyor** — kaydettikten sonra saklanan kural kümesi
  kuralı taşıyor. `add_rule()` çağrısını yenilemeden önce kaldırarak
  düşürülerek doğrulandı.
- Duman koşucusunun taklidi artık `add_rewrite_rule` ve `flush_rewrite_rules`
  sağlıyor; ikincisi gerçeğinin yaptığı gibi **o an kayıtlı olanı** yazıyor,
  ki yukarıdaki sıralama hatası ancak böyle görülebiliyor.
- Duman koşucusunun WordPress taklidi artık `is_email` ve `sanitize_email`
  sağlıyor; ikisi de gerçeğinin davranışını taşıyor — `sanitize_email`'in
  reddetmek yerine düzenlemesi dahil, ki yukarıdaki bulgu buradan çıktı.
- Altı statik denetimin hepsi temiz.

**Doğrulanamadı — ilk gerçek çalıştırma sende olacak:**

- **İş akışının kendisi.** YAML ayrıştırılıyor ve her adımın komutu burada tek
  tek koşturuldu, ama GitHub Actions bu ortamdan tetiklenemiyor. İlk koşu
  dalına itince olacak.
- **Rewrite kuralı.** WordPress kurulamadığı için `/app` adresinin gerçekten
  eşleştiği burada görülemedi. `RoomLinkPage` ve `AppLinks` aynı deseni
  kullanıyor ve ikisi de sende çalışıyor.
- **Sayfanın görünümü.** HTML ve CSS elle yazıldı, tarayıcıda açılmadı.

---

## 5. Reklamlar

Bölüm oynatıcısının içinde, belirli aralıklarla VAST video reklamı. Yalnızca
orada — manga okuyucusunda ve AnimehTok akışında yok.

### Ayrıştırıcı neden kendi kodumuz

Google'ın IMA eklentisi yerine kendi VAST istemcimiz yazıldı, üç gerekçeyle:

1. **Aralık modeli tutmuyor.** IMA, reklam sunucusunun VMAP ile bildirdiği cue
   noktaları etrafında kurulu. Bizim elimizde tek bir VAST adresi var ve
   "her dört dakikada bir, bölüm bitene kadar" isteniyor.
2. **Politika.** IMA Google'ın SDK'sı ve Google'ın reklam politikalarına tabi;
   bu uygulamada yetişkin içerik var.
3. **Dağıtım.** Uygulama Play'de değil, yandan kuruluyor; IMA'nın Play
   Services varsayımları zayıf.

### Ve bu kararın karşılığını hemen verdiği yer

Reklam ağının **gerçek yanıtı**, spesifikasyondan yazılmış bir ayrıştırıcının
hiç bakmadığı bir yazım kullanıyor:

| VAST'ın bilinen olay adları | Yanıtta var mı |
| --- | --- |
| `start`, `firstQuartile`, `midpoint`, `thirdQuartile`, `complete` | **hiçbiri yok** |
| mutlak `offset` taşıyan `progress` olayları | **beş tane** |

İkisi de geçerli VAST 3.0. Ama diğer yazımı arayan bir kod bu yanıtta
**hiçbir izleme pikseli bulamaz**: reklamı oynatır, hiçbir şey raporlar,
hiçbir şey kazandırır ve çalışıyormuş gibi görünür.

Görülebilmesinin tek sebebi, ayrıştırıcının **Android'siz** yazılmış olması.
`javax.xml.parsers` hem platformda hem masaüstü JVM'de var, yani `Vast.kt`
emülatörsüz koşuyor ve gerçek yanıta karşı denendi.

Aynı dosyadan çıkan, hepsi teste giren diğer tuzaklar:

- Her URL `<![CDATA[ … ]]>` içinde **iki yanında boşlukla** geliyor. Başında
  boşluk olan bir adres, hiç gitmeyen bir istektir.
- Offsetler **sırasız** geliyor (10, 6, 13, 20, 28 saniye).
- Süre milisaniyeli: `00:00:29.525`.
- **Tek** medya dosyası, `width`/`height`/`bitrate` yok — seçim bunların
  varlığına yaslanamıyor.
- `skipoffset` **yok**, yani atlama süresini operatörün ayarı belirliyor.
- Ağın kendi çağrı düğmesi (`TitleCTA`) `<Extensions>` içinde.

İki yazım da aynı listeye düşüyor; oynatıcı hangisini aldığını bilmiyor.

### Zamanlama

`AdSchedule` — saf aritmetik, ayrı dosyada, çünkü yanlış olması en kolay ve en
pahalı kısım orası.

Kırılımlar **sabit konumlarda**, son reklamdan sayılarak değil: sayma kayar,
yüklenmesi otuz saniye süren bir reklam sonrakini otuz saniye geciktirir ve
beşinci kırılımda operatörün kurduğu düzen artık kimsenin üstünde olduğu düzen
değildir.

| Durum | Davranış | Neden |
| --- | --- | --- |
| Aynı anın içinde kalmak | Bir daha çıkmaz | İndeksle karşılaştırılıyor, zamanla değil |
| Geri sarmak | Gösterilmiş reklam tekrar çıkmaz | Aynı sebep |
| On dakika ileri atlamak | **Bir** reklam | İndeks tek adımda ilerliyor; dördünü arka arkaya göstermek uygulamayı sildirir |
| Jenerik (son 30 sn) | Çıkmaz | İzleyici zaten sonraki bölüme uzanmış; gösterim kimseye harcanır |
| Süre henüz bilinmiyor | Pre-roll dışında çıkmaz | Sıfır uzunluğa göre konan kırılım, tahmine göre konmuş demektir |

### Birlikte izlemede reklam yok

Kırılım bu izleyiciyi duraklatır, o duraklatma odaya yayınlanır ve
**herkesin bölümü** görmedikleri bir reklam için durur. Eşzamanlı oynatma ile
tek kişinin aldığı bir kesinti bağdaşmıyor; oda kazanıyor.

### İki oynatıcı, tek yüzey

Reklam kendi `ExoPlayer` örneğinde oynuyor. Bölümünkinde bir konum, bir altyazı
izi, bir font kümesi ve bir kalite seçimi var; hepsinin kesintiden sağ çıkması
gerekiyor. Ona başka bir dosya vermek, izleyicinin reklamdan bölümün başına ve
altyazısız dönmesinin yoludur.

### Her şey bölüme doğru düşüyor

Cevap vermeyen ağ, ayrıştırılamayan yanıt, çözülemeyen creative — her biri
kırılımı anında ve sessizce bitiriyor, bölüm kaldığı yerden sürüyor. İzleyici
bölüm için geldi; bir reklam hakkında hata mesajı, reklamın hiç çıkmamasından
kötüdür.

### Ne raporlanıyor

| Olay | Ne zaman |
| --- | --- |
| `Impression` | İlk kare göründüğünde, bir kez |
| `progress` × 5 | Konum her offset'i geçtiğinde, sıradan drenaj |
| `complete` | Reklam sonuna vardığında |
| `skip` | İzleyici geçtiğinde |
| `ClickTracking` + CTA | Dokunulduğunda, hedef açılmadan |
| `Error` (`[ERRORCODE]` yerine konarak) | Creative çözülemediğinde |

İzleme çağrıları **ateşle-ve-unut** ve **yeniden denenmiyor**: 500 dönen bir
izleme ucu da saymıştır, ve yeniden deneme bir gösterimi başkasının
faturasında ikiye çıkarma yoludur.

### Ayarlar — Animeh → Entegrasyonlar → Reklamlar

Sunucuda duruyor, uygulamada değil. Uygulamanın içindeki bir anahtar yalnızca
tek bir telefonu etkilerdi; buradaki değişiklik, uygulamalar açılışta ayarları
sorduğu için herkese ulaşıyor — **kapatmak da dahil.** Kapalıyken adres
uygulamaya hiç gönderilmiyor, yani hiçbir istek yapılmıyor.

### Client Hints meta etiketi neden yok

Reklam ağının verdiği `<meta http-equiv="Delegate-CH" …>` etiketi bir **web
sayfası** içindir. Oynatıcı yerel Android; ortada `<head>` yok. `/app`
sayfasına koymak da yanlış olurdu: orada hiç reklam gösterilmiyor, etiket
yalnızca ziyaretçinin cihaz bilgisini durduk yere reklam sunucusuna
gönderirdi.

### Burada doğrulanan / doğrulanamayan

**Doğrulandı:**

- **VAST ayrıştırıcısı gerçek yanıta karşı koştu.** `tools/checks/kotlin-unit-tests.sh`
  Gradle'ın kendi derleyicisi ve JUnit'iyle framework'süz katmanı derleyip
  çalıştırıyor. 21 test: 12 ayrıştırıcı, 9 zamanlama.
- **Zamanlama**, yukarıdaki tablonun her satırı için ayrı kontrol.
- Kotlin fark taramasında bu değişiklikle gelen 32 hatanın tamamı tek tek
  incelendi ve hepsi androidx/okhttp/dagger'ın bu ortamda görünmemesine
  bağlandı; kullanılan her API'nin projede zaten çalışan bir kullanımı
  gösterildi (`onPlaybackStateChanged`, `onPlayerError`, `errorCodeName`,
  `Player.STATE_ENDED`, `execute().use`, `isSuccessful`). `MediaItem.fromUri`
  gerçek bir API ama burada kanıtlanmamıştı; projenin kullandığı
  `MediaItem.Builder().setUri()` deyimine çevrildi.

**Doğrulanamadı — ilk gerçek çalıştırma sende olacak:**

- **Reklamın telefonda oynaması.** Ne emülatör var ne de `s.magsrv.com`'a
  erişim (bu ortamın çıkış politikasında engelli). Ağ çağrısı, iki oynatıcı
  arasındaki yüzey geçişi ve arayüz burada koşulmadı.
- **Doluluk.** Zone yeni; ağın gerçekten reklam döndüreceği burada denenemez.
  Boş yanıt bir hata değil, normal bir cevap olarak ele alınıyor.
- **User-Agent.** İstek, tarayıcı taklidi yapmayan dürüst bir istemciyle
  gidiyor. Doluluk düşük çıkarsa bakılacak ilk yer burasıdır — ama trafiği
  yanlış tanıtmak ağla aranı bozacak türden bir çözümdür, o yüzden sessizce
  yapılmadı.
