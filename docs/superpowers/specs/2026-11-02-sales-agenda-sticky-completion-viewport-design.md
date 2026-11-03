# Design Note: Tombol Penyelesaian Agenda Tetap Menempel di Viewport

## Intent

Memastikan tombol **Selesaikan agenda** tetap terlihat di bagian bawah viewport mobile ketika pengguna berpindah antara posisi scroll atas dan bawah pada detail Agenda Buku Saku Sales.

## Temuan Saat Ini

- Detail mobile dirender di dalam `#crm-main` yang memiliki `overflow-x-hidden`; computed style browser menjadi `overflow: hidden auto`, sehingga `#crm-main` menjadi scroll container internal.
- `.sales-agenda-sticky-action` berada di dalam container tersebut. CSS saat ini sudah mendeklarasikan `position: fixed`, tetapi kombinasi nested scroll dan viewport browser membuat perilaku visual tidak konsisten pada screenshot pengguna.
- Route, form completion, validasi, otorisasi, dan penyimpanan tidak perlu berubah.

## Pendekatan yang Dipilih

Menggunakan scroll viewport normal khusus halaman `.sales-mobile-detail-page`:

- `#crm-main` tidak lagi menjadi scroll container internal pada halaman detail mobile.
- Overflow horizontal tetap dicegah pada level halaman/body tanpa mengubah lebar konten.
- `.sales-agenda-sticky-action` tetap menjadi action fixed dengan inset viewport penuh.

Pendekatan memindahkan action ke root layout tidak dipilih karena menambah coupling markup form/layout. Pendekatan JavaScript clone tidak dipilih karena menambah state dan risiko duplikasi aksesibilitas.

## Goals

- Tombol terlihat pada scroll paling atas dan paling bawah.
- Tombol tetap berada di tengah dan tidak menyebabkan horizontal overflow.
- Area form memiliki ruang bawah yang cukup agar konten terakhir tidak tertutup action.
- Perilaku desktop, route, validasi, dan backend tetap tidak berubah.

## Non-goals

- Tidak mengubah label, status agenda, workflow submit, atau endpoint.
- Tidak memindahkan action ke layout global.
- Tidak menambahkan JavaScript scroll handler.

## Acceptance Criteria

1. Pada viewport 524×1002 dan 390×844, action tetap visible saat scroll `0` dan saat scroll ke akhir.
2. `#crm-main` tidak menjadi scroll container internal untuk halaman detail mobile.
3. Tidak ada horizontal overflow.
4. Regression test markup/CSS dan browser verification lulus tanpa console error.

## Verification

- Test feature detail Agenda dan kontrak CSS.
- `npm run build`.
- `vendor/bin/pint --dirty --format agent` dan `git diff --check`.
- Browser Orca: screenshot serta computed style pada scroll atas/bawah di dua viewport.
