## Catatan Perubahan (Changelog)

### 2026-07-15 — Input Dinamis Modul Database

- Form Tambah dan Edit sekarang mengikuti data validation Google Sheets: dropdown, checkbox, tanggal, waktu, dan tanggal-waktu.
- Kolom tanggal pada form dinamis menggunakan date picker Oasis yang sama dengan modul Dana Talangan.
- Dropdown mendukung pilihan langsung maupun pilihan dari range atau named range.
- Metadata kolom disimpan saat Sync sehingga form tetap dapat dibuka tanpa membaca Google Sheets secara langsung.
- Baris baru menyalin format dan data validation dari baris contoh selain menyalin formula.
- Nilai dropdown ketat dan format tanggal divalidasi sebelum dikirim ke Google Sheets.

### 2026-07-15 — Sinkronisasi Dua Arah Dana Talangan

- Modul Dana Talangan menggunakan satu tab kanonis bernama `Talangan` untuk seluruh tahun.
- Tambah, Edit, Hapus, import, dan bulk update mengirim perubahan ke Google Sheets.
- Perubahan dari Google masuk ke cache lokal melalui Sync manual atau command terjadwal setiap 10 menit.
- Tab lama hanya dibaca untuk menginfer Proyek yang kosong; tidak digunakan sebagai sumber record.
- Cabang dipetakan dari Proyek, termasuk alias Mulyoharjo ke Jepara, dan tiga kolom metadata sinkronisasi disimpan tersembunyi di Google Sheets.
- Pencarian nama menampilkan jumlah pengajuan per konsumen secara case-insensitive.
- Filter mendukung rentang tanggal atau rentang bulan berdasarkan Tanggal pengajuan.
- Tabel Dana Talangan mengikuti standar tabel Database: grid 2px, header sticky, zebra/hover, horizontal scroll, boolean boxes, serta frozen Pilih + No + Nama.
- Style tabel CRM dipusatkan melalui `.crm-table-scroll` dan `.crm-data-table` agar modul baru tetap konsisten.
- Sorting Dana Talangan menggunakan klik langsung pada header dengan indikator `▼`/`▲`, sama seperti Modul Database.
- Filter Dana Talangan dipindahkan ke satu modal agar toolbar hanya menampilkan pencarian nama, tombol Filter, Export/Import, dan Tambah; jumlah serta ringkasan filter aktif tetap terlihat.
- Grid tabel CRM menggunakan separated borders agar garis cell pada frozen columns tetap terlihat ketika tabel digeser di Chrome/Edge.

### 2026-07-08 — Peningkatan Modul Database

### Ditambahkan — Fitur baru: deteksi boolean, freeze kolom, dan pencocokan header fleksibel
- Deteksi otomatis kolom boolean — kolom yang berisi `true`/`false`/`1`/`0` (case-insensitive) otomatis ditampilkan sebagai checkbox centang (hijau `✓` / putih `□`) di tabel, modal edit, dan modal tambah. Deteksi berdasarkan nilai, bukan nama kolom — jadi `TRUE`, `True`, `False`, dll. semua berfungsi.
- Tombol freeze kolom (🔒/🔓) — kolom nomor `#` dan `id_kavling` menjadi sticky (tetap terlihat saat scroll horizontal). Aktif/nonaktif melalui ikon gembok di header kolom `id_kavling`. Kedua kolom freeze/unfreeze bersamaan.
- Pencocokan header fleksibel — fungsi `isIdKavlingColumn(h)` menormalkan nama header (menghapus spasi, underscore, hyphen, case-insensitive) sehingga cocok dengan `id_kavling`, `ID Kavling`, `ID_KAVLING`, dll.

