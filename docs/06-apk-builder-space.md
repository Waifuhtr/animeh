# APK Builder — Hugging Face Space

Android derlemesi bu geliştirme ortamında yapılamıyor: `dl.google.com` çıkış
politikasında 403, ve `maven.google.com` oraya yönlendiriyor — yani AGP,
AndroidX, Compose, Hilt ve Media3'ün tamamı erişilemez. Brief §17 tam da bu
durum için Hugging Face Docker Space'i alternatif olarak gösteriyor.

Bu, o Space. Tek işi var: `./gradlew assembleDebug` çalıştırmak ve ne olduğunu
göstermek.

---

## Paket

```
dist/animeh-apk-builder-space.zip
```

145 dosya. İçindeki her şeyi Space'e yükle — **klasör yapısını koruyarak**.

```
.
├── Dockerfile          JDK 17 + Android SDK 35 + Gradle 8.11.1
├── app.py              derleme sunucusu (FastAPI)
├── requirements.txt
├── README.md           Space yapılandırması (YAML başlık) + kullanım
├── .dockerignore
├── static/
│   ├── index.html
│   ├── style.css
│   └── app.js
└── android/            ← uygulama kaynağı, 64 Kotlin dosyası
    ├── gradlew          (+ gradle/wrapper/)
    ├── settings.gradle.kts
    ├── build.gradle.kts
    ├── gradle.properties
    ├── gradle/libs.versions.toml
    └── app/…
```

**Yollar önemli.** `app.py` kaynağı `./android/` altında arıyor. Taşırsan
Ortam panelinde `Proje: BULUNAMADI` yazar ve derleme başlamadan durur —
sessizce yanlış bir şey derlemek yerine.

Yeniden üretmek için: `./tools/build-space.sh`.

---

## Space kurulumu

1. huggingface.co → **New Space**
2. SDK: **Docker**, şablon: **Blank**
3. Zip'in içindeki tüm dosyaları yükle (Files → Add file → Upload files)
4. Image derlemesini bekle — **ilk seferinde 10–20 dakika**, Android SDK
   indiriliyor ve bağımlılıklar ısıtılıyor. Bir kez oluyor.
5. Space açılınca **Derle**'ye bas.

Ücretsiz CPU donanımı yetiyor. Disk ihtiyacı ≈ 6–8 GB.

---

## Neden bu şekilde

**SDK image'a gömülü, çalışma anında indirilmiyor.** Bir Space konteyneri
geçici: ilk çalıştırmada indirilen her şey, her yeniden başlatmada tekrar
indirilir. Docker katmanı bir kez ödenir.

**Sürümler sabitlenmiş** (`cmdline-tools 11076708`, `android-35`,
`build-tools 35.0.0`, `gradle 8.11.1`). Bugün derlenen bir APK altı ay sonra da
aynı şekilde derlenmeli; "latest" bir derlemenin hiçbir şey değişmeden bozulma
yoludur.

**`useradd -u 1000`** — Hugging Face Space'leri uid 1000 olarak çalıştırıyor.
Derlemenin yazdığı her şeyin (proje dizini, Gradle home, SDK) sahibi o olmalı,
yoksa ilk derleme gerçek bir sorun yüzünden değil, bir izin hatası yüzünden
düşer.

**Log akıyor, sonunda dönmüyor.** Bir Android derlemesi dakikalar sürüyor ve
bitene kadar hiçbir şey göstermeyen bir sayfa, takılmış bir sayfadan ayırt
edilemez. Server-Sent Events ile satır satır geliyor.

**Aynı anda tek derleme.** İkincisi 409 alıyor, kuyruğa girmiyor: ücretsiz
donanımda paralel iki Gradle derlemesi ikisini birden düşürür.

**15 dakika çıktı gelmezse derleme durduruluyor.** Gradle sürekli ilerleme
basar; sessizlik takıldığının işaretidir, ve takılmış bir derleme Space'i
süresiz tutmamalı.

---

## Sunucu adresi

Arayüzdeki **WordPress adresi** alanı `local.properties` içine
`ANIMEH_API_BASE` yazıyor; `app/build.gradle.kts` onu okuyup APK'nın varsayılan
sunucusu yapıyor.

Değer doğrulanıyor: yalnızca düz bir `http(s)://…` kabul ediliyor. Sebep somut —
bu değer Gradle'ın okuduğu bir properties dosyasına yazılıyor, ve içindeki bir
satır sonu başka bir property enjekte ederdi.

Boş bırakılabilir. Kullanıcı zaten uygulama içinden Ayarlar → Sunucu'dan
değiştirebiliyor, ve site taşınırsa yapması gereken de bu.

---

## Doğrulama

Space burada Docker olmadan çalıştırılamıyor, ama sunucusu ve arayüzü
çalıştırılabiliyor. Gerçek Chromium'da, gerçek arayüzle, gradle yerine bir
stub ile sürüldü:

| | |
| --- | --- |
| Tarayıcı kontrolleri | **25/25** |

Kapsananlar: ortam panelinin dolması, projenin doğru yolda bulunması, derlemenin
başlaması, **derleme sürerken logun akması**, başarıda APK'nın indirilebilir
olması (1.2 MB gerçekten indi, doğru content-type ile), logun indirilebilmesi,
uyarı ve hata satırlarının renklenmesi, ikinci eşzamanlı derlemenin 409 ile
reddi, iptalin gerçekten durdurması, bilinmeyen varyantın 400 ile reddi, ve
sunucu adresindeki satır sonunun reddi.

Ayrı bir koşuda hata yolu: **başarısız derleme `failed` olarak raporlanıyor,
çıkış kodu görünüyor, derleyici hatası logda ve kırmızı.**

Bu bir gerçek hata yakaladı: SSE üreteci önce logu boşaltıp *sonra* durumu
kontrol ediyordu, yani ikisi arasında eklenen satırlar kayboluyordu — ve
tam olarak orada `BUILD SUCCESSFUL` ile APK adı var. Kapanmadan önce son bir
boşaltma eklendi.

Son olarak paketlenmiş zip açılıp çalıştırıldı: **gerçek** `gradlew`, gerçek
proje. Bu konteynerde beklendiği gibi düştü — AGP indirilemiyor — ve sebebi
logda açıkça göründü:

```
* What went wrong:
Plugin [id: 'com.android.application', version: '8.7.3'] was not found …
```

Yani boru hattının tamamı çalışıyor; Space'te farklı olan tek şey bu bağımlılığın
erişilebilir olması.

### Doğrulanamayan

Docker image'ının kendisi burada derlenemedi (Docker yok, ve zaten
`dl.google.com` engelli). Yani `sdkmanager`'ın lisansları kabul etmesi,
SDK'nın kurulması ve bağımlılık ısıtması ilk kez Space'te çalışacak. Dockerfile
dikkatle yazıldı — bilinen tuzaklar (cmdline-tools dizin yapısı, `yes |`
SIGPIPE, uid 1000 sahipliği) yorumlarda gerekçeleriyle işaretli — ama ilk gerçek
image derlemesi sende olacak.

Bir şey patlarsa Space'in kendi build log'unu bana ilet, yeterli.
