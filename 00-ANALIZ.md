# Doktor Brown Backup â€” Rakip Analizi (All-in-One WP Migration Unlimited Extension 2.87 / 2.88)

> **Analiz tarihi:** 2026-10-18 Â· `[Doc]` Â· **Makine:** `[Dell-PC]`
> **Hedefler:** `D:\AI\backup-ALL-IN\all-in-one-287` ve `all-in-one-288`
> **YÃ¶ntem:** Statik kaynak incelemesi (PHP) + SHA-256 dosya karÅŸÄ±laÅŸtÄ±rmasÄ±
> **SonuÃ§:** Bu bir **ServMask "Unlimited Extension" kÄ±rÄ±lmÄ±ÅŸ (nulled) sÃ¼rÃ¼mÃ¼dÃ¼r** â†’ **temiz alternatif yazÄ±ldÄ±: `doktor-brown-backup`**

---

## 1. Ne bu?

| | |
|---|---|
| ÃœrÃ¼n | **All-in-One WP Migration â€“ Unlimited Extension** (ServMask) |
| SÃ¼rÃ¼mler | 2.87 ve 2.88 |
| Lisans (iddia) | GPLv3 |
| Tip | **Base eklentiye baÄŸÄ±mlÄ± uzantÄ±** (WordPress.org'daki Ã¼cretsiz `all-in-one-wp-migration` ÅŸart) |
| GerÃ§ekte | **KÄ±rÄ±lmÄ±ÅŸ**: lisans/aktivasyon kontrolÃ¼ ve uzak servis devre dÄ±ÅŸÄ± bÄ±rakÄ±lmÄ±ÅŸ |

**Ne yapar (orijinal):** Base eklentinin **boyut limitini kaldÄ±rÄ±r**; Pro Ã¶zellikleri (zamanlÄ± yedek, sÄ±fÄ±rlama araÃ§larÄ±, WP-CLI) aÃ§ar.

---

## 2. â˜… KÄ±rma (nulling) teknikleri â€” tespit edildi

| # | Teknik | Yer | Ne yapÄ±yor |
|---|---|---|---|
| N1 | **`pre_http_request` filtresiyle lisans sahteleme** | `all-in-one-wp-migration-unlimited-extension.php:71-79` | `check/unlimited-extension` â†’ sahte `200 {}`; `servmask.com/purchase/activations` â†’ sahte `{"success":true}` (sabit UUID `b5e0b5f8-â€¦`) |
| N2 | **Uzak servis (WASM) nÃ¶tralize** | `constants.php:68-70` | `AI1WMUE_SERVICE_URL` gerÃ§ek `plugin-assets.wp-migration.com/.../service.wasm` â†’ `data:text/plain;charset=utf-8,` |
| N3 | **WASM'Ä± JS shim ile taklit** | ana dosya `script_loader_tag` (`:85-91`) | `fetch`/`WebAssembly.instantiate` sarmalanÄ±r; `service.wasm` boÅŸ dÃ¶ner; parÃ§alÄ± yÃ¼kleme **saf JS**'e yeniden yazÄ±lÄ±r |
| N4 | **Telemetri/GTM devre dÄ±ÅŸÄ±** | `main-controller` (Ã§oÄŸu `enqueue_*`) + `google_tag_manager()` | `site_url`/`admin_email` sahte (`localhost.local` / `anonymous@privacy.org`) |
| N5 | **Sahte gÃ¼ncelleyici kaydÄ±** | ana dosya `:81-83` | `ai1wm_updater` seÃ§eneÄŸine sahte sÃ¼rÃ¼m/indirme linki yazÄ±lÄ±r |
| N6 | **Sabit sahte Purchase ID** | `constants.php:85` | `00000000-0000-â€¦` |

> **Not:** "Unlimited Extension" tek baÅŸÄ±na boyut kilidi kaldÄ±rÄ±r; bu pakette ayrÄ±ca **Pro vendor** (`lib/vendor/servmask/pro/`) gÃ¶mÃ¼lÃ¼ â†’ zamanlÄ± yedek + sÄ±fÄ±rlama araÃ§larÄ± da aÃ§Ä±lÄ±yor.

---

## 3. 2.87 â†” 2.88 â€” gerÃ§ek fark (kullanÄ±cÄ± "sadece versiyon" sanÄ±yor)

**SHA-256 karÅŸÄ±laÅŸtÄ±rmasÄ±:** 287 = **73** dosya Â· 288 = **71** dosya Â· **33 dosyada fark** var.

- 288'de **2 dosya YOK:** `lib/view/common/google-tag-manager.php`, `User-Guide.txt`
- **FarklÄ±:** `changelog.txt`, `constants.php`, `functions.php`, ana PHP, `main-controller`, `settings-controller`, `import-upload`, `vendor/servmask/pro/**` (constants, main-controller, schedules, resetÃ—3, schedule-event(s)), `lib/view/**` (settings, export gÃ¶rÃ¼nÃ¼mleri, 8Ã— `.min.js`), `VERSION`

| | 2.87 | 2.88 |
|---|---|---|
| changelog en Ã¼st | `2.87 Improved output escapingâ€¦` | `2.86 Added --exclude-filesâ€¦` |
| GTM dosyasÄ± | var | **kaldÄ±rÄ±lmÄ±ÅŸ** |
| NÃ¶tralizasyon izleri | daha az | **daha Ã§ok** (`MODIFIED:` yorumlarÄ± yoÄŸun) |

> **SonuÃ§:** Ä°ki sÃ¼rÃ¼m **iÅŸlevsel olarak aynÄ±** (boyut kilidi + Pro), fark **Ã§oÄŸunlukla kÄ±rma/temizleme** ve **view/JS Ã§Ä±ktÄ± kaÃ§Ä±ÅŸÄ±** dÃ¼zeltmeleri. KullanÄ±cÄ±nÄ±n "sadece versiyon farkÄ±" gÃ¶zlemi **tam doÄŸru deÄŸil** â€” ama **davranÄ±ÅŸ farkÄ± yok**.

---

## 4. Mimari (orijinal Ã¼rÃ¼n)

```
all-in-one-wp-migration-unlimited-extension.php   (header + hook'lar + wasm shim)
constants.php                                     (yollar, sÃ¼rÃ¼m, sabitler)
functions.php                                     (retention path, base eklenti kontrol/kurulum)
loader.php                                        (include sÄ±rasÄ±)
lib/
â”œâ”€ controller/  main-controller (menÃ¼+asset) Â· settings-controller
â”œâ”€ model/       settings Â· export-retention Â· import-{upload,database,settings}
â”œâ”€ vendor/servmask/
â”‚  â”œâ”€ command/  WP-CLI (ai1wm backup)
â”‚  â””â”€ pro/      ai1wmve (zamanlÄ± yedek Â· sÄ±fÄ±rlama Â· export/import gÃ¶rÃ¼nÃ¼mleri Â· util sorter)
â””â”€ view/        assets (min.js/css) + settings/export/import/schedules/reset ÅŸablonlarÄ±
```

**Base eklentiye baÄŸlanma:** `ai1wm_loaded`, `ai1wm_commands`, `ai1wm_export`/`ai1wm_import` filtreleri (Ã¶ncelik 5/280/290/310).

**Kritik akÄ±ÅŸ (import):**
1. JS parÃ§alÄ± yÃ¼kler (`chunk_size`, gzip `CompressionStream`) â†’ `admin-ajax.php?action=ai1wm_import`
2. `Ai1wmue_Import_Upload::execute` â†’ `ai1wm_copy_gz()` ile hedefe ekler (offset)
3. `â€¦_Settings` â†’ `retention.json`
4. `â€¦_Database` â†’ retention ayarlarÄ±nÄ± geri yÃ¼kler
5. Base eklenti: Ã§Ä±kart â†’ veritabanÄ± â†’ dosyalar â†’ URL deÄŸiÅŸimi

**Format:** `.wpress` (Ã¶zel arÅŸiv).

---

## 5. Neden temiz alternatif?

| Sorun | SonuÃ§ |
|---|---|
| Lisans sahteleme (N1) | **YasadÄ±ÅŸÄ±** (telif/GPL dÄ±ÅŸÄ± ticari kullanÄ±m) |
| Uzak servis manipÃ¼lasyonu (N2/N3) | **KÄ±rÄ±lgan** â€” gÃ¼ncellemede bozulur, gÃ¼venlik aÃ§Ä±ÄŸÄ± riski |
| Telemetri gizleme (N4) | Åeffaf deÄŸil |
| GÃ¼ncelleyici sahteleme (N5) | Sahte gÃ¼ncelleme â†’ **tedarik zinciri riski** |
| Kaynak bÃ¼tÃ¼nlÃ¼ÄŸÃ¼ | Kim imzaladÄ±? **Belirsiz** |

â†’ **Kendi, Ã¶zgÃ¼n, GPL temiz, telemetrisiz** yedekleme eklentimiz yazÄ±ldÄ±: **`doktor-brown-backup`** (Â§6).

---

## 6. â˜… Yeni Ã¼rÃ¼n: Doktor Brown Backup

| Ã–zellik | Durum |
|---|---|
| Tek baÅŸÄ±na Ã§alÄ±ÅŸÄ±r (base eklenti **gerekmez**) | âœ… |
| **Site dÄ±ÅŸa aktarma** (dosyalar + veritabanÄ±) â†’ tek `.dbb` arÅŸivi | âœ… |
| **Ä°Ã§e aktarma** (geri yÃ¼kleme) | âœ… |
| **ParÃ§alÄ± (chunked) export/import** â€” bÃ¼yÃ¼k siteler, timeout yok | âœ… |
| Yedek listesi Â· indir Â· sil | âœ… |
| URL deÄŸiÅŸimi (site taÅŸÄ±ma) | âœ… (siteurl/home) |
| Telemetri / lisans / WASM / uzak servis | âŒ **hiÃ§ yok** |
| WP-CLI | ğŸ”œ (yol haritasÄ±) |

Yol haritasÄ± ve kod: aynÄ± klasÃ¶r (`doktor-brown-backup/`). Kurulum: `readme.txt`.