### Diperbaiki — Bug fix: checkbox tidak muncul, background transparan, dan geser freeze
- Checkbox `status_kav_mingguan` tidak muncul — penyebab: nilai di Google Sheet adalah `TRUE`/`FALSE` (huruf besar). Kode lama hanya mencocokkan `true`/`false` (huruf kecil). Diperbaiki dengan perbandingan case-insensitive `(value || '').toLowerCase()`.
- Background kolom freeze transparan — sel sticky menjadi transparan saat di-scroll, tumpang tindih dengan data lain. Diperbaiki dengan `background:#fff` eksplisit pada inline style untuk sel `id_kavling` yang di-freeze, dan `!important` CSS background kolom nomor baris.
- Geser kolom saat toggle freeze — menghapus hack `left:-9999px` yang menyebabkan reflow. Sekarang class sticky hanya diterapkan via class `.frozen` pada tabel, tanpa mengubah properti posisi saat toggle.

### Diubah — Modifikasi file utama
- Berkas `resources/views/crm/database/index.blade.php` — semua perubahan di file ini (~30 baris CSS, ~10 modifikasi template, 3 method Alpine baru).

### 2026-07-09 — Penyederhanaan Leads, Database, dan Dana Talangan

### Ditambahkan — Fitur baru: deep-link Database, quick add Lead, dan date picker Dana Talangan
- Deep-link modul Database — halaman Database sekarang bisa langsung membuka tab tertentu melalui parameter `sheet`, misalnya `?sheet=lead`.
- Quick add Lead — tombol `+ Lead Baru` di Dashboard sekarang membuka modal "Tambah Data — lead" langsung dari modul Database melalui `?sheet=lead&add=1`.
- Dashboard Lead berbasis Google Sheet — data Lead di agenda hari ini dan aktivitas terbaru sekarang dibaca dari tab `lead` pada Database, bukan dari tabel lokal `leads`.
- Kolom `TGL Komitmen` pada Dana Talangan — ditambahkan ke form, tabel, detail, export, import, validasi, model, dan database.
- Custom date picker untuk `TGL Komitmen` — menggunakan date picker internal yang sama seperti `Tanggal Lead` dan `Tanggal` Dana Talangan.

### Diubah — Modifikasi navigasi, label, dan alur Leads
- Modul Leads standalone dihapus dari alur utama karena belum digunakan branch dan datanya kosong.
- Sidebar Leads dihapus.
- Tombol quick add Lead tetap dipertahankan, tetapi diarahkan ke tab `lead` di modul Database.
- Label Dana Talangan `Penyelesaian` diubah menjadi `Progress Penagihan`, tanpa mengubah nama kolom database.
- Pencarian Dana Talangan sekarang juga mencakup kolom `penyelesaian`.
- Matching tab Database untuk deep-link dibuat case-insensitive, sehingga `lead`, `Lead`, atau `LEAD` tetap bisa terbuka.

### Diperbaiki — Bug fix: quick add modal, dashboard widget, spasi Masukan, notifikasi
- Quick add Lead tidak membuka modal — diperbaiki dengan mengarahkan ke tab `lead` yang benar.
- Dashboard Lead widget sebelumnya membaca tab yang salah — sekarang membaca dari tab `lead`.
- Spasi berlebih pada Bug/Masukan dipangkas otomatis saat submit dan saat admin memberi catatan.
- Jumlah notifikasi Bug/Masukan sekarang muncul saat halaman pertama kali dimuat.

### Dihapus — Pembersihan modul Leads standalone
- Route standalone `leads.*`.
- Controller standalone `LeadController`.
- View standalone `resources/views/crm/leads/`.
- Menu sidebar Leads standalone.

### 2026-07-09 — Opsi Status Cicilan Dana Talangan

### Diubah — Status `aktif/lunas` menjadi 3 opsi baru
- Opsi status Dana Talangan sekarang: `Sanggup`, `Tidak Sanggup`, `Lunas`.
- Label header tabel, filter, form, detail modal, export, dan bulk bar diubah menjadi `Status Cicilan`.
- Default status baru di database: `sanggup`.
- Validasi create & edit menerima `sanggup`, `tidak_sanggup`, `lunas`.
- Controller bulk status options diperbarui.
- Data lama dengan status `aktif` otomatis dipetakan ke `sanggup` via migration.
- Warna baris tabel: `lunas` hijau, `tidak_sanggup` merah, `sanggup` biru.
- Export template dropdown diperbarui dengan 3 opsi.
- Import menerima nilai `sanggup`, `tidak_sanggup`, `lunas`; nilai `aktif` lama dipetakan ke `sanggup`.

