=== Doktor Brown Backup ===
Contributors: doktorbrown
Tags: backup, restore, migration, database, export
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bağımsız WordPress yedekleme eklentisi. Siteyi (dosyalar + veritabanı) tek bir .dbb arşivine aktarır ve geri yükler. Parçalı çalışır; telemetri, lisans veya uzak servis içermez.

== Description ==

Doktor Brown Backup, herhangi bir ücretli eklentiye veya uzak servise ihtiyaç duymadan
WordPress sitenizin tam yedeğini alır ve geri yükler.

* Tüm site dosyaları + veritabanı → tek `.dbb` arşivine paketler ve geri yükler.
* **`.wpress` arşivlerini içe aktarma** desteği.
* **Parçalı (chunked) çalışma**: büyük sitelerde zaman aşımı/`memory_limit` sorunu yaşatmaz.
* **Otomatik alan adı değiştirme**: localhost → canlı domain geçişinde eski adres
  arşivden okunur ve yeni adresle **serileştirme-güvenli** biçimde değiştirilir.
* Yedek listesi, indirme, silme ve **doğrudan geri yükleme**.
* **Telemetri yok, lisans sunucusu yok, WebAssembly yok, uzak çağrı yok.**
* GPL-2.0 lisansı.

== Kurulum ==

1. `doktor-brown-backup` klasörünü `wp-content/plugins/` içine kopyalayın (veya ZIP'i yükleyin).
2. Eklentiler ekranından etkinleştirin.
3. WP Admin → **Dr. Brown Backup** menüsünü kullanın.

== Sıkça Sorulan Sorular ==

= php-zip gerekli mi? =
Evet. ZipArchive (php-zip) yoksa eklenti uyarı verir ve çalışmaz.

= Farklı sunucuya taşıma =
Yedeği yeni siteye kurun, "Geri Yükle" ekranında **Yeni Site Adresi** alanına hedef URL'yi yazın.

= Hangi dosyalar dahil? =
WordPress kök dizini. `wp-content/cache`, `wp-content/upgrade`, `.git` ve yedek klasörleri
otomatik hariç tutulur (`dbb_export_excludes` filtresiyle değiştirilir).

== Changelog ==

= 1.2.0 =
* **Kaldığı yerden devam (resume):** yükleme ağ kesintisinde koparsa yarım
  kalmış işi sorar, kabul edilen bayttan devam eder (min 256KB parça küçültme).
* **Çift-parça korunumu:** sunucuda zaten yazılmış bölge atlanır, "received" baytıyla devam.
* **Sıra bozulması koruması:** boşluklu parça 409 ile reddedilir (arşiv bozulmaz).
* Yeni AJAX: `dbb_import_resume`; yeni i18n: `resumeAsk`, `resuming`.

= 1.1.0 =
* Yükleme %4'te takılmıyor: parça boyutu sunucu gerçek yükleme sınırından
  hesaplanır; reddedilince parça yarıya düşürülüp otomatik yeniden denenir.
* "Geri Yükle" ekranına sürükle-bırak alanı eklendi (.dbb / .wpress).

= 1.0.0 =
* İlk sürüm: dışa aktarma, içe aktarma, liste/indir/sil, parçalı işlem.

== Upgrade Notice ==

= 1.2.0 =
Kaldığı yerden devam (resume) + çift-parça/sıra koruması.

= 1.1.0 =
Sürükle-bırak alanı + daha dayanıklı parçalı yükleme.
