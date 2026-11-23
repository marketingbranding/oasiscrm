# Runbook Sumber Utama Dana Talangan per Cabang

Flag: `dana_talangan_primary_source`

Default: `off`. Jangan aktifkan global sebelum setiap cabang lulus backfill dan rekonsiliasi.

## Aktivasi cabang

1. Jalankan backfill Dana Talangan untuk cabang target.
2. Jalankan bridge dalam mode dry-run.
3. Periksa laporan rekonsiliasi. Selesaikan semua konflik, UUID ganda, baris tidak dikenal, dan data nominal/status yang berbeda.
4. Cocokkan total nominal, pembayaran terverifikasi, pembayaran belum terverifikasi, sisa, dan lebih bayar dengan sumber lama.
5. Simpan bukti persetujuan pemilik cabang.
6. Buka Pengaturan → Feature flag.
7. Pilih `Sumber utama Dana Talangan per cabang`.
8. Pilih mode `branches`, pilih cabang yang sudah lulus, lalu simpan.
9. Jalankan dry-run bridge ulang. Pastikan baris cabang aktif tercatat sebagai `skipped_primary_source` pada tarik dan tulis.
10. Pantau sinkronisasi berikutnya dan laporan Dana Talangan.

## Rollback flag

Ubah mode menjadi `off` bila rekonsiliasi menemukan masalah. Jangan hapus data OASIS atau sheet. Setelah perbaikan, ulangi backfill, rekonsiliasi, dan persetujuan.

## Batasan

Flag hanya memengaruhi cabang yang dipilih. Cabang lain tetap memakai bridge normal. Operator tidak boleh mengaktifkan flag sebelum langkah backfill dan rekonsiliasi selesai.
