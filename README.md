# Doktor Brown Backup

Bağımsız **WordPress yedekleme/geri yükleme** eklentisi. Siteyi (dosyalar + veritabanı) tek bir `.dbb` arşivine paketler ve geri yükler. Ayrıca **`.wpress`** arşivlerini içe aktarır. Parçalı (chunked) çalışır; **telemetri, lisans sunucusu, WASM veya uzak çağrı içermez.**

## Özellikler

- **Yedekleme (export):** tüm WordPress dosyaları + veritabanı → tek `.dbb` (ZIP tabanlı) arşiv.
- **Geri yükleme (import):** `.dbb` ve `.wpress` arşivlerini yükleyip geri yükler.
- **Parçalı (chunked) çalışma:** büyük sitelerde `max_execution_time` / `memory_limit` sorunu yaşatmaz.
- **10 GB'a kadar yükleme (v1.3.0):** parça boyutu **5 MB tavanlı**; 413/403'te yarıya iner, ağ hatasında aynı parça **30 kez** artan beklemeyle denenir. Yükleme protokolü Doktor Brown Backup için özgün geliştirilmiştir.
- **Parçalı veritabanı geri yükleme (v1.3.0):** `database.sql` tek istekte değil, offset kaydeden sorgu grupları hâlinde yüklenir; Plesk'in 30–60 sn limitinde yarıda kesilmez.
- **SQL hata logu (v1.3.0):** ilk hatalı sorguda durur; tablo, MySQL hatası ve sorgu başını `wp-content/dbb-logs` içine yazar.
- **Geri yükleme öncesi güvenlik yedeği (v1.3.0):** mevcut DB, üzerine yazılmadan önce `dbb-tmp/<iş>/pre-restore-*.sql` olarak alınır.
- **Tanılama / Loglar (v1.3.0):** sunucu limitleri, istek süreleri, fatal/timeout, SQL hataları ve tarayıcıdaki 403/413/504 olayları panelden görüntülenir/indirilir.
- **`wp-config.php` koruması (v1.3.0):** arşive alınmaz ve eski arşivlerden geri yazılmaz — hedef sitenin DB bilgileri ezilmez.
- **Ön kontrol + son onay (v1.3.0):** geri yüklemeden önce DB'ye geçici tablo yazılıp silinir, dizinlere probe dosyası yazılır, disk alanı ölçülür; animasyonlu kontrol listesi ve zorunlu "yedeğimi aldım" onayı vardır.
- **Binary/blob güvenli (v1.3.0):** `binary`/`blob` alanları hex literal olarak yazılır; MySQL 8 sıkı modunda da geri yüklenir.
- **Sunucuya uyumlu parça boyutu:** yükleme parçaları sunucunun gerçek `upload_max_filesize` limitinden hesaplanır; reddedilen parça otomatik küçültülüp yeniden denenir (v1.1.0). Plesk/WAF için 256 KB seçilebilir.
- **Sürükle-bırak yükleme:** "Geri Yükle" sayfasında dosyayı alana bırakın veya tıklayıp seçin (v1.1.0).
- **Otomatik alan adı değiştirme:** localhost → canlı domain geçişinde eski adres arşivden okunur, **serileştirme-güvenli** biçimde yeni adresle değiştirilir.
- Yedek listesi, indirme, silme ve yedekten geri yükleme.
- **WP-CLI destegi:** `wp dbb export | backups | restore | delete`.

## Kurulum

1. `doktor-brown-backup` klasörünü `wp-content/plugins/` içine kopyalayın — veya GitHub Release'teki
   `doktor-brown-backup-*.zip` dosyasını WP Admin → Eklentiler → Yeni Ekle → ZIP Yükle ile kurun.
2. Eklentiyi **etkinleştirin**.
3. WP Admin → **Dr. Brown Backup** menüsü → Yedekler · Yeni Yedek · Geri Yükle.

