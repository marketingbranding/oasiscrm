# Buku Saku Sales Gap Pass 2

## Intent

Menyamakan empat layar mobile Buku Saku Sales pada viewport 390×844 dengan reference lokal: Rencana, TambahAgendaForm, DetailAgenda, dan StrukAkhirPekan.

## Scope

- Mengubah Blade, CSS mobile-scoped, JavaScript, dan regression test.
- Mempertahankan route, service, authorization, dan workflow desktop/legacy.
- Menambahkan helper murni untuk menghitung slot agenda agar mudah diuji.
- Menjadikan upload foto detail agenda single-tap dan auto-submit dengan state loading/error.

## Non-goals

- Tidak mengubah route, permission, policy, controller/service contract, atau schema.
- Tidak mengubah tampilan desktop/legacy.
- Tidak menambah kolom weekend baru; weekend diturunkan dari tanggal rencana.

## Chosen approach

Patch terarah pada markup yang sudah ada. CSS memakai prefix komponen agenda mobile dan token warna/font OASIS. Helper slot menerima tanggal, agenda terjadwal, dan durasi lalu mengembalikan jam mulai/selesai, pesan validasi, serta sisa menit. Form memakai input jam tersembunyi/native yang tetap kompatibel dengan request existing, chip durasi termasuk custom, dan submit existing. Evidence tetap memakai endpoint existing tetapi satu form/input per slot dengan submit otomatis.

## Acceptance contract

- Waktu kosong tampil `Fleksibel`; waktu tersedia tampil `HH:MM–HH:MM` dengan `N mnt`.
- Weekend menampilkan badge `AKHIR PEKAN` dan footer `TOTAL N MENIT · TANPA TARGET`; marker weekend tetap ikut pada data submission yang sudah ada.
- Tab tanggal hanya memberi border-bottom 3px pada tab aktif.
- Slot hari kerja menghormati jam masuk, istirahat, jam pulang, dan overlap; weekend tidak memaksa jam awal.
- Detail agenda memakai Archivo, kartu info tunggal, dua slot foto 110px, auto-upload maksimal dua foto, hasil wajib, dan sticky action yang tidak tertutup chat.
- Regression tests mencakup helper slot, render, weekend marker, batas foto, dan disabled completion.

## Verification

Jalankan focused tests, suite terkait Buku Saku Sales, Pint untuk PHP, build Vite, dan browser verification pada 390×844 serta 1440×900. Simpan screenshot before/after untuk tiga layar yang dapat direproduksi.
