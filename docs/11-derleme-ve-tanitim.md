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

### Kapalı doğuyor

Ayar açılana kadar adres 404 veriyor. Hazır olmayan bir sayfanın
bulunabilmesindense bulunamaması yeğ.

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
- **Kapalı doğuyor** — ayar yokken `enabled` false.
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
