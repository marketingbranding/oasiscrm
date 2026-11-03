# Desain Shell Agenda Buku Saku Terpadu

## Intent

Menyatukan pengalaman Agenda Buku Saku agar pengguna tidak merasa berpindah
halaman ketika memilih hari kerja, agenda yang sudah terkirim, atau akhir pekan.
Perbedaan alur kerja tetap dipertahankan, tetapi identitas halaman, navigasi,
dan status agenda selalu konsisten.

## Goals

- Header `Buku Saku Saya`, identitas Sales/cabang, tab, dan navigasi minggu selalu
  tampil dalam satu shell yang sama.
- Status hari yang dipilih terlihat sebelum pengguna membaca isi agenda:
  `DRAFT — BELUM DIKIRIM`, `TERKIRIM KE KOORDINATOR`, atau
  `TERSIMPAN LANGSUNG — AKHIR PEKAN`.
- Receipt weekday tetap dipakai untuk agenda yang belum dikirim.
- Agenda terkirim dan agenda akhir pekan tetap memakai daftar rencana, tetapi
  berada dalam hierarki visual yang sama.
- Pengguna dapat memahami apakah agenda masih perlu dikirim tanpa bergantung
  pada warna atau bentuk struk saja.

## Non-goals

- Tidak mengubah route, validasi, otorisasi, penyimpanan, atau perhitungan menit.
- Tidak menghapus receipt/submission workflow weekday.
- Tidak mengubah keputusan weekend direct-save yang sudah diterapkan.
- Tidak membuat ulang navigasi global CRM atau mengganti bahasa domain OASIS.

## Anti-goals

- Jangan menghilangkan header Buku Saku pada mode receipt.
- Jangan membuat receipt dan daftar terlihat seperti dua halaman atau modul yang
  berbeda.
- Jangan menggunakan warna hitam penuh sebagai satu-satunya penanda status.
- Jangan menampilkan aksi kirim pada akhir pekan.

## Pendekatan yang dipilih

### Shell terpadu dengan konten berdasarkan status

Komponen header Buku Saku dan navigasi minggu menjadi page-level shell yang
dirender satu kali di luar percabangan receipt/list. Di bawah navigasi tanggal,
workspace menampilkan context strip yang berisi:

- judul konteks tanggal, misalnya `Agenda Jumat, 30 Okt 2026`;
- badge status berbasis teks;
- penjelasan singkat atau aksi utama sesuai status.

Isi workspace tetap mengikuti perilaku yang sudah disepakati:

| Kondisi | Isi utama | Aksi utama |
|---|---|---|
| Weekday belum dikirim | Receipt agenda | Tambah agenda ke struk / Sobek & kirim |
| Weekday sudah dikirim | Daftar agenda terkirim | Tambah agenda tambahan bila tersedia |
| Weekend | Daftar rencana tersimpan | Tambah rencana akhir pekan |

Status harus memiliki teks yang jelas, bukan hanya warna. Accent visual receipt
boleh tetap dipertahankan sebagai identitas workflow, tetapi tidak boleh menutupi
judul, header, atau status utama.

## UX dan responsif

- Desktop dan mobile memakai urutan informasi yang sama: header Buku Saku → tab →
  navigasi minggu → context strip → isi agenda.
- Pada mobile, context strip tetap ringkas dan tidak membuat pengguna harus
  menggulir horizontal.
- Header Buku Saku tidak diulang oleh receipt canvas atau partial daftar.
- Tombol tambah tetap memakai modal yang sudah ada dengan focus management,
  Escape, pemulihan fokus, dan body-scroll lock.

## Batas implementasi

- Audit pemilik markup header saat ini dan pindahkan hanya wrapper yang diperlukan
  agar tidak ada duplikasi header.
- Tambahkan state/label status pada workspace, bukan mengganti backend state.
- Reuse primitive CSS CRM dan komponen status yang sudah ada.
- Tambahkan regression test untuk tiga status, keberadaan header, dan preservasi
  receipt weekday.
- Tambahkan satu entry Changelog melalui migration idempoten karena perubahan ini
  memengaruhi pengalaman kerja pengguna.

## Keputusan yang diminta

Desain ini menafsirkan pilihan Opsi 1 sebagai: **struktur shell selalu sama,
sedangkan receipt versus daftar tetap berbeda sesuai status agenda**. Setelah
disetujui, langkah berikutnya adalah membuat rencana implementasi sebelum
menyentuh kode.
