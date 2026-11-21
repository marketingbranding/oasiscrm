# Runbook Cutover PRD v3

## Backup utama dan uji pemulihan

Backup utama OASIS memakai backup otomatis Hostinger.

1. Cek frekuensi backup otomatis dan status retensi di hPanel Hostinger.
2. Lakukan satu kali uji restore dari backup Hostinger ke lingkungan/database sementara.
3. Verifikasi tabel utama, jumlah data, login, pembacaan Konsumen, dan pembacaan transaksi.
4. Catat waktu backup, waktu restore, target sementara, hasil validasi, dan operator.

## Backup manual sebelum cutover

Sebelum setiap langkah cutover berikut, jalankan backup manual:

- migrasi role;
- aktivasi feature flag;
- peralihan cabang.

Perintah:

```bash
php artisan oasis:database-backup
```

1. Jalankan perintah dari server aplikasi.
2. Catat nama file dan hasil command.
3. Unduh file backup ke lokasi operator yang aman.
4. Jalankan langkah cutover.
5. Validasi langkah tersebut aman melalui smoke test dan pemeriksaan data.
6. Hapus file backup manual dari server setelah langkah dinyatakan aman.
7. Simpan catatan waktu, operator, file yang diunduh, validasi, dan penghapusan.

Backup manual hanya alat pra-cutover. Backup utama tetap backup otomatis Hostinger.
