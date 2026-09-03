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

Oda bağlantısı `https://siten.com/oda/kodu` şeklinde. Bu adres eklentinin
sunduğu küçük bir sayfa ve tek işi telefonu uygulamaya devretmek.

Kalıcı bağlantı ayarların "Düz" ise WordPress'in **Ayarlar → Kalıcı
bağlantılar** sayfasına bir kez girip kaydet; rewrite kuralının işlemesi için
gerekiyor.

Bağlantıya dokunulduğunda Android bir "hangi uygulamayla açılsın" seçimi
gösterebilir. Bunu kaldırmak (doğrulanmış App Links) mümkün ama uygulamanın
**yayın imzasının SHA-256 parmak izini** sitenin `.well-known/assetlinks.json`
dosyasına koymayı gerektiriyor — imzalamayı sen yaptığın için o parmak izi
bende yok. İmzalı bir APK üretmeye karar verirsen parmak izini gönder, dosyayı
ve manifest tarafını ayarlarım.

---

## 6. Ücret

Bu kullanım Firebase'in ücretsiz (Spark) planının içinde kalır: Realtime
Database'de 1 GB depolama ve aylık 10 GB indirme, FCM'de sınırsız bildirim.
Odalar boşalınca kendini sildiği için veritabanında kalıcı bir birikim yok —
aynı anda açık oda sayısı kadar veri tutuluyor, o kadar.
