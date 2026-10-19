# Doktor Brown Backup â€” GELÄ°ÅTÄ°RME NOTLARI (teknik)

> **AmaÃ§:** Ä°leride geliÅŸtirme yaparken gereken her ÅŸey tek yerde. Kod yazmadan Ã¶nce bunu oku.
> Son gÃ¼ncelleme: 2026-10-18 Â· `[Doc]` Â· **Makine:** `[Dell-PC]`
> Ä°lgili: [[00-ANALIZ]] Â· [[00-YAPILACAKLAR]]

---

## 1. Ortam (bu makinede)

| Ne | Yol / deÄŸer |
|---|---|
| PHP | `C:\xampp\php\php.exe` (PHP 8.0.30) |
| Test WordPress | `C:\xampp\htdocs\doga` (WP 7.1.3 Â· prefix `wpxz_` Â· admin `Webyoda`) |
| Plugin (kurulu) | `C:\xampp\htdocs\doga\wp-content\plugins\doktor-brown-backup` |
| Proje kaynaÄŸÄ± | `D:\AI\TASARIM\Projeler\doktor-brown-backup\doktor-brown-backup` |
| Yedek klasÃ¶rÃ¼ (site) | `wp-content/dbb-backups` Â· geÃ§ici: `wp-content/dbb-tmp` |
| WP-CLI | `C:\Users\Dell-Pc\AppData\Local\Temp\opencode\wp-cli.phar` (indirildi) |
| Servisler | Apache :80 Â· MySQL :3306 (XAMPP) |
| DiÄŸer WP | `poyraz` (baÅŸka ajan kullanÄ±yor â€” **dokunma**), `_Envato-element-Wpress` (test .wpress var) |

âš ï¸ **Kod deÄŸiÅŸince doga'ya kopyala:**
```powershell
Copy-Item "D:\AI\TASARIM\Projeler\doktor-brown-backup\doktor-brown-backup\*" "C:\xampp\htdocs\doga\wp-content\plugins\doktor-brown-backup" -Recurse -Force
```

---

## 2. Kod mimarisi (sÄ±nÄ±flar)

| Dosya | SÄ±nÄ±f | Sorumluluk |
|---|---|---|
| `doktor-brown-backup.php` | â€” | BaÅŸlÄ±k, sabitler, boot |
| `includes/class-dbb-plugin.php` | `DBB_Plugin` | Bootstrap, sabit yollar, AJAX kaydÄ±, gÃ¼venlik, CLI kaydÄ±, `.htaccess` guard |
| `class-dbb-filesystem.php` | `DBB_Filesystem` | Dosya tarama (hariÃ§ tutma), `resolve()` (traversal korumalÄ±) |
| `class-dbb-db.php` | `DBB_DB` | DB dump (`dump_table`) + restore (`restore_file`) + **SQL ayrÄ±ÅŸtÄ±rÄ±cÄ±** (`take_complete`, `split_statements`) |
| `class-dbb-replace.php` | `DBB_Replace` | **SerileÅŸtirme-gÃ¼venli** arama-deÄŸiÅŸtirme + URL varyant Ã¼retimi |
| `class-dbb-archive.php` | `DBB_Archive` | `.dbb` = **ZIP** sarmalayÄ±cÄ± + manifest |
| `class-dbb-wpress.php` | `DBB_Wpress` | **`.wpress` okuyucu** (AI1WM formatÄ±) |
| `class-dbb-exporter.php` | `DBB_Exporter` | Durum makinesi: `db` â†’ `files` â†’ `finalize` |
| `class-dbb-importer.php` | `DBB_Importer` | Durum makinesi: `detect` â†’ `extract`/`wp-extract` â†’ `db` â†’ `replace` â†’ `files` â†’ `finalize` |
| `class-dbb-admin.php` | `DBB_Admin` | MenÃ¼, sayfalar, liste, indir, sil, `describe()` |
| `class-dbb-cli.php` | `DBB_CLI` | `wp dbb export` Â· `backups` Â· `restore` Â· `delete` |

---

## 3. â˜… Motor (engine) API â€” transport'suz

Her iki motor da **AJAX'tan baÄŸÄ±msÄ±z** Ã§alÄ±ÅŸÄ±r (CLI/test iÃ§in):

