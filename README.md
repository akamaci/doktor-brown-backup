# Doktor Brown Backup

Bağımsız **WordPress yedekleme/geri yükleme** eklentisi. Siteyi (dosyalar + veritabanı) tek bir `.dbb` arşivine paketler ve geri yükler. Ayrıca **`.wpress`** (All-in-One WP Migration) arşivlerini içe aktarır. Parçalı (chunked) çalışır; **telemetri, lisans sunucusu, WASM veya uzak çağrı içermez.**

## Özellikler

- **Yedekleme (export):** tüm WordPress dosyaları + veritabanı → tek `.dbb` (ZIP tabanlı) arşiv.
- **Geri yükleme (import):** `.dbb` ve `.wpress` arşivlerini yükleyip geri yükler.
- **Parçalı (chunked) çalışma:** büyük sitelerde `max_execution_time` / `memory_limit` sorunu yaşatmaz.
- **Sunucuya uyumlu parça boyutu:** yükleme parçaları sunucunun gerçek `upload_max_filesize` limitinden hesaplanır; reddedilen parça otomatik küçültülüp yeniden denenir (v1.1.0).
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

GPL-2.0-or-later. Kod, ücretli "All-in-One WP Migration Unlimited" eklentisinin
incelenmesiyle yeniden yazılmış **özgün** bir uygulamadır; kırık/pirate sürüm dahil edilmez.
