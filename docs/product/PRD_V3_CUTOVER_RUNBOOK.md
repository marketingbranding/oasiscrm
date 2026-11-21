# Runbook Operator Cutover Fase 1 PRD v3

Runbook ini operator-only. Agent tidak menjalankan cutover produksi, backfill produksi, aktivasi flag, atau restore.

## A. Sebelum deploy

### A1. Backup manual

Perintah:

```bash
php artisan oasis:database-backup
```

Hasil: file backup terkompresi tercatat, dapat diunduh, dan checksum tersimpan.

Go: file dapat diunduh dan dibuka operator di lokasi aman.
No-go: command gagal, file tidak lengkap, checksum berubah, atau Hostinger backup utama belum tersedia.

Mundur: jangan lanjut deploy; gunakan backup Hostinger atau file manual setelah validasi integritas.

Unduh file sebelum langkah berikut. Hapus file dari server setelah langkah aman tervalidasi. Backup utama tetap Hostinger.

### A2. Audit holder Kavling

Jalankan pada salinan DB produksi:

```bash
php artisan consumer:kavling-holder-audit
```

Hasil: duplikat holder aktif = `0`.

Go: tidak ada duplikat.
No-go: satu atau lebih duplikat.
Mundur: hentikan deploy; rapikan data pada salinan dan produksi melalui prosedur koreksi terotorisasi, lalu ulangi audit.

### A3. Dry-run migrasi role

```bash
php artisan oasis:roles-v3-migrate --dry-run
```

Hasil: selisih role/atribut yang telah ditinjau dan disetujui; untuk cutover bersih, selisih harus kosong.

Go: laporan selisih kosong.
No-go: selisih tidak kosong atau kandidat tidak dikenal.
Mundur: jangan jalankan migrasi role; koreksi mapping/data, backup ulang, ulangi dry-run.

### A4. Dry-run tahap Lead

```bash
php artisan sales-lead:stage-v3-backfill --dry-run
```

Hasil: proposal dan kasus perlu tinjau dapat dijelaskan; tidak ada write.

Go: kasus perlu tinjau = `0` atau seluruh kasus memiliki keputusan operator tertulis.
No-go: status tidak dikenal, downgrade, atau kasus belum diputuskan.
Mundur: jangan aktifkan `lead_stages_v3`; simpan output dan perbaiki data sumber.

### A5. Dry-run status transaksi

```bash
php artisan consumer:transaction-status-v3-backfill --dry-run
```

Hasil: semua status legacy terpetakan; `needs_review` dan `CLOSED` ditinjau.

Go: tidak ada nilai status tak dikenal dan semua `needs_review` disetujui.
No-go: ada status tak dikenal atau mapping belum disetujui.
Mundur: jangan aktifkan `transaction_events_v3`; koreksi mapping dan ulangi dry-run.

### A6. Dry-run graf organisasi dan parity

```bash
php artisan organization:backfill --dry-run
php artisan organization:parity
php artisan organization:cutover-health
```

Hasil: laporan paritas bersih, integrity/shadow gate lulus, dan production read source masih `legacy`.

Go: parity bersih, tidak ada siklus, assignment lengkap, dan laporan disimpan.
No-go: parity berbeda, siklus, assignment hilang, atau health gate gagal.
Mundur: jangan aktifkan mode graf kanonis atau pemantauan berjenjang.

## B. Rilis Fase 1

### B1. Merge dan deploy

```bash
git switch main
git merge --no-ff prd-v3/fase-1-fondasi -m "merge: rilis fondasi Fase 1 PRD v3"
git push origin main
```

Deploy artifact commit merge ke server.

Hasil: server menjalankan commit merge; semua flag tetap mati.

Go: health check aplikasi lulus dan commit server sesuai commit rilis.
No-go: deploy gagal, migrasi gagal, atau commit berbeda.
Mundur: hentikan aktivasi; rollback artifact ke commit sebelumnya. Restore DB hanya jika migrasi/data sudah mengubah state dan rollback aplikasi tidak cukup.

