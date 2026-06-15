# PRD Modul: Lead Monitoring Cabang

## 1. Nama Modul
Lead Monitoring Cabang

## 2. Posisi Modul dalam Web App
Modul ini adalah salah satu modul di dalam web app yang sudah ada. Modul tidak berdiri sendiri, tetapi memakai autentikasi, layout, navigasi, dan pola komponen yang sudah tersedia pada aplikasi utama.

## 3. Tujuan Modul
Modul ini digunakan untuk memonitor lead cabang secara terstruktur dengan menghubungkan tiga sumber data:

- **MASTER** sebagai sumber data referensi
- **Januari** sebagai data event/kampanye ringkasan
- **Daily Leads** sebagai data realisasi harian

Tujuan utamanya adalah agar tim operasional dapat melihat performa lead per cabang, proyek, dan sumber lead secara cepat, konsisten, dan dapat diaudit.

## 4. Prinsip Data

1. **MASTER adalah sumber referensi utama**.
   Data cabang, proyek, sumber lead, dan kategori harus mengikuti master.

2. **Januari adalah ringkasan event**.
   Setiap baris merepresentasikan satu event/kampanye dengan `EventID` sebagai pengikat.

3. **Daily Leads adalah log harian**.
   Satu `EventID` dapat memiliki banyak baris harian.

4. **Relasi utama adalah `EventID`**.
   `Januari` dan `Daily Leads` harus saling berkesinambungan melalui `EventID`.

## 5. Ruang Lingkup Modul

### In Scope
- Dashboard ringkas performa lead
- Daftar event/kampanye
- Detail event beserta log harian
- CRUD master data
- CRUD event
- CRUD daily leads
- Import CSV
- Export CSV/XLSX
- Filter dan pencarian data
- Validasi relasi antar data
- Perhitungan otomatis metrik utama

### Out of Scope
- Approval workflow kompleks
- Integrasi WhatsApp/email otomatis
- Forecasting berbasis AI
- Multi-tenant enterprise
- Payroll atau komisi sales

## 6. User Persona

### Admin Operasional
Mengelola master data, input event, dan input harian.

### Supervisor Cabang
Memantau performa cabang dan progres event.

### Manajemen
Melihat KPI, tren, dan perbandingan performa antar cabang/proyek.

## 7. User Story Utama

- Sebagai admin, saya ingin mengimpor data master agar referensi cabang/proyek/sumber lead konsisten.
- Sebagai admin, saya ingin membuat event baru agar kampanye dapat dipantau.
- Sebagai admin, saya ingin mengisi daily leads agar progres harian tercatat.
- Sebagai supervisor, saya ingin melihat dashboard agar saya tahu event mana yang berjalan baik atau bermasalah.
- Sebagai manajemen, saya ingin melihat rekap per cabang dan proyek agar bisa mengambil keputusan.

## 8. Struktur Data

### 8.1 Master Data
Field minimal:
- Cabang
- Proyek
- Sumber Lead
- Kategori
- Cabang (All)
- Proyek (All)
- Sumber (All)

Fungsi:
- menjadi referensi validasi
- menjaga konsistensi penamaan
- mendukung filtering lintas cabang/proyek/sumber

### 8.2 Event / Ringkasan Januari
Field minimal:
- EventID
- Proyek
- Sumber Lead
- Tanggal Mulai
- Tanggal Selesai
- Anggaran Total
- Target Lead Harian
- Status
- Catatan
- Total Leads
- Cost per Lead

Aturan:
- `EventID` unik
- `Total Leads` dapat dihitung dari daily logs
- `Cost per Lead = Anggaran Total / Total Leads` jika total leads > 0

### 8.3 Daily Leads
Field minimal:
- EventID
- Proyek
- Sumber Lead
- Tanggal
- Hari Ke
- Target Lead Harian
- Leads Didapat
- Cumulative Leads
- Achievement %

Aturan:
- satu `EventID` dapat punya banyak baris
- `Hari Ke` dihitung relatif terhadap tanggal mulai event
- `Cumulative Leads` adalah akumulasi harian per event
- `Achievement %` dihitung berdasarkan aturan progres yang disepakati

## 9. Alur Kerja Modul

