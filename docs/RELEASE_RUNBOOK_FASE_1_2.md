# Runbook Rilis Fase 1+2 OASIS CRM

Panduan operator. Agent tidak menjalankan cutover produksi.

## Sebelum rilis

1. Jadwalkan maintenance window minimal 15 menit, rollback owner, dan kontak pemilik produk. Acuan gladi: `migrate` 319 detik. Sebelum migrasi jalankan `php artisan down`; sesudah migrasi dan smoke test lulus jalankan `php artisan up`.
2. Tepat sebelum migrasi, jalankan query duplikat kavling read-only di produksi melalui phpMyAdmin. Data produksi dapat berubah setelah dump gladi dibuat.

```sql
SELECT kavling_id, COUNT(*) AS jumlah_assignment
FROM consumer_kavling_assignments
WHERE assignment_status IN ('active', 'sold')
  AND released_at IS NULL
GROUP BY kavling_id
HAVING COUNT(*) > 1
ORDER BY kavling_id;
```

3. Cek `.env` server: `OASIS_NEW_WORKSPACE_ENABLED=true` sebelum cabang pertama masuk status `paralel`. Jalankan `php artisan optimize:clear` setelah perubahan environment.
4. Catat flag dan nilai default. Semua default berikut tetap mati; operator mengaktifkan setelah gerbang lulus:
   - `org_hierarchical_monitoring=false`
   - `lead_stages_v3=false`
   - `transaction_events_v3=false`
   - `dana_talangan_primary_source=false`
4. Backup database penuh, storage bukti, `.env` terenkripsi, dan konfigurasi web server. Uji restore pada lingkungan terpisah. Dump DB produksi tidak boleh dibagikan atau di-commit; hapus setelah gladi.
5. Pastikan branch deploy bersih, migrasi direview, queue/worker siap, dan scheduler dipanggil tiap menit.

## Gladi migrasi dan dry-run pada salinan DB produksi

Sebelum gladi: wajib jalankan `php artisan migrate:fresh` pada DB MySQL kosong memakai engine dan versi mayor yang sama dengan produksi. Perintah ini harus lulus dari nol; gunakan environment khusus yang tidak di-commit. Jika gagal, perbaiki migrasi dan ulangi sampai lulus. Setelah itu jalankan gladi salinan DB berikut.

Jalankan berurutan. Ganti `{cabang}` dengan ID atau kode cabang yang sedang diperiksa. Jangan menjalankan langkah gladi pada produksi.

1. Restore dump produksi ke DB salinan. Arahkan `.env` salinan ke DB tersebut. Pastikan kredensial, URL, queue, mail, Google Sheets, gateway WhatsApp, AI, dan Web Push memakai endpoint aman untuk gladi atau mock; jangan mengarah ke layanan produksi.
2. Jalankan query read-only berikut untuk mencari duplikat assignment kavling aktif/terjual:

```sql
SELECT
    kavling_id,
    COUNT(*) AS jumlah_assignment
FROM consumer_kavling_assignments
WHERE assignment_status IN ('active', 'sold')
  AND released_at IS NULL
GROUP BY kavling_id
HAVING COUNT(*) > 1
ORDER BY kavling_id;
```

   Jika hasil query berisi baris: **STOP**. Selesaikan duplikat melalui prosedur data yang disetujui; jangan mengubah migrasi untuk melewati pemeriksaan.
3. Simpan output `php artisan migrate --pretend`.
4. Jalankan `php artisan migrate` hanya pada DB salinan. Catat durasi tiap migrasi dan setiap error. Secara khusus catat jumlah baris serta waktu yang diproses oleh `2026_11_21_120000_backfill_sales_lead_stage_v3`; migrasi ini mengisi `lead_stage` NULL walau flag `lead_stages_v3` mati.
5. Setelah migrasi salinan selesai, jalankan perintah pratinjau berikut:

