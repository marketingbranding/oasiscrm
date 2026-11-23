# Desain WP-2.5 Dana Talangan

## Tujuan
Memperkuat Dana Talangan sebagai catatan administrasi biasa di OASIS tanpa alur birokratis baru: relasi data yang aman, pembayaran terverifikasi sederhana, laporan scoped, dan rollout sumber utama per cabang.

## Kondisi saat ini
- `DanaTalangan` sudah memiliki CRUD, import, export, scope cabang/proyek, soft delete, activity logging, dan relasi proyek melalui `project_id`.
- Bridge sheet `Talangan` bersifat global, dengan mode push/pull dan reconciliation.
- Migrasi bridge lama pernah mengisi `project_id` hanya bila nama proyek cocok tunggal; WP-2.5 tidak memperluas tebakan itu.
- Belum ada relasi transaksi/kavling, pembayaran, bukti privat, saldo pembayaran, atau laporan pembayaran.

## Keputusan desain
- Flag `dana_talangan_oasis_source` memakai `scoped_feature_flag`, default `off`, rollout `branches` atau `all`, dan `FeatureFlag::enabledForBranch`.
- Bridge global tetap berjalan. Saat pull/push menemukan baris cabang yang flag-nya aktif, baris dilewati dan skip dicatat; cabang lain tetap tersinkron.
- Paket A memakai pencocokan berurutan: ID Transaksi/PSJB, lalu kavling + nama konsumen dalam proyek sama. Nama saja tidak cukup.
- Kategori dry-run: `PASTI`, `KANDIDAT`, `PERLU DITINJAU`, `TIDAK DITEMUKAN`, masing-masing dengan alasan. `--apply` hanya menulis `PASTI`, idempoten, dan diaudit.
- Paket B memakai satu alur pencatatan: checkbox `Terverifikasi` default tercentang; pencatat otomatis menjadi verifier. Pembayaran belum terverifikasi tidak dihitung.
- Edit/hapus pembayaran mengikuti izin kelola Dana Talangan dalam scope dan mencatat sebelum/sesudah. Bukti opsional jpg/png/pdf maksimal 5 MB di disk privat, diunduh lewat controller scoped.
- Status `lunas` otomatis hanya untuk status yang berasal dari pembayaran terverifikasi. Jika total turun, sistem mengembalikan status manual terakhir. Lunas manual tidak disentuh. Lebih bayar tetap sah.
- Paket C laporan dan ekspor hanya memuat field operasional non-PII; total terverifikasi dan belum terverifikasi dipisah.

## Paket
1. **A — Relasi dan backfill**: migration FK nullable, model relations, command dry-run/apply, daftar review, tes BR-42–44.
2. **B — Pembayaran**: tabel/model/service/controller/form, bukti privat, verifier, saldo/status, audit, tes BR-43/44 dan keamanan.
3. **C — Laporan dan rollout**: scoped report/export, flag per cabang, skip bridge per baris, changelog, tes BR-42–44.

## Non-goals
- Tidak mengaktifkan flag produksi.
- Tidak mengganti bridge global untuk cabang yang belum lolos rekonsiliasi.
- Tidak membuat approval berlapis atau workflow keuangan baru.
- Tidak mengirim bukti pembayaran ke AI.
