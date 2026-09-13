# Denetimler

Buradaki betikler, bu ortamda **yapılamayan** derlemelerin yerini tutuyor.

Android tarafında Google'ın Maven deposu bu ortamdan erişilebilir değil, yani
androidx ve Compose jar'ları indirilemiyor ve gerçek bir derleme mümkün değil.
Derleyici bunlar olmadan koşturulduğunda ~13.800 hata veriyor; hepsi göremediği
sınıflardan. Bu gürültüyü *şekline göre* elemek, beş gerçek hatanın bir sürüm
derlemesine kadar gitmesine sebep oldu: üç eksik import, olmayan bir alan ve
uydurulmuş bir string kaynağı.

| Betik | Ne soruyor |
| --- | --- |
| `kotlin-new-errors.sh [ref]` | Bu değişiklik hangi hataları **ekledi**? Temel sürümü de derleyip farkı alıyor: on üç bin gürültünün hepsi iki koşuda da var, yeni yapılan hata yalnızca birinde. **Kendi sembollerimizi ayrı ve en üstte basıyor** — `import com.animeh.…X` yazıp `X`'i çözemeyen bir dosya kesinlikle yanlıştır ve bunu hiçbir eksik jar açıklayamaz. (Tanımı hiç yazılmamış bir renk sabiti, 223 satırlık listede "unresolved reference" diye elenip sürüm derlemesine kadar gitti.) |
| `android_resources.py <kök>` | Koddaki her `R.string.x` / `R.drawable.x` gerçekten `res/` içinde var mı? `R` derleyiciye görünmediği için bunu yalnızca kaynak dosyalarıyla karşılaştırarak anlayabiliyoruz. |
| `subpackage_imports.py <kök>` | `androidx.compose.runtime.*` alt paketlere inmez — `rememberSaveable` gibi bir sembol ayrıca import edilmiş mi? |
| `activity_collisions.py <kök>` | Bir Activity metodu, üst sınıfın metodunu kazara gölgeliyor mu? (`setImmersive` böyle bir derleme hatasıydı.) |
| `php_imports.py <kök>` | Namespace'li bir PHP dosyasındaki her `Foo::` çözülüyor mu? (`ChapterNumber` böyle 500 vermişti.) |
| `rest_routes.py` | Uygulamanın çağırdığı her yol eklentide kayıtlı mı? (`GET /admin/works/{id}` bir sürüm önce gitti: düzenleme formu boş, altta `rest_no_route`.) |
| `dto_payload_keys.py` | Bir DTO'nun beklediği her anahtarı sunucu gerçekten yazıyor mu? Her alanın varsayılanı olduğu için eksik bir anahtar hata vermez, sessizce 0 / `""` olur. |

Ayrıca üç PHP koşucusu, `build-plugin.sh` içinde sırayla:

| | |
| --- | --- |
| `wordpress-plugin/animeh/tests/run.php` | Saf mantık: `Support/` içindeki her şey, WordPress'e hiç dokunmadan. |
| `wordpress-plugin/animeh/tests/smoke/run.php` | WordPress katmanı yükleniyor, rotalar kayıt oluyor, uçlar sahte satırlarla çalışıyor mu. **SQL'i çalıştırmaz** — `$wpdb` sorgu ne derse desin aynı satırları verir. |
| `wordpress-plugin/animeh/tests/sql/run.php` | Sorgular gerçekten ne demek: eklentinin kendi tabloları SQLite'ta kuruluyor, satırlar eklentinin kendi deposuyla yazılıyor, okumalar eklentinin kendi sorgularıyla yapılıyor. Başlığı "2 video" diyip altı boş kalan etiket sayfası yüzünden yazıldı — bir üstteki koşucu bunu göremezdi. |

Ayrıca `tools/` içinde, `build-plugin.sh` tarafından koşulanlar:

- `php-call-check.php` — yerleşik PHP fonksiyonlarına verilen argüman sayısı
  imzayla uyuşuyor mu (`key()` böyle yakalandı).
- `php-method-check.php` — çağrılan proje metodu var mı, argüman sayısı ve
  değişmez argümanların tipleri uyuşuyor mu.

## Kullanım

```sh
bash tools/checks/kotlin-new-errors.sh          # temel: HEAD
python3 tools/checks/android_resources.py .
python3 tools/checks/subpackage_imports.py .
python3 tools/checks/activity_collisions.py .
python3 tools/checks/php_imports.py .
python3 tools/checks/rest_routes.py
python3 tools/checks/dto_payload_keys.py
php tools/php-call-check.php wordpress-plugin/animeh/src wordpress-plugin/animeh-manga-bridge
php tools/php-method-check.php
php wordpress-plugin/animeh/tests/run.php
php wordpress-plugin/animeh/tests/smoke/run.php
php wordpress-plugin/animeh/tests/sql/run.php
```
