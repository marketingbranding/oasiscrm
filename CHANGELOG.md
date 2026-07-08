## Catatan Perubahan (Changelog)

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
