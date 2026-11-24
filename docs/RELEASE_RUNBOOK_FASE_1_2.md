# Runbook Rilis Fase 1+2 OASIS CRM

Dokumen ini panduan operator. Agent tidak menjalankan cutover produksi.

## Sebelum rilis

1. Jadwalkan maintenance window dan catat rollback owner.
2. Backup database penuh, storage bukti, `.env` terenkripsi, dan konfigurasi web server. Uji restore backup pada lingkungan terpisah.
3. Pastikan branch deploy bersih, migrasi sudah direview, dan queue/scheduler siap.
4. Jalankan dry-run pada salinan DB produksi:
   - `php artisan organization:backfill --dry-run`
   - `php artisan consumer:kavling-holder-audit`
   - pemeriksaan paritas Konsumen per cabang;
   - audit konflik keputusan penautan Lead.
5. Tinjau semua hasil dry-run. Jangan lanjut jika paritas, assignment kavling, permission, atau konflik belum jelas.

## Rilis

1. Aktifkan maintenance sesuai prosedur hosting.
2. Deploy kode dan dependency dari commit yang disetujui.
3. Jalankan `php artisan migrate --force`.
4. Jalankan `php artisan optimize:clear` lalu cache config/route/view sesuai prosedur hosting.
5. Restart worker queue dan pastikan scheduler dipanggil tiap menit.
6. Biarkan feature flag tetap mati. Aktivasi hanya per cabang setelah pemeriksaan operator.

## Pemeriksaan pasca-rilis

1. Buka login, dashboard, modul Sales, Dana Talangan, migrasi, dan Database Konsumen.
2. Periksa log aplikasi, failed jobs, queue, scheduler, dan error HTTP 5xx.
3. Jalankan smoke test: akses scope cabang, form Lead, NUP, entry konsumen, proses transaksi, Dana Talangan, ekspor, dan halaman konflik penautan.
4. Jalankan pemeriksaan paritas ulang. Simpan laporan dan hash deploy.
5. Jika cabang akan masuk mode paralel/OASIS penuh, operator aktifkan flag/sumber utama setelah approval pemilik produk dan bukti dry-run.

## Rollback

1. Hentikan cutover dan pertahankan flag mati.
2. Jika kode gagal, rollback release ke commit sebelumnya melalui prosedur hosting.
3. Jangan rollback migrasi lifecycle, permission, undangan, komentar, pengeluaran, impor, atau tabel audit setelah data produksi masuk.
4. Restore DB hanya jika ada kerusakan data dan setelah persetujuan pemilik produk; gunakan backup tervalidasi.
5. Reconcile ulang queue, audit log, storage bukti, dan status sinkronisasi setelah rollback.
6. Catat insiden, dampak, keputusan, dan rencana perbaikan sebelum membuka maintenance.

## Cutover per cabang

1. Operator memastikan laporan paritas bersih dan freshness memenuhi Pengaturan.
2. Operator mengubah status cabang berurutan dengan alasan dan audit.
3. Operator memantau error sinkron, mismatch, dan input tertolak.
4. Operator menghentikan sinkron Sheet hanya setelah cabang lulus gerbang dan disetujui pemilik produk.
5. Pemetaan NUP lama, konflik, dan pengecualian manual wajib tercatat; tidak boleh dibuat sebagai riwayat palsu.