> **Not:** Sunucuda `php-zip` (ZipArchive) gerekir; yoksa eklenti uyarı verir.

## Geri Yükleme ("uzak sunucuya yedek aktarma")

1. Yedeği almak istediğiniz sitede **Yeni Yedek** ile `.dbb` arşivini oluşturun.
2. Dosyayı (ör. sızdırmadan FTP/panel ile) hedef bilgisayara indirin.
3. Hedef WordPress üzerinde **Dr. Brown Backup → Geri Yükle** sayfasında dosyayı **sürükle-bırak** veya seçin.
4. **Yeni Site Adresi** boş bırakılırsa hedef sitenin kendi adresi kullanılır; doldurursanız otomatik değiştirilir.

### %4'te takılma düzeltmesi (v1.1.0)

Eski sürüm sabit 2 MB parça kullanıyordu; `upload_max_filesize` sınırlı sunucularda 2. parça reddedilip ilerleme ~%4'te donuyordu. Şimdi:

- Parça boyutu sunucunun **gerçek yükleme limitinden** hesaplanır (`wp_max_upload_size()` × %70 güvenlik payı).
- Sunucu parçayı reddederse veya ağ hatası olursa parça **yarıya düşürülüp otomatik yeniden denenir** (min 256 KB).

### Plesk'te "tekrar deneniyor" / yarıda kalma (v1.3.0)

- Yükleme isteği 413/403/500/504 dönerse parça **5 MB tavanına** çekilir; 413/403'te yarıya iner, diğer hatalarda aynı parça **30 kez** artan beklemeyle denenir.
- `database.sql` **parçalı** yüklenir; her istek kendi offset'ini kaydeder. PHP timeout olsa bile kaldığı yerden devam eder.
- Geri yükleme öncesi **mevcut DB'nin SQL yedeği** alınır (kapatmak için `dbb_safety_backup` filtresi).
- Hata ve limitler: **Dr. Brown Backup → Tanılama / Loglar**.

## Dosya yapısı

```text
doktor-brown-backup/
├── doktor-brown-backup.php   # ana eklenti dosyası
├── uninstall.php
├── readme.txt
├── assets/
│   ├── css/admin.css         # admin stilleri (sürükle-bırak alanı dahil)
│   └── js/admin.js           # parçalı yükleme + ilerleme + sürükle-bırak
└── includes/
    ├── class-dbb-plugin.php      # çekirdek: storage, AJAX yönlendirme
    ├── class-dbb-admin.php       # admin menüleri/sayfaları
    ├── class-dbb-exporter.php    # dışa aktarma motoru
    ├── class-dbb-importer.php    # içe aktarma motoru (parçalı yükleme dahil)
    ├── class-dbb-archive.php     # .dbb (ZIP) okuma/yazma
    ├── class-dbb-wpress.php      # .wpress okuyucu
    ├── class-dbb-db.php          # SQL bölme + veritabanı geri yükleme
    ├── class-dbb-replace.php     # serileştirme-güvenli arama/değiştir
    ├── class-dbb-filesystem.php  # dosya listeleme + yol güvenliği
    └── class-dbb-cli.php         # WP-CLI komutları
```

## Test

Yerel (WordPress'siz) smoke testi — XAMPP PHP ile çalıştırılır:

```powershell
& "C:\xampp\php\php.exe" tests\smoke.php
```

37 kontrol: SQL bölme, dosya listeleme/güvenliği, `.wpress` başlık ayrıştırma,
serileştirme-güvenli alan adı değiştirme ve v1.1.0 kaynak sözleşmeleri (sürükle-bırak,
markasız not, dinamik parça boyutu, otomatik yeniden deneme).

## Lisans

GPL-2.0-or-later. Kod, başka bir ücretli eklentinin incelenmesiyle yeniden
yazılmış **özgün** bir uygulamadır; kırık/pirate sürüm dahil edilmez.
