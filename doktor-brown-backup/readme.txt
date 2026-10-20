=== Doktor Brown Backup ===
Contributors: doktorbrown
Tags: backup, restore, migration, database, export
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bağımsız WordPress yedekleme eklentisi. Siteyi (dosyalar + veritabanı) tek bir .dbb arşivine aktarır ve geri yükler. Parçalı çalışır; telemetri, lisans veya uzak servis içermez.

== Description ==

Doktor Brown Backup, herhangi bir ücretli eklentiye veya uzak servise ihtiyaç duymadan
WordPress sitenizin tam yedeğini alır ve geri yükler.

* Tüm site dosyaları + veritabanı → tek `.dbb` arşivine paketler ve geri yükler.
* **`.wpress` arşivlerini içe aktarma** desteği.
* **Parçalı (chunked) çalışma**: büyük sitelerde zaman aşımı/`memory_limit` sorunu yaşatmaz.
* **10 GB'a kadar yükleme:** parça boyutu **5 MB tavanlıdır** (PHP sınırı daha
  yüksek olsa bile). 413/403'te parça yarıya iner; diğer hatalarda aynı parça
  30 kez, artan beklemeyle tekrar denenir. Bu protokol Doktor Brown Backup
  için özgün olarak geliştirilmiştir.
* **Parçalı veritabanı geri yükleme:** SQL dosyası tek istekte değil, güvenli
  sorgu grupları hâlinde yüklenir; Plesk/PHP 30–60 sn limitinde yarıda kesilmez.
* **Geri yükleme öncesi güvenlik yedeği:** mevcut DB, üzerine yazılmadan önce
  `wp-content/dbb-tmp/<iş>/pre-restore-*.sql` olarak alınır.
* **Tanılama / Loglar:** her AJAX isteği, sunucu limitleri, yavaş adımlar,
  SQL hataları, PHP fatal/timeout ve tarayıcı tarafındaki 403/413/504 yanıtları
  `wp-content/dbb-logs/` altına yazılır; panelden indirilebilir.
* **`wp-config.php` asla yedeklenmez/geri yazılmaz** (hedef sitenin DB
  bilgilerini ezmesin diye).
* **Ön kontrol + son onay:** geri yüklemeden önce veritabanına geçici tablo
  yazılıp silinir, dizinlere probe dosyası yazılır, disk alanı ölçülür ve
  sonuçlar animasyonlu kontrol listesinde gösterilir. Kritik hata varsa devam
  edilemez; yoksa "yedeğimi aldım" onay kutusu işaretlenmeden başlamaz.
* **Binary/blob güvenli yedek:** `binary`, `varbinary`, `blob` ve geçersiz
  UTF-8 alanları hex literal olarak yazılır; MySQL 8 sıkı modunda da geri yüklenir.
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
WordPress kök dizini. `wp-config.php`, `.user.ini`, `wp-content/cache`, `wp-content/upgrade`,
`.git` ve yedek klasörleri otomatik hariç tutulur (`dbb_export_excludes` filtresiyle değiştirilir).

= Geri yükleme neden yarıda kalıyor? =
Eski sürüm tüm `database.sql` dosyasını tek AJAX isteğinde çalıştırıyordu. Plesk gibi
30–60 sn istek limiti olan sunucularda istek kesiliyor, bazı tablolar DROP edilmiş ama
yeniden oluşturulmamış kalıyordu. 1.3.0 bunu sorgu gruplarına böler; her istek kendi
offset'ini kaydeder ve kaldığı yerden devam eder. Ayrıca ilk SQL hatasında durur ve
hatanın tablosunu/ifadesini loglar (eskiden sessizce devam ediyordu).

