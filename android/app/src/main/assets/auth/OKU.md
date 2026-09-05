# Giriş ve kayıt ekranının kapak görselleri

Bu klasöre iki görsel koy:

| Dosya | Nerede görünür | Önerilen boyut |
| --- | --- | --- |
| `login.<uzantı>` | Giriş ekranının üst yarısı | 1080 × 1350 (3:4), dikey |
| `register.<uzantı>` | Kayıt ekranının üst kısmı | 1080 × 1080 (1:1), dikey/kare |

Uzantı serbest: `.jpg`, `.jpeg`, `.png` veya `.webp`. Uygulama dosyayı adının
ilk kısmına bakarak bulur, yani `login.webp` de `login.jpg` de çalışır — ikisini
birden koyma, biri yeter.

Görsellerin üst kısmına başlık yazıları biniyor ("Tekrar hoş geldin!",
"Hesap oluştur"), o yüzden karakterin yüzü **üst yarıda** olan bir görsel
seç; alt kısım karartma ile zaten kararıyor.

Dosya yoksa uygulama patlamaz — mor bir degrade çizer ve ekran çalışmaya
devam eder.