```php
$ex = new DBB_Exporter();
$r  = $ex->start();                 // ['job'=>..., 'totalFiles'=>.., 'tables'=>..]
while ('done' !== ($s = $ex->step($r['job'])['status'])) { /* ... */ }

$im = new DBB_Importer();
$r  = $im->prepare_from_backup($file, $target_url, $auto_domain); // veya start()+upload
while ('done' !== ($s = $im->step($r['job'], $target_url, $auto_domain)['status'])) { /* ... */ }
```
> `ajax_*` metodlarÄ± yalnÄ±zca **sarmalayÄ±cÄ±**: `verify_request()` + `wp_send_json_*`. Ä°ÅŸ mantÄ±ÄŸÄ± motor metodlarÄ±nda.

**Durum:** her iÅŸ `wp-content/dbb-tmp/<job>/state.json` iÃ§inde tutulur (kesintiye dayanÄ±klÄ±).

---

## 4. AJAX sÃ¶zleÅŸmesi (istemci â†” sunucu)

| Action | Girdi | DÃ¶nÃ¼ÅŸ |
|---|---|---|
| `dbb_export_start` | â€” | `{job,totalFiles,tables,progress}` |
| `dbb_export_step` | `job` | `{status,step,progress,message,file}` |
| `dbb_import_start` | â€” | `{job}` |
| `dbb_import_upload` | `job,offset,chunk(file)` | `{received}` |
| `dbb_import_step` | `job,target_url,auto_domain` | `{status,step,progress,message}` |
| `dbb_restore_backup` | `file,target_url,auto_domain` | `{job}` |
| `dbb_delete` | `file` | `{message}` |

TÃ¼mÃ¼: `nonce` (dbb_ajax) + `manage_options` (`DBB_Plugin::CAPABILITY`).
JS: `assets/js/admin.js` (chunk 2 MB, otomatik tekrar deneme).

---

## 5. â˜… ArÅŸiv formatlarÄ±

### 5.1 `.dbb` (bizim format) â€” ZIP
```
dbb-manifest.json   { format, plugin_ver, created, site_url, home_url,
                      abspath, table_prefix, wp_version, file_count, table_count }
database.sql        mysqldump benzeri (DROP+CREATE+INSERT)
files/<gÃ¶reli yol>  ABSPATH'e gÃ¶re
```

### 5.2 `.wpress` (All-in-One WP Migration) â€” Ã¶zel blok
**Blok baÅŸlÄ±ÄŸÄ± = 4377 bayt** (`unpack('a255name/a14size/a12mtime/a4088prefix/a8crc32')`):

| Alan | Offset | Uzunluk |
|---|---|---|
| name | 0 | 255 |
| size | 255 | 14 |
| mtime | 269 | 12 |
| prefix | 281 | 4088 |
| crc32 | 4369 | 8 |

- Veri header'dan hemen sonra (`offset = blok_baÅŸÄ± + 4377`), `size` kadar.
- Veri **gzip** olabilir â†’ `1f 8b` sihri varsa `gzdecode`.
- KÃ¶k dizin prefix'i `"."` gelir â†’ **temizlenir** (`.`/`./` â†’ boÅŸ).
- ArÅŸiv sonu: boÅŸ name + boÅŸ size bloÄŸu (v1) veya CRC'li (v2).
- **DB dosyasÄ±:** `database.sql` (bazen farklÄ± ad) â†’ `*.sql` ile aranÄ±r.

---

## 6. â˜… Alan adÄ± deÄŸiÅŸtirme (`DBB_Replace`)

- **Eski adres** kaynaÄŸÄ±: `.dbb` â†’ manifest `site_url`; `.wpress` â†’ `package.json` `SiteURL`.
- **Yeni adres**: `target_url` verilmiÅŸse o; yoksa **DB bozulmadan Ã–NCE** yakalanan `home_url()` (`state.resolved_new_url`).
  âš ï¸ DB restore sonrasÄ± `home_url()` **eski** deÄŸeri dÃ¶ner â€” bu yÃ¼zden `step_detect`'te yakalanÄ±r.
- **SerileÅŸtirme gÃ¼venliÄŸi:** `@unserialize($v, ['allowed_classes'=>false])` â†’ dizi iÃ§inde `str_replace` â†’ `serialize()`.
  `O:`/`C:` (nesne) iÃ§eren serileÅŸtirilmiÅŸ veriler **atlanÄ±r** (incomplete-class riski).
