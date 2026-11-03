# Desain: Edit dan Hapus Rencana Agenda Sebelum Dikirim

## Intent

Sales dapat memperbaiki atau menghapus rencana agenda yang sudah dimasukkan ke struk selama struk hari tersebut belum dikirim ke Koordinator.

## Goals

- Menampilkan aksi `Edit` dan `Hapus` pada setiap baris agenda di struk draft.
- Menggunakan form bottom sheet agenda yang sudah ada untuk mode edit.
- Menghapus agenda draft dengan konfirmasi yang jelas.
- Menegakkan aturan yang sama di backend: hanya owner, scope Sales yang sah, dan belum ada `SalesAgendaDaySubmission`.
- Menjaga validasi jam kerja, overlap, target menit, optimistic locking, evidence, dan status agenda.

## Non-goals dan anti-goals

- Tidak mengizinkan edit/hapus setelah struk dikirim.
- Tidak mengubah agenda `done`, `cancelled`, atau `rescheduled` menjadi draft.
- Tidak menambah penyimpanan draft baru, localStorage, migration data, atau framework frontend.
- Tidak mengubah alur agenda Koordinator atau data production.

## Pendekatan yang dipilih

Pendekatan aksi langsung pada baris struk dipilih dibanding halaman detail terpisah atau aksi yang hanya tersedia di riwayat, karena paling sesuai dengan alur mobile dan konteks visual yang sedang dilihat Sales.

## Kontrak perilaku

- `Edit` membuka bottom sheet yang sama dengan form tambah, terisi data agenda yang dipilih.
- Penyimpanan edit memvalidasi ulang owner, tanggal, slot waktu, overlap dengan mengecualikan agenda yang sedang diedit, kapasitas target, dan optimistic lock.
- `Hapus` meminta konfirmasi lalu menghapus agenda draft; request stale atau agenda yang sudah dikirim ditolak tanpa perubahan.
- Saat `daySubmission` ada, tombol edit/hapus tidak dirender dan endpoint tetap mengembalikan penolakan backend.
- Setelah berhasil edit/hapus, halaman kembali ke workspace dengan flash/toast yang konsisten.

## Dampak implementasi

- Tambah request/route/controller/service logic untuk update rencana dan delete draft.
- Perluas partial struk dan JavaScript workspace untuk mode edit, konfirmasi hapus, focus restoration, dan state loading.
- Tambah feature/render tests untuk owner, non-owner, submitted, stale lock, overlap, dan markup mobile.
- Tambah satu entri Oasis Changelog idempoten.