1. User membuka modul dari web app utama.
2. Sistem memuat data master, event, dan daily leads.
3. User dapat melihat dashboard ringkas.
4. User dapat membuka daftar event.
5. User masuk ke detail event untuk melihat log harian.
6. User dapat menambah atau mengedit daily leads.
7. Sistem melakukan validasi dan kalkulasi otomatis.
8. User dapat mengimpor atau mengekspor data.

## 10. Halaman / Submenu Modul

### 10.1 Dashboard
Menampilkan ringkasan:
- total event aktif
- total event selesai
- total leads
- total budget
- average cost per lead
- top performa proyek/cabang
- event dengan performa terbaik dan terburuk

### 10.2 Master Data
Fitur:
- lihat daftar master
- tambah/edit/hapus
- cari dan filter
- import/export

### 10.3 Event Monitoring
Fitur:
- daftar event
- tambah/edit event
- detail event
- status event
- ringkasan budget dan performa

### 10.4 Daily Leads
Fitur:
- daftar log harian
- tambah/edit/hapus baris harian
- bulk input per tanggal
- auto update cumulative leads

### 10.5 Reports
Fitur:
- rekap per cabang
- rekap per proyek
- rekap per sumber lead
- rekap per periode
- export hasil laporan

## 11. Business Rules

1. Jika `EventID` tidak ditemukan di data event, daily log harus ditolak atau diberi status invalid.
2. Jika proyek atau sumber lead tidak sesuai master, tampilkan warning.
3. Jika tanggal selesai lebih kecil dari tanggal mulai, tampilkan error.
4. Jika ada duplikasi `EventID + Tanggal` pada daily leads, sistem harus mencegah input ganda.
5. Jika total leads masih 0, `Cost per Lead` tidak boleh menghasilkan pembagian nol.
6. Event lintas bulan tetap harus didukung.

## 12. Validasi Data

### Validasi wajib
- EventID unik
- Relasi daily ke event valid
- Nilai tanggal valid
- Budget numerik dan tidak negatif
- Leads numerik dan tidak negatif
- Data master harus jadi referensi utama

### Validasi tambahan
- Deteksi baris anomali
- Deteksi event tanpa daily log
- Deteksi daily log di luar rentang tanggal event

## 13. Kalkulasi Otomatis

Sistem wajib menghitung:
- `Total Leads` = jumlah `Leads Didapat`
- `Cumulative Leads` = akumulasi leads harian
- `Cost per Lead` = budget / total leads
- progress event terhadap target
- ranking performa event/proyek/cabang

## 14. UX Requirements
- Tampilan clean dan profesional
- Fokus pada keterbacaan data
- Card KPI di bagian atas
- Tabel dengan search, filter, sort, dan pagination
- Badge warna untuk status
- Detail event mudah dibaca pada desktop dan mobile
- Form input sederhana untuk user operasional

## 15. Integrasi dengan Web App yang Ada

Modul ini harus mengikuti:
- layout global aplikasi utama
- autentikasi yang sudah tersedia
- sistem navigasi existing
- style guide existing
- pola komponen existing

Modul tidak boleh mengubah struktur inti aplikasi utama. Modul hanya menjadi feature area yang dapat diaktifkan melalui sidebar/menu.

## 16. Acceptance Criteria

Modul dianggap selesai jika:
- data master, event, dan daily dapat ditampilkan
- relasi antar data berjalan via `EventID`
- dashboard memperlihatkan KPI utama
- user bisa CRUD data
- import/export berjalan
- perhitungan otomatis berjalan benar
- validasi data anomali muncul dengan jelas
- modul bisa dipasang ke web app yang sudah ada tanpa merusak layout utama

## 17. Output yang Diharapkan dari Agent AI

Agent AI yang membangun modul ini harus menghasilkan:
- halaman dashboard
- halaman master data
- halaman event monitoring
- halaman daily leads
- halaman reports
- komponen reusable
- model data yang jelas
- seed data contoh
- validasi input dan kalkulasi otomatis
- integrasi yang rapi ke web app existing

## 18. Catatan Implementasi untuk Agent AI
- Pertahankan relasi parent-child antara event dan daily leads.
- Jangan satukan seluruh data menjadi tabel flat.
- Jangan mengubah makna asli kolom data.
- Prioritaskan struktur yang mudah dipelihara.
- Modul harus siap dikembangkan ke backend nyata.

