# Checklist UAT Fase 2

Host: `http://127.0.0.1:8000`  
Akun: sesi Super Admin pemilik produk  
Data: dummy lokal saja; jangan memakai workbook produksi.

## Persiapan

- [ ] Cek `.env`: `OASIS_NEW_WORKSPACE_ENABLED=true`.
- [ ] Buat DB/salinan lokal terisolasi.
- [ ] Jalankan seed dummy lokal sesuai kebutuhan. Jangan commit seeder, dump, credential, workbook, atau data dummy.
- [ ] Bersihkan data dummy setelah UAT.
- [ ] Catat console error, HTTP 4xx/5xx, dan hasil DB setiap langkah.

## Alih Lead dan antrean tanpa pemilik

URL: `/workspace/lead`, `/buku-saku-sales/leads/unowned-queue`

1. [ ] Buat Lead dummy tanpa Sales. Hasil: Lead masuk antrean tanpa pemilik.
2. [ ] Buka antrean. Hasil: Lead terlihat sesuai cabang/scope.
3. [ ] Pilih Sales tujuan dan submit alih Lead. Hasil: owner berubah, audit alih tercatat, Lead hilang dari antrean.
4. [ ] Buka URL langsung di cabang luar scope. Hasil: 403 atau data kosong sesuai policy.

## Izin akses sementara

URL: `/admin/izin-akses-khusus`

1. [ ] Isi grantee, modul, expiry, dan alasan. Submit.
2. [ ] Hasil: grant aktif terlihat, audit tercatat.
3. [ ] Masuk sebagai grantee pada workspace. Hasil: hanya scope grant terlihat.
4. [ ] Cabut grant. Hasil: akses mutasi hilang, audit revoke tercatat.

## Penanda perhatian

URL: `/workspace/lead`, `/team-history`

1. [ ] Buat Lead/agenda dummy yang belum memiliki aktivitas.
2. [ ] Buka daftar monitoring. Hasil: badge perhatian dan ringkasan muncul sesuai aturan.
3. [ ] Tambah aktivitas manusia. Hasil: badge berubah/hilang sesuai sumber aktivitas.
4. [ ] Cek filter tanggal dan scope bawahan. Hasil: data luar scope tidak tampil.

## Status migrasi, paritas, gerbang cutover, override

URL: `/admin/status-migrasi-cabang`, `/admin/migrations/marison/reconciliation`

1. [ ] Jalankan laporan paritas dummy. Hasil: snapshot tersimpan dan status freshness terlihat.
2. [ ] Coba transisi status tidak berurutan. Hasil: ditolak dengan error inline.
3. [ ] Transisi valid dengan alasan. Hasil: status dan audit berubah.
4. [ ] Coba cutover sebelum gerbang paritas. Hasil: ditolak.
5. [ ] Gunakan override pemilik produk dengan alasan. Hasil: override tercatat lengkap dan status berubah.
6. [ ] Buka laporan rekonsiliasi. Hasil: item sesuai cabang/proyek terlihat.

## Dana Talangan: pembayaran, bukti, laporan, ekspor

URL: `/dana-talangan`, `/dana-talangan/create`, `/dana-talangan/report`

1. [ ] Buat Dana Talangan dummy. Hasil: record tampil.
2. [ ] Tambah pembayaran. Hasil: saldo dan status berubah.
3. [ ] Unggah bukti dummy non-produksi. Hasil: bukti tertaut, tipe/ukuran tervalidasi.
4. [ ] Buka laporan. Hasil: total sesuai record dan filter.
5. [ ] Jalankan ekspor. Hasil: file berhasil dibuat tanpa data luar scope.
6. [ ] Hapus/batalkan record dummy. Hasil: audit/soft-delete sesuai aturan.

## Kembali ke NUP dan Selesaikan Kendala

URL: `/consumer-workspace/{id}`, `/konsumen-progress/applications/{id}/kembali-ke-nup`, `/consumer-process/{process}`

