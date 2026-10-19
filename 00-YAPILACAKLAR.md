# Doktor Brown Backup â€” YAPILACAKLAR / Bekleyen Ä°ÅŸler

> Bu dosya, ileride unutulmamasÄ± gereken iÅŸleri tutar. TamamlandÄ±kÃ§a `[x]` yapÄ±lÄ±r.
> Son gÃ¼ncelleme: 2026-10-18 Â· `[Doc]`

---

## â³ BEKLEYEN â€” Mail / SMTP kurulumu (canlÄ± site)

> **KullanÄ±cÄ± kararÄ± (2026-10-18):** Mail adresi dÃ¼zeni **sonra** kurulacak. Åimdilik not.

- [ ] **WP Mail SMTP** eklentisini kur (canlÄ±: `dogateknoloji.com.tr`).
- [ ] Bir saÄŸlayÄ±cÄ± baÄŸla: **Google Workspace / Gmail** (en kolay) veya **Brevo / Mailgun / Postmark**.
- [ ] DNS: **SPF + DKIM + DMARC** kayÄ±tlarÄ±nÄ± ekle (inbox garantisi iÃ§in ÅŸart).
- [ ] GÃ¶nderen adresi: `no-reply@dogateknoloji.com.tr` (gmail'den gÃ¶ndermek spam riskini artÄ±rÄ±r).
- [ ] **Test maili** gÃ¶nder (spam klasÃ¶rÃ¼nÃ¼ de kontrol et).
- [ ] **GÃ¶Ã§ sonrasÄ± hatÄ±rlatma:** DB taÅŸÄ±ndÄ±ysa SMTP ayarlarÄ±/kimlikleri yeniden girilmeli; taÅŸÄ±madan sonra **ilk iÅŸ test maili**.

**Mevcut durum (Ã¶lÃ§Ã¼ldÃ¼, 2026-10-18 Â· doga):**
- Aktif SMTP eklentisi **yok** Â· `sendmail_path` boÅŸ Â· PHP `SMTP=localhost:25` â†’ **PHP `mail()`** kullanÄ±lÄ±yor.
- `admin_email = akamaci@gmail.com`.
- âš ï¸ Windows/XAMPP `mail()` **gÃ¼venilmez** â€” bir mailin gelmesi dÃ¼zenin kurulu olduÄŸunu gÃ¶stermez.

---

## â„¹ï¸ KARAR â€” LoginPress opt-in (wpbrigade.com)

- **Karar:** Risk yok ise **wpbrigade.com Ã¼zerinden kullanÄ±lacak** (LoginPress pazarlama listesi).
- **Risk deÄŸerlendirmesi:** Teknik/gÃ¼venlik riski **yok** â€” yalnÄ±zca `akamaci@gmail.com` adresini
  LoginPress bÃ¼lten/teklif listesine ekler; siteye, veriye veya eriÅŸime dokunmaz. **Tek tÄ±kla Ã§Ä±kÄ±labilir.**
- [ ] Ä°stenirse LoginPress â†’ Settings'ten opt-in **kapatÄ±labilir**.
- [ ] Ä°stenmezse onay maili **silinebilir** (hiÃ§bir ÅŸey olmaz).

---

## ğŸ”œ PROGRAM â€” sÄ±radaki geliÅŸtirmeler (Doktor Brown Backup)

- [x] `.dbb` export (dosyalar + DB, parÃ§alÄ±)
- [x] `.dbb` import + geri yÃ¼kleme
- [x] **`.wpress` okuma/import** (All-in-One WP Migration formatÄ±)
- [x] **Otomatik alan adÄ± deÄŸiÅŸtirme** (serileÅŸtirme-gÃ¼venli) â€” `localhost/doga â†’ www.dogateknoloji.com.tr`
- [x] Yedek listesi Â· indir Â· sil Â· **yedekten geri yÃ¼kle**
- [ ] `.wpress` dosyalarÄ±nÄ± da yedek klasÃ¶rÃ¼nde listele (bÄ±rak-Ã§alÄ±ÅŸtÄ±r)
- [ ] Tam **uÃ§tan uca `.wpress` geri yÃ¼kleme** testi (doga Ã¼zerinde, yedekle-gÃ¼venli)
- [x] **WP-CLI** komutlarÄ± (`wp dbb export` Â· `backups` Â· `restore` Â· `delete`) â€” âœ… test edildi
- [ ] **ZamanlÄ± yedek** (cron) + saklama sayÄ±sÄ±
- [ ] Ä°lerleme Ã§ubuÄŸunda **hÄ±z/ETA** gÃ¶sterimi
- [ ] Ã‡ok parÃ§alÄ± bÃ¼yÃ¼k dosya yÃ¼klemede **yeniden deneme (resume)** iyileÅŸtirmesi

---

## ğŸ§ª TEST DURUMU (Ã¶lÃ§Ã¼ldÃ¼)

| Test | SonuÃ§ |
|---|---|
| PHP lint (tÃ¼m dosyalar) | âœ… temiz |
| `tests/smoke.php` | âœ… **28/28** |
| doga `.dbb` export | âœ… 11.825 dosya + 55 tablo â†’ 112 MB / 90 sn |
| doga `.dbb` round-trip import | âœ… tamamlandÄ± (Wordfence log tablosu uyarÄ±larÄ± kritik deÄŸil) |
| `.wpress` okuyucu (gerÃ§ek dosya) | âœ… 2.217 Ã¶ge, package.json â†’ `http://localhost/Dukkan` |
| **`.wpress` DB geri yÃ¼kleme (scratch DB)** | âœ… 2 MB SQL â†’ 13 tablo, 293 ifade |
| **Otomatik alan adÄ± (uÃ§tan uca)** | âœ… `http://localhost/Dukkan` â†’ `https://www.dogateknoloji.com.tr` |
| Otomatik alan adÄ± (serileÅŸtirme) | âœ… serileÅŸtirme-gÃ¼venli deÄŸiÅŸtirme doÄŸrulandÄ± |

---

## Ä°lgili
- [[00-ANALIZ]] Â· [[doktor-brown-backup/readme]]
