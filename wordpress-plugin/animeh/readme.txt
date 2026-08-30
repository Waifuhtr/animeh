=== Animeh ===
Contributors: waifuhtr
Tags: video, player, hls, subtitles, ass
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Animeh oynatıcısı için yönetim paneli: kaynak testi, altyazı fontları, Backblaze B2 depolama ve site taşıma.

== Description ==

Bu eklenti, Animeh projesinin özel video oynatıcısını WordPress içinden test etmek için kullanılır.

* HLS ve MKV kaynaklarını gerçek ortamda oynatır
* ASS/SSA altyazıları libass ile render eder
* Bir altyazının ihtiyaç duyduğu fontları tespit eder, eksik olanları raporlar
* Eksik fontlar panelden yüklenebilir ve oynatıcıya anında sunulur
* Bant genişliğini kısıtlayarak zayıf bağlantı davranışını ölçer
* Başlangıç süresi, yeniden tamponlama ve bant genişliği ölçümlerini kaydeder
* Backblaze B2 bucket'ını yapılandırır ve bağlantıyı sınar
* Bölümleri, WordPress'ten geçirmeden doğrudan bucket'a çok parçalı yükler
* Friendly URL yanıt vermezse oynatmayı S3 adresine devreder
* Kütüphane verisini bucket'a yedekler ve başka bir siteye taşır

Fontlar `wp-content/uploads/animeh-fonts/` altında saklanır ve dosya içeriğine
bakılarak doğrulanır; sitenin genel yükleme ayarları değiştirilmez.

Backblaze uygulama anahtarı şifrelenerek saklanır, hiçbir endpoint'ten geri
dönmez ve yedeklerin içine hiçbir zaman girmez.

== Installation ==

1. Eklentiyi `wp-content/plugins/animeh/` klasörüne kopyala veya zip olarak yükle.
2. Eklentiler ekranından etkinleştir.
3. Yönetim menüsünde **Animeh → Player Test** ekranını aç.
4. Depolama kullanacaksan **Animeh → Depolama** ekranından bucket bilgilerini gir ve **Bağlantıyı Sına** ile doğrula.
5. **Animeh → Yedek ve Taşıma** ekranından günlük yedeği aç.

== Frequently Asked Questions ==

= Hız kısıtlama neden sunucudan geçiyor? =

Zayıf bağlantı davranışı hızlı bir bağlantıda ölçülemez. Kısıtlama gerektiğinde
medya, yalnızca yetkili kullanıcılara açık bir proxy üzerinden akar. Kısıtlama
kapalıyken medya doğrudan kaynağından çekilir.

= Hangi adresler kullanılabilir? =

Yalnızca http ve https. Özel ağlara, loopback'e ve bulut metadata adreslerine
işaret eden adresler reddedilir. İstersen ayarlardan bir alan adı izin listesi
tanımlayabilirsin.

== Changelog ==

= 0.1.0 =
* İlk sürüm: player test paneli, font kayıt defteri ve kısıtlama proxy'si.
