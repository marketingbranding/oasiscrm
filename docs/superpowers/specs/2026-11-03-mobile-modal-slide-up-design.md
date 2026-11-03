# Desain Animasi Modal Mobile dari Bawah

## Intent

Modal pada layar mobile OASIS terasa seperti bottom sheet: backdrop memudar, lalu panel naik dari bawah saat dibuka dan turun kembali saat ditutup. Desktop tetap menggunakan perilaku dan posisi saat ini.

## Goals

- Menerapkan pola pada komponen bersama `x-crm.modal`.
- Menerapkan pola yang sama pada bottom sheet agenda Sales yang memiliki markup khusus.
- Menjaga focus management, Escape, backdrop close, body-scroll lock, dan ukuran/touch target yang ada.
- Menghormati `prefers-reduced-motion` dengan transisi minimal.

## Non-goals

- Tidak mengubah route, authorization, data, atau isi modal.
- Tidak menambah library animasi.
- Tidak mengubah modal desktop atau panel kanan desktop.
- Tidak membuat drag-to-dismiss.

## Pendekatan yang Dipilih

Menggunakan CSS transition dan hook `x-transition` Alpine yang sudah tersedia. Backdrop memakai fade sekitar 150–180ms; panel mobile memakai `translateY(100%)` ke posisi normal dengan easing yang singkat. Aturan berada dalam breakpoint mobile dan tidak memengaruhi desktop. Bottom sheet agenda diberi hook transition yang sama tanpa memindahkan logika form.

Alternatif hanya menganimasikan modal standar ditolak karena membuat bottom sheet agenda tidak konsisten. Logika JavaScript baru ditolak karena tidak diperlukan untuk state yang sudah dikelola Alpine.

## Verifikasi

- Tambahkan regression/source-contract test untuk hook transition dan reduced-motion.
- Jalankan focused PHPUnit test terkait modal/workspace.
- Build Vite.
- Verifikasi browser pada sekitar 390px dan 1440px: buka/tutup modal, Escape, backdrop, focus, serta console tanpa error baru.
