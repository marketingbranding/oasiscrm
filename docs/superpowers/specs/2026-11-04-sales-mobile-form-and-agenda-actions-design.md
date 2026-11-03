# Perbaikan form lead dan aksi agenda di mobile

## Intent

Rapikan dua masalah layout pada Buku Saku Sales di ponsel: footer tombol form tambah lead menutupi field ketika grup opsional dibuka, dan tombol Edit/Hapus agenda keluar dari sisi kertas struk.

## Goals

- Field form lead tetap dapat dijangkau dan discroll tanpa tertutup tombol Simpan atau Simpan & Tambah Lagi.
- Aksi Edit dan Hapus agenda terlihat utuh, teratur, dan mudah disentuh pada lebar ponsel.
- Perubahan dibatasi pada presentasi mobile.

## Non-goals / constraints

- Tidak mengubah route, otorisasi, data, lifecycle lead, atau perilaku agenda.
- Tidak mengubah layout desktop/tablet atau menambah dependensi.
- Mempertahankan footer aksi form yang mudah dijangkau.

## Approaches

1. **CSS mobile terarah (dipilih):** sesuaikan batas tinggi/scroll panel form dan susunan tombol agenda pada kertas struk. Perubahan kecil dan menjaga markup serta alur yang ada.
2. **Ubah markup:** pisahkan ulang area scroll dan kontrol agenda secara struktural. Lebih luas daripada kebutuhan karena masalah yang terlihat terbatas pada aturan responsif saat ini.

## Implementation notes

- Pertahankan panel form sebagai layout kolom dengan footer aksi tetap terlihat di bawah area konten; area konten scroll saat isinya bertambah tanpa ditumpangi footer.
- Atur tombol Edit/Hapus agar membungkus atau menggunakan lebar kolom yang muat di dalam kontainer struk pada layar sempit.
- Verifikasi kedua kondisi pada viewport mobile serta pastikan desktop tidak berubah.
- Standar OASIS mengharuskan entri Changelog berbahasa Indonesia untuk perubahan UI; deploy entri ini melalui migrasi idempoten dan verifikasi tampil satu kali di halaman Changelog.
