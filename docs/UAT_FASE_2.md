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

URL: `/consumer-workspace/{id}`, `/consumer-process/{process}`

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

## Data dummy DB lokal pemilik

Skrip seed tidak disimpan di repo. Simpan sementara di `C:\Users\FAIZAL\AppData\Local\Temp\uat_fase2_seed.php`, atau lokasi temp lokal lain. Skrip hanya boleh dijalankan terhadap DB lokal pemilik.

Set password akun UAT lewat environment proses. Jangan menulis password ke skrip, shell history, laporan, atau chat:

```powershell
$env:UAT_PASSWORD='isi-sementara-di-shell-lokal'
php artisan tinker --execute="require 'C:/Users/FAIZAL/AppData/Local/Temp/uat_fase2_seed.php';"
```

Perintah seed memakai DB aktif aplikasi (`database/database.sqlite` pada setup lokal saat ini), bukan DB PHPUnit. Skrip menolak jalan jika `UAT_PASSWORD` kosong, membuat semua nama/nomor/HP berprefix `UAT-`, mencetak ID record dan URL langsung, serta tidak mengubah record tanpa prefix tersebut.

Setelah UAT, bersihkan hanya data UAT:

```powershell
$env:UAT_MODE='cleanup'
php artisan tinker --execute="require 'C:/Users/FAIZAL/AppData/Local/Temp/uat_fase2_seed.php';"
Remove-Item Env:UAT_MODE
Remove-Item Env:UAT_PASSWORD
Remove-Item 'C:\Users\FAIZAL\AppData\Local\Temp\uat_fase2_seed.php'
```

Simpan output seed secara lokal. Output memuat ID record dan URL, tetapi tidak memuat password. Jangan commit skrip, output, dump, credential, workbook, atau data dummy. Jangan menjalankan `migrate:fresh`, `db:wipe`, `RefreshDatabase`, atau `php artisan test` untuk membuat data browser; perintah test memakai DB test terpisah dan di-refresh.

### ID record dan URL hasil seed

Tempel output seed di bawah tabel ini setelah seed lokal berhasil. Jangan tempel password atau data produksi.

| Alur | ID record dummy | URL langsung |
|---|---:|---|
| Alih Lead / antrean tanpa pemilik | `unownedOne`, `unownedTwo` | `/workspace/lead`, `/buku-saku-sales/leads/unowned-queue` |
| Akses sementara | `grantee`, `grant` | `/admin/izin-akses-khusus` |
| Penanda perhatian | `attentionLead` | `/workspace/lead`, `/team-history` |
| Status migrasi | `branch` | `/admin/status-migrasi-cabang` |
| Paritas | `branch` | `/admin/laporan-paritas/{branch}` |
| Rekonsiliasi | `reconciliation` | `/admin/migrations/marison/reconciliation/{reconciliation}` |
| Dana Talangan | `talanganFull`, `talanganPartial` | `/dana-talangan`, `/dana-talangan/report` |
| Transaksi dengan kendala | `application`, `issue` | `/consumer-workspace/{application}`, `/consumer-process/proses-bank` |
| NUP | `waitingNup`, `convertedNup`, `convertedApp` | `/nups`, `/consumer-workspace/{convertedApp}` |
| Penautan Sheet dan Data Lama | `sheetRows` | `/consumer-workspace`, `/consumer-workspace/link-subject/sheet/{sheetRows[0]}` |
| Konflik penautan | `sourceDecision`, `targetDecision` | `/consumer-workspace/lead-link/conflicts` |

Ganti placeholder `{id}` dengan ID yang dicetak skrip. URL kanonis tanpa trailing slash. Jika route lokal berbeda, gunakan route yang tercetak/terdaftar oleh `php artisan route:list`; jangan mengarang URL.
