=== Animeh ===
Contributors: waifuhtr
Tags: video, player, hls, subtitles, ass
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Animeh oynatıcısını gerçek kaynaklarla test etmek ve altyazı fontlarını yönetmek için yönetim paneli.

== Description ==

Bu eklenti, Animeh projesinin özel video oynatıcısını WordPress içinden test etmek için kullanılır.

* HLS ve MKV kaynaklarını gerçek ortamda oynatır
* ASS/SSA altyazıları libass ile render eder
* Bir altyazının ihtiyaç duyduğu fontları tespit eder, eksik olanları raporlar
* Eksik fontlar panelden yüklenebilir ve oynatıcıya anında sunulur
* Bant genişliğini kısıtlayarak zayıf bağlantı davranışını ölçer
* Başlangıç süresi, yeniden tamponlama ve bant genişliği ölçümlerini kaydeder

Fontlar `wp-content/uploads/animeh-fonts/` altında saklanır ve dosya içeriğine
bakılarak doğrulanır; sitenin genel yükleme ayarları değiştirilmez.

== Installation ==

1. Eklentiyi `wp-content/plugins/animeh/` klasörüne kopyala veya zip olarak yükle.
2. Eklentiler ekranından etkinleştir.
3. Yönetim menüsünde **Animeh → Player Test** ekranını aç.

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
