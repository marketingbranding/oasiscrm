# Desain Navigasi Mingguan Sales Agenda

## Tujuan

Mengganti navigasi tiga konteks **Kemarin / Hari ini / Rencana** dengan navigasi tanggal mingguan **Senin sampai Minggu**, tanpa mengganti workflow agenda harian dan receipt OASIS yang sudah ada.

## Keputusan Desain

### 1. Model navigasi

Gunakan **Opsi A: strip tanggal mingguan sebagai navigasi harian**.

- Halaman tetap menampilkan satu hari agenda pada satu waktu.
- Strip navigasi selalu menampilkan tujuh tanggal: Senin, Selasa, Rabu, Kamis, Jumat, Sabtu, Minggu.
- Tanggal aktif membuka agenda untuk hari tersebut.
- Klik tanggal tidak mengubah layout receipt, form, slot waktu, atau action agenda yang sudah ada.
- Minggu ditentukan dari tanggal aktif dan selalu dimulai pada hari Senin.

Contoh susunan:

```text
< minggu sebelumnya     03 - 09 NOV 2026     minggu berikutnya >   [ kalender ]

 SEN  SEL  RAB  KAM  JUM  SAB  MIN
  03   04   05   06   07   08   09
       !         ▣                         ← indikator per tanggal
```

Visual final mengikuti referensi gambar pengguna: tanggal aktif memakai treatment aktif yang kuat, tanggal lain tetap ringkas, dan strip dapat digunakan pada mobile dengan horizontal scrolling bila diperlukan.

### 2. Tombol kalender

- Tombol kalender membuka picker CRM yang sudah tersedia.
- Pengguna memilih satu tanggal acuan, bukan memilih `from` dan `to` secara bebas.
- Sistem menghitung minggu Senin–Minggu yang memuat tanggal tersebut.
- Setelah submit, tanggal yang dipilih tetap menjadi tanggal aktif; strip menampilkan minggu terkait.
- Tidak ada batas maksimum tanggal.
- Tanggal hari ini dan tanggal lampau tetap dapat dibuka karena navigasi baru adalah navigasi agenda umum, bukan lagi konteks Rencana khusus.
- Parameter URL utama tetap `work_date=Y-m-d` agar link lama dan redirect agenda tetap kompatibel.

### 3. Indikator status pada tanggal

Setiap tanggal pada strip dapat menampilkan status visual dan teks aksesibel:

- **Belum memiliki rencana:** ikon kotak bergaya referensi dan label singkat seperti `Belum ada rencana`.
- **Memiliki agenda belum selesai:** tanda `!` di bawah/di dekat tanggal.
- **Memiliki rencana dan agenda selesai:** tidak menampilkan tanda bahaya tambahan.
- Bila sebuah tanggal memiliki lebih dari satu kondisi, indikator `!` tetap diprioritaskan karena membutuhkan tindakan.

Indikator tidak boleh hanya mengandalkan warna. Ikon, teks tersembunyi/accessible label, atau tooltip keyboard harus menyampaikan maknanya.

### 4. Identitas visual

- Pertahankan receipt, toolbar, warna modul Sales, border, typography, dan density OASIS yang sudah ada.
- Strip tanggal menjadi satu area navigasi baru, bukan redesign seluruh halaman.
- Gunakan bentuk kotak/minim rounded sesuai desain OASIS; jangan memakai kartu SaaS besar atau glassmorphism.
- Tombol kalender memakai gaya button CRM yang sudah tersedia.
- Status warning memakai treatment semantik yang ada; tidak mengganti warna warning dengan accent dekoratif.
- Ukuran target sentuh setiap tanggal minimal 44px pada mobile.

### 5. Responsive behavior

Desktop:

- Tujuh tanggal tampil dalam satu baris.
- Tombol minggu sebelumnya/berikutnya dan kalender berada pada baris kontrol yang sama.
- Receipt dan area agenda di bawahnya tidak berubah.

Mobile:

- Tujuh tanggal tetap tersedia dalam strip yang dapat digeser horizontal atau dalam grid tujuh kolom yang tidak memotong label.
- Tombol kalender dan navigasi minggu tetap berukuran touch-friendly.
- Tidak ada horizontal scrolling pada form atau receipt; scrolling hanya boleh terjadi pada strip tanggal jika diperlukan.
- Tanggal aktif dan indikator status tetap terlihat jelas tanpa hover.

## Kontrak Data dan URL

- `work_date` tetap menjadi tanggal aktif yang digunakan controller, receipt, query agenda, slot waktu, dan form.
- Controller menghitung `weekStart` dengan hari Senin dan `weekEnd` dengan hari Minggu dari `agendaDate`.
- Query indikator menggunakan scope organisasi yang sama dengan agenda aktif; tidak boleh menampilkan status tanggal dari branch/project yang tidak dapat dilihat pengguna.
- Tombol minggu sebelumnya/berikutnya mengubah `work_date` ke tanggal aktif yang setara di minggu sebelumnya/berikutnya, dengan fallback tanggal Senin bila diperlukan.
- Parameter lama `plan_date` tidak menjadi jalur utama lagi. Jika masih diterima untuk backward compatibility, nilainya harus dinormalisasi ke `work_date` tanpa mengubah link Hari ini atau histori yang sudah ada.

## Empty dan Attention State

- Hari tanpa rencana menampilkan empty treatment bergaya referensi di area agenda saat tanggal tersebut aktif.
- Pada strip, tanggal tanpa rencana mendapat indikator ringkas; detail lengkap tetap ditampilkan pada area agenda aktif.
- Agenda yang belum selesai tetap menggunakan status bisnis yang ada. Tanda `!` hanya indikator navigasi dan tidak mengubah status agenda.
- Tidak mengubah aturan submit, edit, delete, complete, reschedule, atau upload evidence.

## Aksesibilitas

- Setiap tanggal adalah button/link semantik dengan nama yang memuat hari dan tanggal lengkap.
- Tanggal aktif memakai `aria-current="date"` atau atribut setara yang konsisten dengan markup existing.
- Tombol minggu sebelumnya/berikutnya dan kalender memiliki label bahasa Indonesia.
- Indikator `!` dan ikon kotak memiliki accessible name; status tidak disampaikan melalui warna saja.
- Keyboard dapat berpindah ke semua tanggal dan membuka picker.
- Tidak menambah modal baru jika picker CRM yang ada sudah memenuhi kebutuhan.

## Acceptance Criteria

1. Pada halaman agenda, navigasi lama Kemarin/Hari ini/Rencana digantikan strip Senin–Minggu.
2. Minggu yang tampil selalu dimulai Senin dan berakhir Minggu.
3. Klik tanggal menampilkan agenda untuk tanggal tersebut dan mempertahankan desain receipt/form saat ini.
4. Tombol kalender memilih satu tanggal acuan dan menampilkan minggu yang sesuai.
5. Navigasi minggu sebelumnya/berikutnya mempertahankan tanggal aktif secara masuk akal.
6. Tanggal tanpa rencana menampilkan ikon kotak dan keterangan sesuai referensi.
7. Tanggal dengan agenda belum selesai menampilkan tanda `!` dengan label aksesibel.
8. Scope branch/project/permission tetap sama dengan halaman agenda saat ini.
9. Workflow input agenda pada Hari ini tidak diubah secara fungsional.
10. URL lama dengan `work_date` tetap dapat dibuka.

## Di Luar Scope

- Menampilkan tujuh receipt sekaligus.
- Mengubah status agenda atau aturan slot waktu.
- Mengubah model/database agenda.
- Membuat calendar library baru.
- Mengubah desain global CRM atau sidebar.