= Yükleme neden "tekrar deneniyor" diyor? =
Bu mesaj tarayıcının isteği 413/403/500/504 veya ağ hatasıyla düştüğü anlamına gelir.
1.3.0 her denemeyi HTTP kodu ve sunucu yanıtıyla `dbb-logs` dosyasına yazar. Parça
boyutu 5 MB ile sınırlıdır; 413/403 gelirse otomatik yarıya iner ve aynı parça
30 kez denenir. Yine takılırsa "Geri Yükle" ekranından parça boyutunu **256 KB** yapın.

= 10 GB yükleme gerçekten mümkün mü? =
Evet, sunucunun disk alanı yettiği sürece. Dosya hiçbir zaman tek parça hâlinde
gönderilmez; 5 MB'lık parçalar hâlinde gönderilip sunucuda birleştirilir. Bu yüzden
`upload_max_filesize` 2 MB olsa bile 10 GB arşiv yüklenebilir.

= Yükleme protokolü nedir? =
Dosya 5 MB'lık parçalara bölünür. Sunucu 413/403 dönerse parça yarıya indirilir;
ağ hatasında aynı parça artan beklemeyle 30 kez yeniden denenir. Parçalar sunucuda
offset sırasına göre birleştirilir; kopan yükleme kaldığı yerden devam eder.

== Changelog ==

= 1.3.0 =
* **5 MB parça tavanı + 30 denemeli yükleme:** 10 GB'a kadar arşiv, Plesk/WAF
  limitlerine rağmen yüklenir. 413/403'te parça yarıya iner; ağ hatasında aynı
  parça artan beklemeyle tekrar denenir.
* **Parçalı DB geri yükleme:** `database.sql` sorgu grupları hâlinde, offset
  kaydederek yüklenir. Plesk/FPM `max_execution_time` kesintisi "yarıda kalma"yı
  bitirir.
* **SQL hatası artık fatal:** ilk hatada durur, tablo + hata + ifade başını loglar.
  Eskiden `$wpdb->query()` hataları yutuluyor ve yarım DB ile devam ediliyordu.
* **Geri yükleme öncesi güvenlik yedeği** (`db-safety` adımı, tablo tablo).
* **Tanılama / Loglar ekranı:** ortam (PHP/DB limitleri, `max_allowed_packet`,
  disk), istek süreleri, fatal/timeout, SQL hataları ve istemci 403/413/504
  olayları. Log indirme + temizleme.
* **Ön kontrol ekranı + son uyarı modalı:** DB yazma testi, dizin probe'ları,
  disk alanı, PHP/Zip/limit kontrolleri ve arşiv manifesti. Kritik hata varsa
  geri yükleme başlamaz.
* **MySQL 8 / MariaDB uyumu:** `SET SESSION sql_mode=''`, binary/blob alanları
  hex literal; boş blob'lar `''` olarak yazılır. Eski arşivler de bu kurallarla
  geri yüklenir.
* **Yedek (export) düzeltmesi:** binary sütunlar artık bozulmadan yazılır;
  üretilen `database.sql` phpMyAdmin'den de içe aktarılabilir.
* **`wp-config.php` / `.user.ini` arşiv dışı**; eski arşivlerde de geri yazılmaz.
* **Büyük arşivi kopyalamadan geri yükleme:** "listedeki yedeği geri yükle"
  artık 100+ MB dosyayı tek istekte kopyalamaya çalışmaz.
* **Sınırlı + geri çekilmeli retry** (sonsuz "tekrar deneniyor" döngüsü yerine).
* **post_max_size aşımı** net 413 mesajı verir (eskiden "İş bulunamadı" 404).
* Yükleme ekranına **parça boyutu seçimi** (Otomatik / 256 KB – 2 MB).
* Sürüm 1.3.0.

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

= 1.3.0 =
Parçalı DB geri yükleme + SQL hata logu + Tanılama/Loglar + wp-config koruması.
Plesk'te yarıda kalan geri yüklemeler için zorunlu güncelleme.

= 1.2.0 =
Kaldığı yerden devam (resume) + çift-parça/sıra koruması.

= 1.1.0 =
Sürükle-bırak alanı + daha dayanıklı parçalı yükleme.
