# Runbook Rilis Fase 1+2 OASIS CRM

Panduan operator. Agent tidak menjalankan cutover produksi.

## Sebelum rilis

1. Jadwalkan maintenance window, rollback owner, dan kontak pemilik produk.
2. Cek `.env` server: `OASIS_NEW_WORKSPACE_ENABLED=true` sebelum cabang pertama masuk status `paralel`. Jalankan `php artisan optimize:clear` setelah perubahan environment.
3. Catat flag dan nilai default. Semua default berikut tetap mati; operator mengaktifkan setelah gerbang lulus:
   - `org_hierarchical_monitoring=false`
   - `lead_stages_v3=false`
   - `transaction_events_v3=false`
   - `dana_talangan_primary_source=false`
4. Backup database penuh, storage bukti, `.env` terenkripsi, dan konfigurasi web server. Uji restore pada lingkungan terpisah. Dump DB produksi tidak boleh dibagikan atau di-commit; hapus setelah gladi.
5. Pastikan branch deploy bersih, migrasi direview, queue/worker siap, dan scheduler dipanggil tiap menit.

## Gladi dry-run pada salinan DB produksi

Jalankan berurutan. Ganti `{cabang}` dengan ID atau kode cabang yang sedang diperiksa.

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

6. Tinjau seluruh output: paritas, assignment kavling, permission, konflik penautan, status migrasi, dan kasus perlu tinjau. Jangan lanjut jika hasil tidak jelas.

## Rilis

1. Aktifkan maintenance sesuai prosedur hosting.
2. Deploy commit yang disetujui.
3. Jalankan `php artisan migrate --force`.
4. Jalankan `php artisan optimize:clear`, lalu cache config/route/view sesuai prosedur hosting.
5. Restart worker queue dan pastikan scheduler berjalan tiap menit.
6. Biarkan flag tetap mati sampai smoke test dan approval pemilik produk selesai.

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

1. Operator memastikan laporan paritas bersih dan freshness memenuhi Pengaturan.
2. Operator mengubah status cabang berurutan dengan alasan dan audit.
3. Operator memantau mismatch, error sinkron, dan input tertolak.
4. Operator menghentikan sinkron Sheet hanya setelah cabang lulus gerbang dan disetujui pemilik produk.
5. Pemetaan NUP lama, konflik, dan pengecualian manual wajib tercatat; jangan membuat riwayat palsu.