1. [ ] Buat transaksi dummy status `LANJUT` dengan kendala.
2. [ ] Selesaikan kendala. Hasil: issue resolved, audit tercatat.
3. [ ] Kembali ke NUP. Hasil: transaksi mundur, NUP tercipta, kavling dilepas sesuai aturan.
4. [ ] Coba ulangi aksi. Hasil: ditolak/idempoten, tidak ada duplikasi.

## Riwayat Tim

URL: `/team-history`

1. [ ] Buka dengan filter user langsung dan tanggal.
2. [ ] Hasil: Lead/agenda bawahan tampil sesuai scope.
3. [ ] Coba rentang lebih dari 92 hari. Hasil: validasi menolak.
4. [ ] Coba user/cabang luar scope. Hasil: data tidak tampil atau 403.

## Penautan Lead: Workspace dan saran

URL: `/consumer-workspace`, `/consumer-workspace/{id}`, `/consumer-workspace/link-subject/{subjectType}/{subjectId}`

1. [ ] Buka subjek aplikasi/NUP/Sheet. Hasil: saran Lead tampil scoped.
2. [ ] Submit keputusan `Tertaut` dengan Lead. Hasil: satu keputusan aktif dan audit.
3. [ ] Submit `Tanpa Lead` dengan alasan. Hasil: keputusan aktif tanpa Lead.
4. [ ] Coba Lead yang sudah aktif pada subjek lain. Hasil: ditolak.

## Bulk Data Lama

URL: `/consumer-workspace/lead-link/bulk-legacy/preview`

1. [ ] Buka pratinjau bulk dengan data dummy.
2. [ ] Hasil: token/pratinjau menampilkan jumlah dan konflik.
3. [ ] Ubah filter atau token. Hasil: eksekusi ditolak.
4. [ ] Eksekusi token asli. Hasil: hanya baris valid berubah, rollback atomik saat error.

## Konflik penautan

URL: `/consumer-workspace/lead-link/conflicts`

1. [ ] Buat dua keputusan sumber/tujuan yang berbeda.
2. [ ] Buka daftar dan detail konflik. Hasil: sumber, tujuan, status, dan audit terlihat.
3. [ ] Pilih keputusan penyelesaian dengan alasan wajib. Hasil: konflik resolved, keputusan final aktif.
4. [ ] Buka konflik di luar scope. Hasil: 403.

## Bukti akhir

| Alur | Desktop 1440x900 | HP 390x844 | DOM | DB | Console | Catatan |
|---|---|---|---|---|---|---|
| Alih Lead | ☐ | ☐ | ☐ | ☐ | ☐ | |
| Akses sementara | ☐ | ☐ | ☐ | ☐ | ☐ | |
| Penanda perhatian | ☐ | ☐ | ☐ | ☐ | ☐ | |
| Migrasi/paritas/cutover | ☐ | ☐ | ☐ | ☐ | ☐ | |
| Dana Talangan | ☐ | ☐ | ☐ | ☐ | ☐ | |
| Kembali NUP/kendala | ☐ | ☐ | ☐ | ☐ | ☐ | |
| Riwayat Tim | ☐ | ☐ | ☐ | ☐ | ☐ | |
| Penautan Workspace | ☐ | ☐ | ☐ | ☐ | ☐ | |
| Bulk Data Lama | ☐ | ☐ | ☐ | ☐ | ☐ | |
| Konflik | ☐ | ☐ | ☐ | ☐ | ☐ | |

## Perintah dummy lokal

Tidak ada seeder baru yang di-commit untuk UAT. Pemilik dapat memakai test DB terisolasi dan factory melalui test terarah:

```powershell
php artisan test --compact tests/Feature/ConsumerLeadLinkDecisionTest.php
php artisan test --compact tests/Feature/ConsumerMigrationReconciliationTest.php
php artisan test --compact tests/Feature/ConsumerV2ProcessTest.php
php artisan test --compact tests/Feature/DanaTalanganTest.php
```

Perintah tersebut membuat data di DB test sesuai konfigurasi PHPUnit, bukan DB produksi. Hapus dump/export hasil gladi setelah review.
