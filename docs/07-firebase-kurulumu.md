# Firebase kurulumu

Birlikte izleme odalarının anlık verisi ve bildirimler Firebase üzerinden
geçiyor. Bu dosya senin yapman gereken adımları anlatıyor; uygulama ve eklenti
tarafındaki her şey hazır.

**Neden Firebase?** Bir odada oynatma konumu saniyede birkaç kez değişiyor.
Bunu WordPress'e yazdırmak paylaşımlı bir hostingi dakikalar içinde devirir.
Bu yüzden iş bölümü şöyle: **WordPress** kimin oda açabileceğine, kimin
katılabileceğine ve kimin davet edilebileceğine karar veriyor — bunlar
istemcinin yalan söyleyemeyeceği yerde durması gereken kararlar. **Firebase**
ise oynatma konumunu, sohbeti ve odada kimin bulunduğunu taşıyor.

**google-services.json gerekmiyor.** Yapılandırma sunucudan geliyor, yani
Firebase projesini değiştirdiğinde yeni APK derlemene gerek yok.

---

## 1. Proje ve uygulama

1. <https://console.firebase.google.com> → **Proje ekle**. Adı ne olursa olsun.
   Google Analytics'e ihtiyacın yok, kapatabilirsin.
2. Proje açılınca **Android simgesine** tıkla.
   - Paket adı: `com.animeh.app` — **birebir bu olmalı.**
   - Takma ad ve SHA-1 boş kalabilir.
3. "google-services.json dosyasını indir" adımını **atla**. Gerekmiyor.
4. Sol üstteki dişli → **Proje ayarları** → **Genel** sekmesi. Aşağıya inip
   "Uygulamalarınız" bölümünde Android uygulamasını seç. Şu dört değeri
   göreceksin:

   | Firebase konsolunda | Eklentideki alan |
   | --- | --- |
   | Proje kimliği | Proje kimliği |
   | Web API anahtarı | Web API anahtarı |
   | Uygulama kimliği (`1:...:android:...`) | Uygulama kimliği |
   | Proje numarası | Gönderen kimliği |

---

## 2. Realtime Database

**Firestore değil, Realtime Database.** İkisi ayrı ürün; oda senkronizasyonu
için doğru olanı Realtime Database.

