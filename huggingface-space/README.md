---
title: Animeh APK Builder
emoji: 🎬
colorFrom: purple
colorTo: indigo
sdk: docker
app_port: 7860
pinned: false
---

# Animeh APK Builder

Animeh Android uygulamasını derleyip APK üreten bir Hugging Face Docker Space.

Buradaki tek iş: `./gradlew assembleDebug` çalıştırmak ve ne olduğunu
göstermek. Süslü bir şey yok; olması gereken tek şey logun okunabilir olması.

## Kullanım

1. **Derle**'ye bas.
2. Log akmaya başlar. İlk derleme uzun sürer (5–15 dk) — Gradle bağımlılıkları
   indiriyor. Sonrakiler daha hızlı.
3. Bitince **APK indir** düğmesi çıkar.

**Varyant:**

- `debug` — imzasız, doğrudan telefona kurulabilir. Test için bunu kullan.
- `release` — küçültülmüş ve optimize edilmiş, ama **keystore ister**.
  Keystore yoksa imzasız çıkar ve telefona kurulmaz.

**WordPress adresi** alanı APK'nın varsayılan sunucusunu belirliyor. Boş
bırakılabilir — kullanıcı zaten uygulama içinden Ayarlar → Sunucu'dan
değiştirebiliyor.

## İçerik

```
.
├── Dockerfile          JDK 17 + Android SDK 35 + Gradle
├── app.py              derleme sunucusu (FastAPI)
├── requirements.txt
├── static/             arayüz (HTML + CSS + JS)
│   ├── index.html
│   ├── style.css
│   └── app.js
└── android/            ← uygulama kaynağı
    ├── gradlew
    ├── settings.gradle.kts
    ├── build.gradle.kts
    ├── gradle/
    │   ├── libs.versions.toml
    │   └── wrapper/
    └── app/
        ├── build.gradle.kts
        ├── proguard-rules.pro
        └── src/main/…
```

**Yollar önemli.** `app.py` kaynağı `./android/` altında arıyor. Klasör
yapısını bozarsan Ortam panelinde "Proje: BULUNAMADI" yazar ve derleme
başlamadan durur.

## Space ayarları

| | |
| --- | --- |
| SDK | Docker |
| Port | 7860 |
| Donanım | CPU basic (ücretsiz) yeterli |
| Disk | Android SDK + Gradle cache ≈ 6–8 GB |

İlk **image** derlemesi de uzun sürer (10–20 dk): Android SDK indiriliyor ve
bağımlılıklar ısıtılıyor. Bu bir kez oluyor; Space yeniden başladığında image
hazır.

## Release imzalama (isteğe bağlı)

Keystore'unu Space'e yükle ve **Settings → Variables and secrets** altına ekle:

```
ANIMEH_KEYSTORE=/app/android/release.jks
ANIMEH_KEYSTORE_PASSWORD=…
ANIMEH_KEY_ALIAS=…
ANIMEH_KEY_PASSWORD=…
```

Şifreleri **secret** olarak ekle, variable olarak değil — variable'lar Space
sayfasında görünür.

Keystore yoksa `release` yapılandırması hiç oluşturulmuyor; `debug` derlemesi
etkilenmiyor.

## Sorun giderme

**"Proje: BULUNAMADI"** — `android/` klasörü yüklenmemiş ya da yanlış yerde.
`android/settings.gradle.kts` var olmalı.

**"Permission denied: ./gradlew"** — Dockerfile `chmod +x` yapıyor, ama Space'e
yüklerken bit kaybolduysa `app.py` yedek olarak `gradle` kullanıyor. Yine de
düzeltmek için dosyayı yeniden yükle.

**Derleme "Could not resolve …" ile düşüyor** — Space'in ağı kesilmiş olabilir;
tekrar dene. Sürekli oluyorsa `gradle/libs.versions.toml` içindeki sürüm o
depoda gerçekten yok demektir.

**"Zaten bir derleme sürüyor"** — aynı anda tek derleme çalışıyor. İptal et ya
da bitmesini bekle.

**Bellek hatası (`OutOfMemoryError`, `Daemon disappeared`)** — ücretsiz
donanımda Compose derlemesi sınıra yakın. `android/gradle.properties` içindeki
`org.gradle.jvmargs` değerini `-Xmx3072m` yapıp tekrar dene, ya da Space'i daha
büyük donanıma al.

**15 dakika çıktı gelmezse** derleme otomatik durduruluyor — takılan bir
derlemenin Space'i süresiz tutmaması için.