```powershell
php artisan organization:backfill --dry-run
php artisan sales-lead:stage-v3-backfill --dry-run
php artisan consumer:transaction-status-v3-backfill --dry-run
php artisan consumer-kavling:backfill preview --branch={cabang}
php artisan consumer:kavling-holder-audit
php artisan dana-talangan:backfill-relations
php artisan consumer-lead-link:carry-over --dry-run
php artisan consumer:parity-report {cabang} --xlsx
```

`consumer:transaction-status-v3-backfill` wajib memakai `--dry-run`. Kode command menolak mode tanpa opsi ini dan tidak menyediakan mode tulis. Jika versi deploy berbeda dan `--dry-run` tidak tersedia, hentikan gladi: command harus direview dulu; jangan menjalankan command yang menulis data.

`consumer-kavling:backfill preview` dan `dana-talangan:backfill-relations` tanpa `--apply` hanya pratinjau. Jangan memakai `execute`, `--apply`, `--confirm`, `--write`, atau opsi mutasi pada gladi.

6. Tinjau seluruh output: paritas, assignment kavling, permission, konflik penautan, status migrasi, jumlah backfill, durasi, dan kasus perlu tinjau. Jangan lanjut jika hasil tidak jelas.

## Rilis

1. Aktifkan maintenance sesuai prosedur hosting.
2. Deploy commit yang disetujui.
3. Jalankan `php artisan migrate --force`.
4. Jalankan `php artisan optimize:clear`, lalu cache config/route/view sesuai prosedur hosting.
5. Restart worker queue dan pastikan scheduler berjalan tiap menit.
6. Biarkan flag tetap mati sampai smoke test dan approval pemilik produk selesai.

Jika migrate produksi gagal di tengah: hentikan rilis dan jangan mengulangi migrate secara membabi buta; jangan rollback migrasi yang tidak reversibel atau migrasi yang sudah memiliki data produksi; pertahankan flag mati; simpan error, migrasi terakhir, dan durasi; hubungi rollback owner serta pemilik produk; restore backup tervalidasi bila ada kerusakan data; lalu reconcile queue, audit log, storage bukti, dan status sinkronisasi sebelum keputusan pemulihan.

## Pemeriksaan pasca-rilis

1. Buka login, dashboard, Sales, Dana Talangan, migrasi, Riwayat Tim, NUP, dan Database Konsumen.
2. Periksa log aplikasi, failed jobs, queue, scheduler, dan HTTP 5xx.
3. Smoke test scope cabang, form Lead, NUP, entry konsumen, proses transaksi, Dana Talangan, ekspor, dan konflik penautan.
4. Jalankan ulang `php artisan consumer:parity-report {cabang} --xlsx`; simpan laporan dan hash deploy.
5. Operator mengaktifkan flag/sumber utama per cabang hanya setelah approval pemilik produk dan bukti dry-run.

## Rollback

1. Hentikan cutover dan pertahankan flag mati.
2. Jika kode gagal, rollback release ke commit sebelumnya melalui prosedur hosting.
3. Jangan rollback migrasi lifecycle, permission, undangan, komentar, pengeluaran, impor, atau tabel audit setelah data produksi masuk.
4. Restore DB hanya jika ada kerusakan data dan persetujuan pemilik produk; gunakan backup tervalidasi.
5. Reconcile queue, audit log, storage bukti, dan status sinkronisasi.
6. Catat insiden, dampak, keputusan, dan perbaikan sebelum membuka maintenance.

## Cutover per cabang

Sebelum cutover cabang, selesaikan atau terima secara tertulis: 39 konflik kavling, 245 transaksi perlu tinjau, 20 Dana Talangan tidak ditemukan, dan unresolved struktur organisasi. Jalankan ulang seluruh pemeriksaan pada data terbaru; hasil gladi hanya acuan.

1. Operator memastikan laporan paritas bersih dan freshness memenuhi Pengaturan.
2. Operator mengubah status cabang berurutan dengan alasan dan audit.
3. Operator memantau mismatch, error sinkron, dan input tertolak.
4. Operator menghentikan sinkron Sheet hanya setelah cabang lulus gerbang dan disetujui pemilik produk.
5. Pemetaan NUP lama, konflik, dan pengecualian manual wajib tercatat; jangan membuat riwayat palsu.
