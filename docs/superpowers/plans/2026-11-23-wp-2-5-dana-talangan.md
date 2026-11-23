# Rencana WP-2.5 Dana Talangan

Branch: `prd-v3/wp-2.5-dana-talangan`
Base: `prd-v3/fase-2-pemantauan-data`
Urutan: Paket A → Paket B → Paket C. Tiap paket commit dan push untuk review.

## Paket A — Relasi dan backfill aman
- Tambah FK nullable `consumer_application_id` dan `kavling_id` pada `dana_talangans`.
- Tambah relasi Eloquent dan field identitas kuat yang dibutuhkan untuk pencocokan.
- Tambah command backfill `--dry-run` dengan kategori `PASTI`, `KANDIDAT`, `PERLU DITINJAU`, `TIDAK DITEMUKAN` dan alasan per baris.
- Urutan pencocokan: ID Transaksi/PSJB, lalu kavling + nama konsumen dalam proyek sama. Nama saja tidak cukup.
- `--apply` hanya menulis `PASTI`, idempoten, audit hasil; default dry-run.
- Tes BR-42, BR-43, BR-44 untuk relasi, kategori, no-guess, dry-run/apply.
- Changelog migration.
- Verifikasi focused test, Pint, diff check, push.

## Paket B — Pembayaran dan bukti privat
- Tambah `dana_talangan_payments` dengan nominal decimal, tanggal, catatan, verifier, status verifikasi, path bukti privat, soft delete bila sesuai audit.
- Form catat pembayaran: checkbox `Terverifikasi`, default tercentang; pencatat otomatis verifier.
- Service hitung total terverifikasi, belum terverifikasi, sisa, lebih bayar.
- Auto-Lunas hanya saat total terverifikasi ≥ nominal; simpan status manual terakhir; cabut auto-Lunas saat total turun. Lunas manual tidak diubah.
- Edit/hapus scoped oleh pemilik izin manage; activity log before/after.
- Upload opsional jpg/png/pdf maksimal 5 MB ke disk privat; download controller scoped.
- Tes BR-43/44, otorisasi, upload, verifikasi, edit/hapus, auto-Lunas, rollback status, lebih bayar, privasi.
- Changelog migration.
- Focused test, Pint, diff check, push.

## Paket C — Laporan dan rollout per cabang
- Tambah flag `dana_talangan_oasis_source` scoped feature flag default off.
- Bridge pull/push melewati dan mencatat skip untuk baris cabang yang flag aktif; cabang lain tetap berjalan.
- Laporan filter cabang/proyek/periode dengan total terverifikasi dan belum terverifikasi terpisah.
- Ekspor XLSX tanpa NIK/HP/alamat.
- UI status flag hanya untuk operator; agent tidak mengaktifkan.
- Tes BR-42–44, scope, skip per cabang, default off, ekspor privacy.
- Changelog migration.
- Full suite, Pint, diff check, build bila frontend berubah, push.

## Checkpoint
- Setelah tiap paket: focused tests hijau, commit atomik, push branch.
- Sebelum laporan akhir: full `vendor/bin/phpunit`, Pint, `git diff --check`, migrasi/view cache bila relevan.
