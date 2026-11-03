# Desain Perbaikan Mobile Lead Saya

## Intent

Menyesuaikan workspace `Lead Saya` pada viewport mobile agar lebih dekat dengan referensi: kartu lead mudah dipindai, status manual menggunakan aksen kuning OASIS, dan modal tambah lead memiliki urutan serta kepadatan field yang lebih nyaman disentuh.

## Goals

- Mengubah `Lihat detail lead` dari overlay transparan seluruh kartu menjadi tautan aksi kecil yang terlihat dan tetap keyboard-accessible.
- Menampilkan status manual `No Respon`, `Diskusi`, `Tatap Muka`, dan `Cek Lokasi` dengan badge kuning tanpa menghilangkan teks status.
- Menampilkan tanda bintang merah pada label `No. WhatsApp / Telepon` dan field wajib lain yang terlihat pada modal mobile.
- Menata ulang modal tambah lead mobile seperti referensi: judul mixed-case, badge `Wajib diisi`, konteks tanggal/proyek, nama dan WhatsApp full-width, tanggal/proyek dua kolom bila tersedia, bagian tambahan tertutup secara default, lalu sumber/status dan tombol aksi.
- Mempertahankan validasi server, route, authorization, status workflow, duplicate warning, CSRF, operation UUID, safe-area, focus behavior, dan desktop layout.

## Non-goals

- Tidak mengubah database, controller, route, policy, FormRequest, atau aturan scope.
- Tidak mengubah field yang dikirim atau menghapus kemampuan input sumber, kanal, aktivitas, status, dan catatan.
- Tidak mengubah modal edit lead atau workspace agenda selain selector/style yang memang shared dan aman.
- Tidak menambah dependency atau membuat sistem form/notification baru.

## Approaches

1. **Mobile-only reference alignment (dipilih):** mempertahankan markup/data contract yang ada, memperbaiki struktur mobile pada partial `lead-saya.blade.php`, dan menambahkan style khusus mobile. Ini paling dekat dengan referensi serta membatasi risiko desktop.
2. **Minimal CSS:** hanya memperkecil dan mewarnai struktur sekarang. Ini lebih kecil tetapi tidak menyelesaikan urutan/kepadatan modal yang menjadi keluhan utama.
3. **Shared form refactor:** menyatukan quick lead dan modal Lead Saya ke partial reusable. Ini berpotensi mengurangi duplikasi, tetapi memperbesar area regresi untuk form desktop, monitoring, dan validasi field.

## Responsive Contract

- Mobile sekitar 425px: modal menjadi bottom sheet dengan konten yang dapat discroll, tombol aksi tetap mudah dijangkau, dan tidak ada horizontal overflow.
- Mobile: bagian `Sumber dan proses` tertutup default; membuka bagian itu tetap memungkinkan pengguna mengisi field opsional/lanjutan.
- Desktop/tablet: struktur dan layout form yang ada tetap dipertahankan kecuali perubahan semantik label/status yang aman.
- Badge kuning tetap menyertakan teks status agar warna bukan satu-satunya indikator.
- Semua link/button minimal memiliki target sentuh yang sesuai dan fokus terlihat.

## Validation and Tests

- Tambahkan regression test markup untuk badge kuning, bintang wajib WhatsApp, modal section, dan tautan detail yang tidak lagi menjadi overlay transparan.
- Pertahankan test server-side bahwa `phone` tetap wajib dan pesan validasi tetap Bahasa Indonesia.
- Jalankan focused PHPUnit, Pint, Vite build, Blade cache, full suite bila relevan, dan browser verification pada tab `519a704c-ddca-4fe2-8670-ebd241048eb5` di 425×812 serta desktop.

## Open Implementation Calls

- Status yellow diterapkan pada badge status manual di mobile Lead Saya; status selesai tetap semantic green.
- Label wajib menggunakan `<span aria-hidden="true">*</span>` berwarna merah di samping label, sementara atribut `required` tetap menjadi sumber validasi dan accessibility semantics.
- Struktur field modal dipertahankan agar payload dan validasi tidak berubah; hanya urutan visual, default collapsed state, label, dan CSS mobile yang disesuaikan.
