# Doktor Brown Backup â€” Proje Dizini (Ã¶nce bunu oku)

> **Ne bu?** All-in-One WP Migration Unlimited Extension (kÄ±rÄ±k sÃ¼rÃ¼mler) incelenerek
> yazÄ±lmÄ±ÅŸ **Ã¶zgÃ¼n, temiz** WordPress yedekleme eklentisi.
> OluÅŸturma: 2026-10-18 Â· `[Doc]`

---

## ğŸ“ Dosyalar

| Dosya | Ne iÅŸe yarar | Ne zaman okunur |
|---|---|---|
| **`00-OKU-BENI.md`** (bu) | Proje dizini | En baÅŸta |
| **`00-GELISTIRME-NOTLARI.md`** | Mimari, API, formatlar, test komutlarÄ±, tuzaklar | **Kod yazmadan Ã¶nce** |
| **`00-YAPILACAKLAR.md`** | Bekleyen iÅŸler (mail/SMTP dahil), test durumu | Ä°ÅŸ planlarken |
| **`00-ANALIZ.md`** | Rakip analizi (kÄ±rÄ±k AIO 2.87/2.88), kÄ±rma teknikleri | Referans |
| **`doktor-brown-backup/`** | Eklenti kaynak kodu | GeliÅŸtirirken |
| **`doktor-brown-backup-1.0.0.zip`** | Kurulabilir paket | WP'ye yÃ¼klerken |
| **`tests/smoke.php`** | 28 birim/entegrasyon testi | Her deÄŸiÅŸiklikten sonra |

---

## ğŸš€ HÄ±zlÄ± baÅŸlangÄ±Ã§

**Kurulum:** WP Admin â†’ Eklentiler â†’ Yeni Ekle â†’ `doktor-brown-backup-1.0.0.zip` yÃ¼kle â†’ etkinleÅŸtir.
MenÃ¼: **Dr. Brown Backup** â†’ Yedekler Â· Yeni Yedek Â· Geri YÃ¼kle.

**Test:**
```powershell
& "C:\xampp\php\php.exe" "D:\AI\TASARIM\Projeler\doktor-brown-backup\tests\smoke.php"
```

**Kod deÄŸiÅŸince doga'ya kopyala:** bkz. [[00-GELISTIRME-NOTLARI]] Â§1.

---

## âœ³ï¸ Ã–zet yetenekler

- Site â†’ tek `.dbb` (dosyalar + veritabanÄ±), **parÃ§alÄ±** (timeout yok)
- **`.wpress`** (All-in-One) iÃ§e aktarma
- **Otomatik alan adÄ± deÄŸiÅŸtirme** (serileÅŸtirme-gÃ¼venli) â€” localhost â†’ canlÄ± domain
- Yedek listesi Â· indir Â· sil Â· **yedekten geri yÃ¼kle**
- **WP-CLI**: `wp dbb export | backups | restore | delete`
- Telemetri / lisans / WASM / uzak Ã§aÄŸrÄ± **yok** Â· GPL-2.0

---

## ğŸ”— Ä°lgili (Ã§alÄ±ÅŸma alanÄ±)
- `D:\AI\backup-ALL-IN\` â€” incelenen kÄ±rÄ±k AIO sÃ¼rÃ¼mleri + orijinal zip'ler
- Test sitesi: `C:\xampp\htdocs\doga`
- Test `.wpress`: `C:\xampp\htdocs\_Envato-element-Wpress\`, `D:\AI\...\Downloads\`