- **URL varyantlarÄ±:** `url_pairs()` http/https + www/non-www Ã¼retir.
- SÄ±ra: `db` â†’ **`replace`** â†’ `files` (replace DB'de, dosyalarda deÄŸil).

---

## 7. GÃ¼venlik kararlarÄ±

- Enable ederken `.htaccess` (`Require all denied`) + `index.php` â†’ yedek/tmp klasÃ¶rleri web'den kapalÄ±.
- `DBB_Filesystem::resolve()`: `..` ve null byte iÃ§eren yolu **reddeder**.
- TÃ¼m AJAX: nonce + capability.
- Dosya adlarÄ± `sanitize_file_name`.
- **Telemetri/uzak Ã§aÄŸrÄ±/WASM YOK** (kÄ±rÄ±k AIO'nun tam tersi â€” bkz. [[00-ANALIZ]] Â§2).

---

## 8. Test komutlarÄ±

```powershell
$php="C:\xampp\php\php.exe"

# 1) SÃ¶zdizimi
Get-ChildItem -Recurse -File -Filter *.php "D:\AI\TASARIM\Projeler\doktor-brown-backup\doktor-brown-backup" |
  ForEach-Object { & $php -l $_.FullName }

# 2) Birim/entegrasyon testi (28 test, WordPress'siz)
& $php "D:\AI\TASARIM\Projeler\doktor-brown-backup\tests\smoke.php"

# 3) doga Ã¼zerinde canlÄ± test (yardÄ±mcÄ± scriptler)
& $php "C:\Users\Dell-Pc\AppData\Local\Temp\opencode\doga-drive.php"     export
& $php "C:\Users\Dell-Pc\AppData\Local\Temp\opencode\doga-drive.php"     list
& $php "C:\Users\Dell-Pc\AppData\Local\Temp\opencode\doga-drive.php"     import <file.dbb>
& $php "C:\Users\Dell-Pc\AppData\Local\Temp\opencode\doga-wpress.php"    <file.wpress>   # okuyucu
& $php "C:\Users\Dell-Pc\AppData\Local\Temp\opencode\dbb-wpress-ittest.php"              # scratch DB + domain

# 4) WP-CLI
$wp="C:\Users\Dell-Pc\AppData\Local\Temp\opencode\wp-cli.phar"
& $php $wp dbb backups --path="C:\xampp\htdocs\doga"
& $php $wp dbb export  --path="C:\xampp\htdocs\doga"
& $php $wp dbb restore <file> --path="C:\xampp\htdocs\doga" [--url=...] [--no-domain]
```

> âš ï¸ **YÄ±kÄ±cÄ± test Ã¶ncesi:** `mysqldump` gÃ¼venlik aÄŸÄ± al (`doga-safety.php dump`).

---

## 9. Ã–ÄŸrenilen tuzaklar (tekrar yaÅŸamamak iÃ§in)

1. **`names` Ã§akÄ±ÅŸmasÄ±:** WP-CLI'de `list` rezerve â†’ metod adÄ± `backups` (trailing `_` iÅŸe yaramadÄ±).
2. **`WP_CLI::table()` yok** â†’ `\WP_CLI\Utils\format_items('table', $rows, $fields)`.
3. **`.wpress` prefix `"."`** â†’ normalize et, yoksa `./` yollarÄ± ve package.json bulunamaz.
4. **`home_url()` DB restore sonrasÄ± eskidir** â†’ yeni adresi **restore'dan Ã¶nce** yakala.
5. **Wordfence log tablosu** restore sÄ±rasÄ±nda eÅŸzamanlÄ± yazÄ±lÄ±rsa `Duplicate entry` uyarÄ±r (kritik deÄŸil).
6. **ZipArchive her adÄ±mda yeniden aÃ§Ä±lÄ±r** (CREATE) â€” bÃ¼yÃ¼k arÅŸivde yavaÅŸlar; gelecekte tek handle/part dosya dÃ¼ÅŸÃ¼nÃ¼lebilir.
7. **AIO kÄ±rÄ±k sÃ¼rÃ¼mleri** (`all-in-one-287/288`) â†’ **kopyalama**, referans iÃ§in bkz. [[00-ANALIZ]].

---

## 10. SÄ±radaki iÅŸler
Bkz. [[00-YAPILACAKLAR]] Â§"PROGRAM â€” sÄ±radaki geliÅŸtirmeler".