### B2. Migrasi dan cache

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan view:cache
```

Hasil: seluruh migrasi selesai; cache konfigurasi/rute lama hilang; view cache berhasil.

Go: command exit `0`, tidak ada exception, dan changelog D32 tercatat.
No-go: migrasi gagal atau schema tidak sesuai.
Mundur: jangan menghapus migrasi produksi; hentikan rollout dan ikuti prosedur restore setelah menilai perubahan schema.

### B3. Smoke test

Uji login, dashboard, cabang/proyek, input SAS di scope sendiri, URL rekonsiliasi dengan role non-rekonsiliasi, dan pembacaan transaksi.

Go: semua smoke test lulus tanpa error log baru.
No-go: satu alur utama gagal atau akses lintas scope terbuka.
Mundur: matikan flag yang terkait; rollback artifact. Restore backup hanya bila data korup/hilang atau migrasi tidak dapat dipulihkan aman.

## C. Aktivasi setelah deploy

Setiap langkah wajib: backup manual A1, ubah satu flag/mode, smoke test, jeda pengamatan minimal 24 jam, tinjau log/metric, lalu putuskan lanjut atau mundur.

### C1. Migrasi role

```bash
php artisan oasis:roles-v3-migrate
```

Go: laporan sukses, parity user sesuai dry-run, tidak ada user kehilangan akses.
No-go: delta tak terduga atau akses berubah.
Mundur: jangan menghapus role lama; restore DB bila pemetaan sudah menulis state salah.

### C2. Mode graf kanonis

Prasyarat: parity bersih dan observasi legacy minimal 7 hari.

Aktifkan mode graf kanonis melalui Pengaturan sesuai prosedur admin. Jangan mengubah flag lain.

Go: health gate dan parity tetap bersih selama jeda.
No-go: mismatch scope, siklus, atau akses lintas cabang.
Mundur: matikan mode graf kanonis; kembali ke read source legacy.

### C3. Pemantauan hierarkis

Aktifkan `org_hierarchical_monitoring` melalui Pengaturan.

Go: supervisor/manager melihat bawahan sesuai graf; direct URL di luar scope tetap 403.
No-go: scope melebar atau data hilang.
Mundur: matikan `org_hierarchical_monitoring`.

### C4. Opsi Gugur di dropdown Sheet

Tambahkan opsi `Gugur` pada dropdown tab `lead` sesuai kontrak Sheet. Jangan mengubah ID sinkronisasi.

Go: opsi tampil dan pull/push kompatibel; lead UTJ tidak turun tahap.
No-go: tab salah, status lama berubah, atau sinkronisasi gagal.
Mundur: kembalikan dropdown Sheet; flag `lead_stages_v3` tetap mati.

### C5. Tahap Lead v3 satu cabang uji

Aktifkan `lead_stages_v3` untuk satu cabang uji melalui Pengaturan.

Go: lead baru → UTJ/Gugur, lock setelah terminal, dan sinkronisasi lulus.
No-go: downgrade, bypass lock, atau status asing.
Mundur: matikan `lead_stages_v3`; jangan hapus data/riwayat.

### C6. Event transaksi satu cabang uji

Aktifkan `transaction_events_v3` untuk satu cabang uji melalui Pengaturan.

Go: status/event audit, NUP, kavling holder, dan scope lulus.
No-go: duplikat holder, event tanpa audit, atau transaksi berubah ID.
Mundur: matikan `transaction_events_v3`; restore hanya bila state transaksi tidak dapat dikoreksi aman.

## D. Rollback umum

1. Hentikan langkah berikutnya.
2. Matikan flag terkait: `org_hierarchical_monitoring`, `lead_stages_v3`, atau `transaction_events_v3`.
3. Jalankan smoke test legacy dan simpan log.
4. Jika masalah hanya perilaku flag, jangan restore DB.
5. Restore backup bila terjadi korupsi/hilang data, migrasi gagal sebagian, constraint rusak, atau koreksi transaksional tidak aman.
6. Setelah restore, jalankan `php artisan migrate:status`, smoke test, dan audit scope.
7. Catat operator, waktu, commit, flag sebelum/sesudah, backup yang dipakai, hasil validasi, dan keputusan go/no-go.

Backup Hostinger adalah sumber pemulihan bencana utama. Backup manual OASIS adalah bukti pra-cutover: unduh sebelum cutover, validasi sesudahnya, lalu hapus dari server setelah aman.
