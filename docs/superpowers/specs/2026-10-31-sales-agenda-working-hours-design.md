# Sales Agenda Working Hours

## Intent

Menyesuaikan perhitungan waktu Agenda Sales dengan jam kerja operasional baru. Modal tambah agenda harus menyambung otomatis dari agenda terakhir yang valid, sementara pekerjaan pada jam istirahat tetap dapat dicatat tetapi tidak mengurangi target kerja harian.

## Goals

- Senin–Kamis: 08.00–17.00 dengan istirahat 12.00–13.00.
- Jumat: 07.30–17.00 dengan istirahat 11.30–13.00.
- Target efektif hari kerja dihitung dari jam kerja di luar istirahat: 480 menit.
- Modal agenda baru mengisi waktu mulai dari akhir agenda terakhir yang valid.
- Default otomatis melewati jam istirahat; pengguna tetap dapat memilih jam istirahat secara sengaja.
- Agenda yang beririsan dengan jam istirahat tetap tersimpan dan diberi label/warna khusus.
- Menit yang beririsan dengan jam istirahat tidak dihitung ke target 480 menit.
- Sabtu–Minggu tetap tanpa target durasi; agenda tetap dapat dibuat dengan waktu fleksibel.

## Non-goals

- Tidak mengubah route, permission, authorization, atau schema database.
- Tidak menambah konfigurasi jam kerja admin pada tahap ini.
- Tidak menghapus atau mengubah histori agenda yang sudah tersimpan.
- Tidak menghitung pekerjaan jam istirahat sebagai target kerja normal.

## Constraints

- `SalesAgendaReceiptService` tetap menjadi sumber aturan jadwal, ringkasan, validasi slot, dan submit.
- `sales-agenda-slot.js` tetap menjadi helper murni untuk perilaku slot di browser.
- Durasi mentah agenda tetap disimpan seperti saat ini; menit efektif dihitung dari irisan waktu dengan jam istirahat.
- Agenda yang melewati jam istirahat perlu dihitung hanya pada segmen sebelum dan sesudah istirahat.
- Agenda di luar jam kerja tetap mengikuti aturan `is_outside_schedule` yang sudah ada.

## Approaches considered

### 1. Perhitungan menit efektif tanpa kolom baru — chosen

Jadwal, target, slot, dan ringkasan menghitung menit kerja efektif dengan mengurangi irisan jam istirahat. Status visual agenda jam istirahat diturunkan dari tanggal, `start_time`, dan `end_time` saat render.

**Alasan dipilih:** tidak memerlukan migrasi atau backfill, menjaga identitas agenda lama, dan satu aturan waktu dapat dipakai oleh backend, helper browser, struk, dan laporan.

### 2. Menyimpan status jam istirahat di database

Menambah kolom seperti `is_break_schedule` atau `credited_minutes`.

**Ditolak untuk tahap ini:** status dapat berubah ketika aturan jam kerja berubah dan membutuhkan migrasi/backfill; juga memperbesar permukaan query dan sinkronisasi.

### 3. Hanya mengubah tampilan

Memberi warna berbeda pada agenda jam istirahat tetapi tetap menghitung durasi mentah penuh.

**Ditolak:** bertentangan dengan aturan bahwa target tidak memasukkan jam istirahat.

## Chosen behavior

1. Jika belum ada agenda valid, default mulai mengikuti awal jam kerja hari tersebut.
2. Jika agenda terakhir berakhir sebelum istirahat, default mulai dari akhir agenda tersebut kecuali waktu itu berada di batas istirahat; slot otomatis berikutnya dimulai setelah istirahat.
3. Jika agenda terakhir berakhir setelah istirahat, default mulai dari akhir agenda tersebut.
4. Agenda yang dipilih sengaja pada jam istirahat boleh dibuat, tidak diberi `is_outside_schedule`, tetapi ditampilkan sebagai agenda jam istirahat.
5. Menit efektif sebuah agenda adalah durasi di luar interval istirahat. Agenda yang seluruhnya berada pada jam istirahat memiliki 0 menit efektif.
6. Target hari kerja adalah 480 menit. Sabtu dan Minggu memiliki target 0 dan tidak menampilkan meter target 480.

## Affected areas

- `app/Services/SalesAgendaReceiptService.php`: jadwal, target, ringkasan, validasi dan submit.
- `resources/js/sales-agenda-slot.js`: perhitungan default slot dan validasi client-side.
- `resources/views/crm/sales-pocketbook/partials/agenda-receipt.blade.php` dan `agenda-receipt-canvas.blade.php`: target, label agenda istirahat, dan ringkasan.
- `resources/css/app.css`: warna/status agenda yang beririsan dengan jam istirahat.
- Fokus test Feature dan Node untuk jadwal, target, overlap istirahat, submit, dan tampilan weekend.

## Acceptance criteria

- Agenda 09.00–10.00 membuat agenda berikutnya default mulai 10.00.
- Agenda berikutnya setelah 11.30 pada Senin–Kamis default mulai 13.00.
- Agenda 12.00–13.00 tampil dengan label khusus dan tidak menambah menit target.
- Agenda 11.30–13.30 pada Jumat menghitung hanya 30 menit efektif sebelum dan 30 menit efektif sesudah istirahat.
- Hari kerja menampilkan target 480 menit; Sabtu–Minggu tidak menampilkan target.
- Submit hari kerja hanya berhasil ketika menit efektif mencapai 480.
- Existing authorization, route, and desktop/mobile modal flow tetap berjalan.
