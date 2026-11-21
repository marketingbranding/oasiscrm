# Runbook Cutover PRD v3

## Uji pemulihan backup database

1. Set `OASIS_BACKUP_DISK_ROOT` ke lokasi disk backup terpisah dari disk aplikasi.
2. Set kredensial database dan `OASIS_MYSQLDUMP_BINARY` melalui environment server. Jangan menaruh kredensial di command line, log, atau repository.
3. Jalankan `php artisan oasis:database-backup`.
4. Verifikasi output `.sql.gz`, ukuran file, timestamp, dan log task tanpa membaca atau menyalin secret.
5. Siapkan database sementara dengan kredensial terpisah.
6. Ekstrak backup ke pipe lokal dan restore ke database sementara. Jangan restore langsung ke database produksi untuk uji.
7. Jalankan `php artisan migrate --force` pada database sementara bila backup berasal dari versi sebelum migrasi terbaru.
8. Bandingkan jumlah tabel utama dan jalankan smoke test login, navigasi, pembacaan Konsumen, serta pembacaan transaksi.
9. Catat waktu backup, ukuran, database target sementara, hasil validasi, dan operator. Hapus database sementara setelah bukti pemulihan disimpan.
10. Uji retensi dengan file backup sintetis bertimestamp lama. Pastikan hanya file backup OASIS dalam disk backup yang terhapus.
