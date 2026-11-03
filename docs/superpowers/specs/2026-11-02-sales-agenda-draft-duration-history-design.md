# Perbaikan Draft, Durasi, dan Riwayat Sales Agenda

**Status:** Menunggu persetujuan implementasi
**Tanggal:** 2026-11-02
**Scope:** Lokal saja; tidak mencakup deployment production.

## Intent

Memperbaiki tiga gangguan pada workspace Agenda Sales mobile dan menambahkan akses
ke riwayat agenda pribadi tanpa mengubah model submission struk yang sudah berjalan.

## Goals

- Mengganti kontrol silang preview struk yang terlalu dominan dengan tombol
  `Simpan draft struk`.
- Memastikan tombol tersebut menutup preview dan kembali ke workspace tanpa
  mengirim struk ke Koordinator.
- Membuat durasi custom seperti 180 menit diterima oleh browser dan validasi
  server, tetap mengikuti aturan kelipatan 5 menit dan batas jam kerja.
- Menjamin hanya konteks tanggal yang benar-benar dipilih yang diberi status aktif;
  `Rencana` tidak ikut aktif saat `Hari ini` dipilih.
- Menampilkan tombol `Lihat semua agenda` di bawah daftar/receipt Agenda Sales,
  dengan penanda jumlah agenda pribadi yang belum selesai.
- Menyediakan daftar semua agenda pribadi lintas tanggal melalui route workspace
  yang sama, tetap dengan authorization owner dan pagination yang ada.

## Non-goals

- Tidak membuat tabel atau migration draft struk baru.
- Tidak menyimpan draft ke server, localStorage, atau perangkat lain.
- Tidak mengirim struk atau mengubah status submission saat `Simpan draft struk`
  ditekan.
- Tidak mengubah aturan jam kerja, istirahat, target efektif, ownership,
  permission, evidence, atau lifecycle agenda.
- Tidak mengubah workspace monitoring Koordinator/Supervisor.

## Constraints

- `ContentItem` tetap menjadi sumber data agenda.
- `SalesAgendaReceiptService` tetap menjadi sumber aturan waktu dan submission.
- Route bernama `sales-agendas.index` tetap menjadi entry point Sales.
- UI mengikuti token dan pola receipt/workspace yang sudah ada: kontrol minimal 44px,
  focus state, Escape close, dan tidak mengandalkan hover.
- Perubahan harus diuji melalui feature/render tests dan frontend slot tests.

## Approaches

### 1. Patch minimal pada kontrak yang ada — dipilih

Perbaiki kontrol preview dan state Alpine, gunakan field durasi canonical yang
terpisah dari preset select, lalu tambahkan mode URL `view=all` pada route workspace
yang sama. Mode default tetap menampilkan tanggal terpilih; mode semua menampilkan
agenda pribadi lintas tanggal secara paginated. Pendekatan ini dipilih karena tidak
menambah persistence baru dan paling kecil risikonya terhadap alur submission.

### 2. Draft lokal browser

Menyimpan isi draft di `localStorage`. Ini tidak memerlukan migration, tetapi dapat
meninggalkan data stale, tidak tersedia lintas perangkat, dan belum dibutuhkan oleh
kontrak bisnis saat ini. Tidak dipilih.

### 3. Draft server-side

Menambah persistence draft per Sales/tanggal. Ini memberi pemulihan lintas perangkat,
tetapi membutuhkan schema, lifecycle, authorization, dan aturan konflik baru.
Tidak proporsional untuk kebutuhan close-preview saat ini dan tidak dipilih.

## Design decisions

### Simpan draft struk

Label close preview mobile diganti menjadi `Simpan draft struk`. Aksinya hanya
memanggil `closeReceipt()` dan mengembalikan focus ke tombol pembuka. Tidak ada
request jaringan dan tidak ada perubahan pada `SalesAgendaDaySubmission`.

### Custom duration

Preset 30/60/90/120 tetap menjadi chip. Nilai custom disimpan pada input numerik
yang menjadi sumber durasi saat custom dipilih; nilai tersebut dipakai untuk
menghitung `end_time` dan dikirim sebagai `duration_minutes` canonical. Select preset
tidak lagi menjadi satu-satunya sumber nilai custom. Server tetap memvalidasi format,
kelipatan 5, dan batas waktu melalui alur request/service yang ada.

### Date navigation

Status aktif menggunakan kecocokan tanggal eksplisit untuk Kemarin, Hari ini, dan
tanggal Rencana yang direpresentasikan oleh kontrak UI saat ini. Link tetap
menggunakan `work_date`; daftar default hanya menampilkan tanggal terpilih.

### Semua agenda

Tombol `Lihat semua agenda` mengarah ke `sales-agendas.index` dengan parameter URL
yang eksplisit (`view=all`). Controller membangun query owner-only lintas tanggal,
mengecualikan status yang sudah selesai/cancelled/rescheduled hanya dari hitungan
badge, bukan dari daftar utama, sehingga riwayat tetap lengkap. Daftar diberi
pagination dan tetap memakai detail/action route yang sudah ada. Badge menampilkan
jumlah agenda aktif yang belum selesai; jika nol, tombol tetap tersedia tanpa badge
peringatan.

## Verification

- Feature render test untuk custom duration contract, label tombol draft, active-state
  Hari ini/Rencana, tombol semua agenda, badge agenda belum selesai, dan isolasi owner.
- Frontend test untuk custom duration 180 menghasilkan slot valid dan nilai canonical.
- Test query `view=all` memastikan agenda lintas tanggal milik user tampil dan agenda
  milik user lain tidak tampil.
- Jalankan focused PHPUnit/frontend tests, Pint untuk PHP yang berubah, build Vite,
  `git diff --check`, lalu verifikasi browser lokal pada sekitar 390x844 dan
  1440x900 jika sesi autentikasi tersedia.
