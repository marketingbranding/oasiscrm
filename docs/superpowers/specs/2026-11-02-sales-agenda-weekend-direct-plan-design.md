# Desain Rencana Akhir Pekan Langsung

## Intent

Pada Sabtu/Minggu, Sales dapat menambahkan rencana langsung melalui modal tanpa
melewati atau melihat struk agenda. Setelah disimpan, rencana langsung dianggap
tersimpan dan tidak memerlukan proses “Sobek & kirim ke Koordinator”.

## Goals

- Tombol “+ Tambah rencana akhir pekan” langsung membuka modal tambah rencana.
- Akhir pekan selalu ditampilkan sebagai daftar rencana biasa, bukan canvas atau
  preview struk.
- Agenda akhir pekan yang baru disimpan tetap tampil sebagai rencana tersimpan
  dan dapat dibuka seperti agenda lainnya.
- Hari kerja tetap memakai alur receipt/submission yang sudah ada.
- Copy modal menggunakan “Tambah Rencana” dan “Tambahkan Rencana”.

## Non-goals

- Tidak mengubah route, validasi, otorisasi, atau penyimpanan agenda.
- Tidak menghapus dukungan data submission akhir pekan yang sudah tersimpan.
- Tidak mengubah perhitungan jam kerja Jumat atau navigasi mingguan.

## Anti-goals

- Jangan menampilkan tombol atau instruksi “Sobek & kirim ke Koordinator” untuk
  akhir pekan.
- Jangan membuka preview struk pada desktop maupun mobile untuk akhir pekan.
- Jangan mengubah perilaku receipt hari Senin–Jumat.

## Constraints

- Gunakan komponen, styling, modal, dan state Alpine yang sudah ada.
- Tetap menjaga fokus modal, Escape, pemulihan fokus, dan body-scroll lock.
- Tambahkan regression test untuk markup akhir pekan dan pastikan test receipt
  hari kerja tetap lulus.
- Tambahkan satu entry Changelog melalui migration idempoten karena ini adalah
  perubahan workflow yang terlihat pengguna.

## Approaches

1. **Tampilan akhir pekan tanpa receipt (dipilih):** cabangkan render akhir
   pekan di Blade, tampilkan daftar rencana dan tombol langsung ke modal, serta
   lewati receipt preview di JavaScript. Ini memenuhi intent tanpa migrasi data
   atau perubahan endpoint.
2. **Bypass tombol mobile saja:** lebih kecil, tetapi agenda akhir pekan yang
   sudah ada tetap terlihat sebagai struk dan tidak konsisten.
3. **Hapus konsep submission akhir pekan:** paling menyeluruh, tetapi mengubah
   kontrak backend dan data historis tanpa kebutuhan produk.

## Implementasi yang dipilih

- `agenda-receipt.blade.php` akan menggunakan state daftar khusus akhir pekan
  ketika agenda sudah ada atau belum ada; receipt canvas hanya dipakai untuk
  hari kerja yang belum dikirim.
- Tombol tambah akhir pekan memanggil `openAgendaSheet($event)` secara langsung
  dan tidak merender `agenda-receipt-canvas` sebagai preview.
- Form modal memakai label “Tambah Rencana” dan “Tambahkan Rencana”; label edit
  dan label tambahan setelah submission tetap dipertahankan sesuai konteks.
- `sales-agenda-workspace.js` tetap memakai modal yang sama; tidak dibuat state
  atau modal baru.
- Test render/markup akan membuktikan tidak adanya receipt dan submit akhir
  pekan, direct-open modal, serta preservasi receipt hari kerja.

## Keputusan yang masih terbuka

Tidak ada. Keputusan produk: agenda akhir pekan langsung tersimpan tanpa proses
kirim ke Koordinator.