### 2026-07-09 — Metadata Internal Dipisahkan dari Google Sheet

### Diubah — Metadata tidak lagi ditulis ke Google Sheet
- Tiga kolom metadata (`oasis_sync_id`, `oasis_deleted_at`, `oasis_deleted_by`) tidak lagi ditambahkan oleh `ensureMetadataHeaders()` saat sync.
- `createRecord()` dan `updateRecord()` tidak lagi menulis `oasis_sync_id` ke cell sheet.
- `softDelete()` tidak lagi menulis `oasis_deleted_at`/`oasis_deleted_by` ke cell sheet.
- Metadata tetap disimpan secara lokal di tabel `database_sheet_records` untuk operasi internal.
- `editableHeaders()` kini juga mengecualikan `oasis_sync_id` dari daftar yang bisa diedit pengguna.

### Ditambahkan — Command Pembersihan
- `php artisan sheet:cleanup-meta` untuk menghapus kolom metadata yang sudah telanjur ada di sheet lama.
- Dukungan `--dry-run` untuk melihat apa yang akan dihapus tanpa menjalankan.
- Dukungan `--branch={id}` untuk membersihkan branch tertentu.
- Command `sheet:cleanup-meta` terdaftar di artisan.

### 2026-07-09 — Audit Konsistensi UI CRM

### Diperbaiki — Bug aksi, route, dan tampilan
- Tombol hapus admin cabang sekarang menargetkan user admin yang benar.
- Jumlah admin di kartu cabang memakai counter relasi yang sesuai.
- Quick action `+ Proyek Baru` hanya tampil untuk superadmin.
- Route `database.fetch` yang mengarah ke method tidak ada dihapus.
- Endpoint data sheet Database sekarang memvalidasi akses branch user.
- URL fetch/action di widget feedback, detail task, dan Database sheet dibuat absolut agar tidak rusak di halaman nested.
- Kolom body tabel Dana Talangan mengikuti visibility header pada layar kecil agar tidak misalignment.
- Empty state Database sheet kosong menjelaskan kebutuhan baris contoh sebelum tambah data.
- Bulk update/delete Task Tracker mempertahankan konteks filter/bulan saat redirect.

### 2026-07-10 — Dashboard: Widget Pipeline Konsumen

### Ditambahkan — Widget Konsumen Progress
- Grafik pipeline 7 tahap (BI Checking → PSJB → Pemberkasan → Proses Bank → PPJB Dev → Akad → BAST) menggantikan widget Status Cabang.
- Data konsumen dihitung unik per tahap terakhir, deduplikasi lintas tahap dengan `branch_id|kavling` key.
- Ketika cabang dipilih: menampilkan pipeline cabang tersebut.
- Ketika tidak ada cabang dipilih (superadmin): menampilkan agregasi seluruh cabang aktif.
- Sumber data: tabel `konsumen_progress_sheet_rows` yang sudah ada (sync via `KonsumenProgressSyncService`).

### Dihapus — Widget Status Cabang
- Widget batang Status Cabang (completion rate per cabang) dihapus dari dashboard.
- Kode controller `$branchStatuses` beserta query `ContentItem` per-cabang dibersihkan.

### Diperbaiki — Error dashboard
- Menambahkan `use App\Models\DatabaseSheetSyncStatus` ke `DashboardController` untuk memperbaiki `Class not found` error.
- Dashboard admin cabang kini menerima `selectedBranchId` dari cabang user, sehingga widget Sync tidak lagi memicu error 500 akibat variabel yang tidak tersedia.
- Akses `selectedBranchId` pada template dibuat defensif dan ditambahkan feature test untuk memastikan dashboard admin cabang merespons HTTP 200.
