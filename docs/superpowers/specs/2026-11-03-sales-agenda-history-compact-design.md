# Desain Daftar Semua Agenda yang Lebih Ringkas

## Intent

Mengurangi tinggi halaman “Semua agenda” pada mobile dengan mengganti kartu agenda besar menjadi baris agenda padat yang mudah dipindai, mengikuti pola daftar agenda pada tampilan hari terpilih.

## Goals

- Menampilkan tanggal, jadwal, judul, kategori, status, dan akses ke detail dalam ruang yang lebih kecil.
- Mempertahankan pagination dan URL detail yang sudah ada.
- Mempertahankan status berbasis teks/semantic badge serta akses keyboard.
- Menjaga informasi proyek dan lokasi tetap tersedia pada halaman detail agenda.
- Tetap responsif pada mobile dan desktop tanpa mengubah route, query, atau authorization.

## Non-goals

- Tidak mengubah data agenda, query backend, urutan, pagination, atau status workflow.
- Tidak menghapus informasi proyek/lokasi dari halaman detail.
- Tidak mengubah tampilan agenda hari terpilih atau receipt workspace.
- Tidak menambah komponen/library baru.

## Pendekatan yang Dipilih

Daftar “Semua agenda” memakai markup khusus baris ringkas yang mengikuti pola `sales-submitted-row`: kolom waktu di kiri, isi agenda di tengah, dan status di bawah/di samping isi sesuai lebar layar. Tanggal menjadi eyebrow kecil di atas judul; kategori tetap terlihat sebagai label kecil; tautan detail tetap menjadi target interaksi yang jelas.

Alternatif kartu kompak ditolak karena masih mempertahankan kepadatan vertikal yang dikeluhkan. Pemakaian ulang langsung markup receipt ditolak karena receipt memiliki konteks satu tanggal dan aturan layout berbeda, sehingga berisiko mencampur perilaku dua mode.

## Responsive Contract

- Mobile: satu kolom, padding kecil, judul boleh wrap, status tetap terlihat, target sentuh minimal 44px pada tautan detail.
- Desktop: baris dapat memakai grid waktu/isi/status dengan informasi tetap mudah dipindai.
- Empty state dan pagination tetap memakai komponen/pola yang sudah ada.

## Verifikasi

- Tambahkan source/render regression test untuk field ringkas, detail link, status, dan tidak adanya grid fakta besar pada mode all.
- Jalankan focused PHPUnit tests, Pint, Vite build, Blade cache, dan full suite bila risiko tetap rendah.
- Verifikasi browser pada 390×844 dan 1440×900 jika halaman dapat diakses dengan sesi lokal; jangan meminta atau menyimpan kredensial.