1. Sol menü → **Derleme** → **Realtime Database** → **Veritabanı oluştur**.
2. Bölge: **europe-west1** (Türkiye'ye en yakın olanı).
3. "Kilitli modda başlat" seç. Kuralları birazdan biz yazacağız.
4. Oluşunca üstteki adresi kopyala. Şuna benzer:
   `https://proje-adin-default-rtdb.europe-west1.firebasedatabase.app`

Bu adres eklentideki **Realtime Database adresi** alanına gidiyor.

### Güvenlik kuralları

**Kurallar** sekmesine geç ve içindekini tamamen aşağıdakiyle değiştir:

```json
{
  "rules": {
    "rooms": {
      "$code": {
        ".read": "auth == null",
        ".write": "auth == null",

        "state": {
          ".validate": "newData.hasChildren(['positionMs', 'playing', 'by'])",
          "positionMs": { ".validate": "newData.isNumber() && newData.val() >= 0" },
          "playing":    { ".validate": "newData.isBoolean()" },
          "by":         { ".validate": "newData.isString() && newData.val().length <= 32" },
          "at":         { ".validate": "newData.isNumber()" },
          "episodeId":  { ".validate": "newData.isNumber()" },
          "$other":     { ".validate": false }
        },

        "members": {
          "$uid": {
            ".validate": "newData.hasChildren(['uid', 'name'])",
            "uid":    { ".validate": "newData.isString() && newData.val().length <= 32" },
            "name":   { ".validate": "newData.isString() && newData.val().length <= 64" },
            "avatar": { ".validate": "newData.isString() && newData.val().length <= 512" },
            "at":     { ".validate": "newData.isNumber()" },
            "$other": { ".validate": false }
          }
        },

        "chat": {
          "$message": {
            ".validate": "newData.hasChildren(['uid', 'name', 'text'])",
            "uid":    { ".validate": "newData.isString() && newData.val().length <= 32" },
            "name":   { ".validate": "newData.isString() && newData.val().length <= 64" },
            "avatar": { ".validate": "newData.isString() && newData.val().length <= 512" },
            "text":   { ".validate": "newData.isString() && newData.val().length <= 500" },
            "at":     { ".validate": "newData.isNumber()" },
            "$other": { ".validate": false }
          }
        },

        "$other": { ".validate": false }
      }
    },

    "$other": { ".read": false, ".write": false }
  }
}
```

**Bu kuralların ne yaptığını ve ne yapmadığını açıkça söyleyeyim.**

Yaptıkları: `rooms` dışındaki her yolu tamamen kapatıyor, her alanın tipini ve
uzunluğunu doğruluyor, tanımadığı alanların yazılmasını reddediyor. Yani
kimse bu veritabanını kendi verisi için depo olarak kullanamaz, sohbet mesajı
500 karakteri geçemez, oynatma konumu negatif olamaz.

**Yapmadıkları:** oda kodunu bilen birinin o odaya girmesini engellemiyor.
Uygulama Firebase'e ayrı bir kimlikle bağlanmıyor (`auth == null`), çünkü
kullanıcılar WordPress'te kayıtlı, Firebase'de değil. Odayı koruyan şey kodun
kendisi: 32 harflik alfabeden 10 karakter, yaklaşık 60 bit — tahmin edilmeye
değmez. Ama **oda bağlantısını paylaştığın herkes odaya girebilir**, tıpkı
paylaşılan bir Google Docs bağlantısı gibi. Bunu bilerek paylaş.

İleride bunu sıkılaştırmak istersen yol şu: WordPress'in oda açarken bir
Firebase custom token üretmesi ve uygulamanın onunla `signInWithCustomToken`
yapması. O zaman kurallar `auth.uid`'e bakabilir. Şu an gerekli değil, ama
mimari buna kapalı değil — söylersen eklerim.

---

## 3. Bildirimler (servis hesabı)

Davetlerin uygulama kapalıyken de bildirim çubuğuna düşmesi için gereken kısım.
Bunu atlarsan odalar yine çalışır, sadece davet bildirimi gitmez.

1. **Proje ayarları** → **Servis hesapları** sekmesi.
2. **Yeni özel anahtar oluştur** → **Anahtar oluştur**. Bir `.json` dosyası iner.
3. Dosyayı bir metin düzenleyicide aç, **içeriğinin tamamını** kopyala.
4. Eklentideki **Servis hesabı JSON** kutusuna yapıştır.

> Bu dosya, projenin adına herkese bildirim gönderebilen özel bir anahtar
> içeriyor. Eklenti onu veritabanına şifreleyerek yazıyor ve hiçbir zaman
> uygulamaya göndermiyor. Yine de dosyayı indirdiğin bilgisayarda bırakma,
> bir yere yükleme, kimseye gönderme.

Google 2024'te eski "server key" yöntemini kapattı; eklenti güncel olan
**FCM HTTP v1** akışını kullanıyor — servis hesabıyla imzalanmış bir JWT'yi
erişim jetonuyla takas ediyor. Bu imzalama kodu birim testleriyle doğrulanıyor.

---

## 4. Eklentiye girilecekler

WordPress → **Animeh** → **Entegrasyonlar** → *Firebase* bölümü:

| Alan | Nereden |
| --- | --- |
| Realtime Database adresi | Adım 2'de kopyaladığın adres |
| Proje kimliği | Proje ayarları → Genel |
| Web API anahtarı | Proje ayarları → Genel |
| Uygulama kimliği | Proje ayarları → Genel → Android uygulaması |
| Gönderen kimliği | Proje ayarları → Genel → Proje numarası |
| Servis hesabı JSON | Adım 3 (isteğe bağlı) |

"Birlikte izleme açık" kutusunu işaretle ve kaydet. Sayfa kaydettikten sonra
"Bildirimler gönderilebilir durumda" yazıyorsa servis hesabı da doğru okunmuş
demektir.

---

## 5. Davet bağlantıları

Oda bağlantısı `https://siten.com/oda/kodu` şeklinde.

Kalıcı bağlantı ayarların "Düz" ise WordPress'in **Ayarlar → Kalıcı
bağlantılar** sayfasına bir kez girip kaydet; rewrite kuralının işlemesi için
gerekiyor.

Bir odaya üç ayrı yoldan girilebiliyor, çünkü hiçbiri tek başına güvenilir
değil:

1. **Doğrudan uygulama** — bağlantı tarayıcıya hiç uğramadan uygulamada
   açılır. Bunun için aşağıdaki parmak izi adımı gerekiyor.
2. **Devir sayfası** — parmak izi girilmemişse bağlantı eklentinin küçük bir
   sayfasını açar; sayfa uygulamayı kendiliğinden çağırmayı dener, olmazsa
   **Uygulamada aç** düğmesini gösterir.
3. **Oda kodu** — uygulamadaki **Odalar → Odaya katıl** kutusuna kod yazılır.
   Kod hem devir sayfasında hem de odanın kendi ekranında yazıyor. Bu yol her
   koşulda çalışır.

### Bağlantının doğrudan uygulamada açılması

Android 12'den beri, bir bağlantının o uygulamaya ait olduğu **kanıtlanmadıkça**
sistem onu sessizce tarayıcıya gönderiyor — eskisi gibi "hangi uygulamayla
açılsın" diye sormuyor. Bu yüzden davet bağlantısı tarayıcıda açılıyordu.

Kanıt, APK'yı imzalayan sertifikanın SHA-256 parmak izi. Şöyle alınır:

```
keytool -printcert -jarfile animeh.apk
```

Çıktıdaki **SHA-256** satırını olduğu gibi kopyala ve WordPress →
**Animeh** → **Entegrasyonlar** → *Uygulama bağlantıları* kutusuna yapıştır.
Eklenti bunu `https://siten.com/.well-known/assetlinks.json` adresinden
yayınlar; Android uygulama kurulduğunda o dosyayı okuyup bağlantıyı doğrudan
uygulamaya verir.

Notlar:

- Parmak izi gizli bir şey değil — bir **açık** sertifikanın özeti, ve Google
  zaten herkese açık bir adreste yayınlamanı istiyor.
- İki nokta üst üsteli (`AB:CD:…`) ya da düz (`abcd…`) yazılmış olması fark
  etmiyor, ikisi de kabul ediliyor. Yanlışlıkla **SHA-1** satırını
  yapıştırırsan eklenti bunu söyler.
- APK'yı her yeniden imzaladığında parmak izi değişmez — aynı anahtar deposunu
  kullandığın sürece bir kez girmen yeterli.
- Doğrulama, uygulama **kurulduğunda** yapılıyor. Parmak izini APK'yı
  kurduktan sonra girdiysen uygulamayı bir kez kaldırıp yeniden kur.

---

## 5b. Telefonda bildirim izni

Android 13 ve sonrasında bildirim ayrı bir izin. Uygulama bunu giriş
yaptıktan sonra bir kez soruyor; **İzin ver** demezsen davetler gönderilir ama
bildirim çubuğunda görünmez. Yanlışlıkla reddettiysen: telefonun
**Ayarlar → Uygulamalar → Animeh → Bildirimler** ekranından açabilirsin.

Bir davetin ulaşıp ulaşmadığı artık uygulamada da yazıyor: davet ettiğin kişi
sayısının yanında kaç kişiye bildirim gittiğini söylüyor. Hiç gitmediyse
sunucuda servis hesabı yok demektir (Adım 3). Sunucu tarafında da
**Animeh → Günlükler** ekranında `FCM_ERROR` satırı sebebini yazıyor.

---

## 6. Ücret

Bu kullanım Firebase'in ücretsiz (Spark) planının içinde kalır: Realtime
Database'de 1 GB depolama ve aylık 10 GB indirme, FCM'de sınırsız bildirim.
Odalar boşalınca kendini sildiği için veritabanında kalıcı bir birikim yok —
aynı anda açık oda sayısı kadar veri tutuluyor, o kadar.
