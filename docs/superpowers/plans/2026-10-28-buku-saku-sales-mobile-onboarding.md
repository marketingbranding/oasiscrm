# Rencana: Buku Saku Sales Mobile-First dan Onboarding

> Status: rencana untuk ditinjau. Belum ada implementasi fitur dari rencana ini.

## Tujuan

Membuat Buku Saku Sales menjadi ruang kerja mobile-first untuk Sales lapangan, dengan:

1. Agenda sebagai alur kerja utama.
2. Visual kertas agenda dan efek sobek tetap dipertahankan sebagai identitas produk.
3. Lead Saya sebagai daftar lead yang cepat dipindai dan mudah diperbarui dari ponsel.
4. Onboarding empat langkah yang menjelaskan konsep Buku Saku tanpa menghidupkan kembali Daily Planning.

## Batasan yang tidak berubah

- Daily Planning, aturan 420 menit, dan submit plan tidak dikembalikan.
- Terminologi domain tetap Agenda, Lead Saya, Koordinator Sales, Cek Lokasi, dan istilah OASIS yang sudah ada.
- Route, policy, scope cabang/proyek/tim, optimistic locking, sinkronisasi spreadsheet, komentar, dan notifikasi tetap menjadi kontrak yang harus dipertahankan.
- Koordinator, Supervisor, Manager, Admin, dan Superadmin tetap memakai workspace monitoring masing-masing; redesign mobile utama ini ditujukan untuk workspace personal primary-role `sales`.
- Tidak menambah framework frontend, calendar library, notification center, atau penyimpanan browser sebagai sumber kebenaran.

## Keputusan UX dari referensi PDF

### Workspace personal Sales

- Header ringkas: `Buku Saku Saya`, nama Sales, KC/cabang, dan proyek.
- Dua tab utama: `Agenda` dan `Lead Saya`.
- Agenda memakai tiga konteks yang mudah dipindai: `Kemarin`, `Hari ini`, `Rencana`.
- Agenda hari ini mendapat penekanan visual kuning; agenda kemarin yang belum memiliki hasil menjadi antrean tindakan.
- Setiap kartu agenda menampilkan waktu, status, judul, kategori, dan lokasi bila ada.
- Aksi utama di mobile berada di area bawah yang mudah dijangkau, tetapi tidak menutupi konten atau browser controls.
- Detail agenda adalah halaman/route tersendiri: checklist tugas, foto bukti, hasil aktivitas, dan aksi `Selesaikan agenda`.
- Lead Saya mempunyai dua konteks: `Belum selesai` dan `Selesai`, masing-masing dengan jumlah.
- Kartu lead menonjolkan nama, proyek/tanggal/telepon, status funnel, dan aksi yang relevan.
- Form input lead memprioritaskan nama dan WhatsApp/telepon. Tanggal dan proyek tetap dikontrol server; sumber/kanal/aktivitas boleh dilengkapi kemudian untuk input cepat Sales, tanpa mengendurkan validasi flow coordinator/monitoring.
- Detail lead menampilkan progres status, informasi kontak, riwayat hasil, dan aksi `Tandai selesai` yang terpisah dari status funnel.

### Onboarding

Empat layar full-screen yang dapat di-swipe/tombol:

1. **Satu buku saku untuk kerjamu** — Agenda dan Lead Saya berada di satu tempat.
2. **Hari kemarin, hari ini, dan rencana** — agenda langsung terlihat; buka agenda untuk tugas, foto, dan hasil.
3. **Tulis di struk, lalu sobek** — menyelesaikan agenda mengirim struk ke Koordinator Sales dan menampilkan hasilnya di daftar.
4. **Lead selesai saat kamu bilang selesai** — status funnel dapat diperbarui, tetapi lead belum selesai sebelum `Tandai selesai`.

Setiap layar memiliki progress indicator, `Lanjut`, dan `Lewati`. Layar terakhir memakai `Mulai bekerja`.

## Rencana implementasi bertahap

### Tahap 1 — Kontrak domain dan migrasi data

1. Tambahkan `completed_at` dan `completed_by_user_id` pada `sales_leads` untuk membedakan selesai operasional dari status funnel. Data existing tetap belum selesai.
2. Tambahkan tabel `sales_agenda_tasks` dengan `content_item_id`, judul tugas, `is_completed`, `completed_at`, `sort_order`, `created_by`, dan timestamps. Tambahkan model `SalesAgendaTask`, relasi `ContentItem::tasks()`, casts, index, dan FK yang mempertahankan histori.
3. Tambahkan tabel progress onboarding per user, misalnya `user_onboarding_progress`, dengan identitas `user_id + flow_key + version`, `current_step`, `completed_at`, `skipped_at`, dan timestamps. Gunakan unique identity agar `skip`, `complete`, dan retry idempotent.
4. Buat satu migration changelog idempotent yang mengelompokkan perubahan mobile Buku Saku dan onboarding, dengan judul/deskripsi Bahasa Indonesia, `version` null bila tidak ada versi rilis yang diberikan, dan `down()` yang hanya menghapus entry tersebut.
5. Tambahkan model/service kecil untuk completion lead, task agenda, dan onboarding. Controller tetap tipis; policy dan service yang sudah ada tetap menjadi sumber authorization.

**Tes tahap ini:** migration dapat dijalankan berulang, FK/index benar, default lead lama tidak berubah, completion tidak mengubah `current_status`, dan progress onboarding tidak membuat duplikasi.

### Tahap 2 — Agenda mobile-first

1. Perluas `StoreSalesAgendaRequest` dan `SalesAgendaController` untuk menerima jam mulai/jam selesai yang sudah didukung kolom existing, memvalidasi urutan waktu, dan menyimpan durasi tanpa aturan 420 menit. `activity_result` tetap opsional saat membuat agenda.
2. Ubah query personal pada `SalesAgendaController::index()` dari satu tanggal/pagination tunggal menjadi konteks `yesterday`, `today`, dan `planned`; kelompokkan hasil berdasarkan tanggal. Pertahankan parameter tanggal lama sebagai kompatibilitas bila masih dipakai link existing.
3. Tambahkan route `sales-agendas.show` dan action detail yang hanya mengizinkan agenda Sales dengan `SalesAgendaPolicy`/authorization dan `SalesAgendaEvidenceAuthorizationService` yang sudah ada. Jangan menerima ID agenda tanpa pemeriksaan owner/scope.
4. Tambahkan route dan FormRequest untuk checklist tugas: tambah tugas, toggle selesai dengan optimistic token bila record diubah bersamaan, dan hapus hanya ketika agenda masih mutable. Tidak menambahkan drag-and-drop atau dependency antar tugas pada fase ini.
5. Pindahkan penyelesaian agenda ke detail: tampilkan checklist `x dari y selesai`, maksimal dua foto existing dengan thumbnail/alt text, upload mobile memakai `accept` dan `capture` yang sesuai, textarea `Hasil aktivitas *`, dan tombol sticky `Selesaikan agenda`. Efek sobek tetap dipakai pada submit dan memiliki fallback langsung untuk reduced motion.
6. Ubah halaman personal `resources/views/crm/sales-pocketbook/sales-agenda.blade.php` menjadi ringkasan tiga konteks dan kartu agenda; gunakan route detail untuk membuka pekerjaan. Form tambah agenda menjadi bottom-sheet/modal mobile dengan field yang terlihat dan label jelas.
7. Perbarui `resources/js/sales-agenda-paper.js` hanya untuk interaksi submit/tear yang aman: cegah double-submit, tetap submit jika animation tidak selesai, dan jangan mengganggu keyboard/reduced-motion.
8. Tambahkan style khusus di `resources/css/app.css` untuk day switcher, kartu agenda, kertas sobek, sticky action bar, checklist, foto, empty/loading/error/unavailable states, dan breakpoint sekitar 360/390/430 px. Reuse token dan `x-crm.modal`; jangan menimpa makna warna status dengan accent Sales.

**Tes tahap ini:** tanggal dan status grouping, create dengan/ tanpa hasil, jam invalid, agenda kosong/terlewat, detail agenda foreign/denied, task toggle/delete authorization, upload maksimal dua foto, completion wajib hasil, stale token 409, dan kontrak script tear.

### Tahap 3 — Lead Saya mobile-first

1. Sesuaikan `SalesLeadRequest` secara kontekstual: primary Sales boleh menyimpan lead dengan nama, telepon, tanggal, proyek, dan status default `no_response`; `source`, `platform`, dan `campaign_name` dapat null saat input cepat. Coordinator tetap memakai flow lengkap yang ada. Jika sumber diisi, canonicalization/snapshot tetap mengikuti kontrak workbook dan tidak menghapus snapshot lama.
2. Tambahkan completion service/action terpisah dari `SalesLeadLifecycleController`: hanya actor yang berwenang dalam scope lead boleh menandai selesai; catat activity log tanpa PII, gunakan optimistic locking, dan sediakan aksi buka kembali hanya bila keputusan produk mengharuskannya. Default fase pertama tidak perlu reopen.
3. Tambahkan filter server-side `open`/`completed`, count untuk masing-masing tab, dan preserve tab/filter/pagination pada redirect. Default personal Sales adalah `open`; monitoring tidak boleh berubah semantik hanya karena UI personal berubah.
4. Ubah kartu mobile pada `sales-agenda.blade.php` untuk menampilkan status ringkas, metadata penting, dan tombol `Lihat detail lead`. Input lead memakai bottom-sheet dengan dua aksi `Simpan` dan `Simpan & baru`; setelah save, fokus kembali ke nama untuk aksi berulang.
5. Perbarui `lead-detail.blade.php` agar sesuai referensi: breadcrumb kembali, badge selesai, status progression yang tetap memakai enum/lifecycle existing, informasi ringkas, riwayat status/hasil, `Update hasil`, dan `Tandai selesai`. Semua lifecycle downstream (konsumen, SLIK, freelance, Akad) tetap tunduk pada capability dan policy existing.
6. Hindari menampilkan seluruh form monitoring/lifecycle di layar awal mobile. Gunakan progressive disclosure; detail dan modal yang panjang harus scroll di dalam viewport, memiliki focus management, Escape, focus restoration, dan tombol minimal 44 px.
7. Pertahankan desktop table/monitoring dan tampilan coordinator/supervisor sebagai kontrak terpisah; hanya ekstrak komponen bila markup yang sama benar-benar muncul pada tiga tempat.

**Tes tahap ini:** minimal lead Sales dan default status, coordinator tetap tervalidasi, completion/duplicate retry/optimistic conflict, count dan filter open/completed, direct URL unauthorized, status funnel tidak berubah saat completion, history, mobile markup, dan role/scope matrix.

### Tahap 4 — Onboarding interaktif

1. Tambahkan `SalesPocketbookOnboardingService` untuk menentukan eligibility: akun aktif/terverifikasi, primary role `sales`, permission `sales_pocketbook.view_own`, dan flow/version yang belum selesai atau dilewati. Supplemental role, coordinator, monitoring-only user, dan user tanpa akses route tidak boleh melihat onboarding personal.
2. Tambahkan `SalesPocketbookOnboardingController` dan route protected untuk `show`, `skip`, `complete`, serta replay yang hanya dipanggil dari aksi eksplisit `Lihat panduan Buku Saku`. Endpoint write memakai validation, CSRF, authorization, dan operasi idempotent.
3. Buat view khusus onboarding, bukan modal kecil yang bergantung pada halaman CRM. Gunakan ilustrasi HTML/CSS ringan yang meniru kartu Agenda, Lead Saya, struk sobek, dan lead selesai; jangan menambah image/dependency baru.
4. Buat modul Alpine `sales-pocketbook-onboarding.js` dengan step state, keyboard next/previous, progress announcement, focus awal, focus trap bila modal, body-scroll lock, `prefers-reduced-motion`, timeout/failure fallback, dan pemulihan focus ke trigger saat replay.
5. Saat onboarding aktif, jangan tampilkan daily reminder bersamaan. Setelah `Mulai bekerja` atau `Lewati`, redirect ke workspace personal dan biarkan reminder harian mengikuti aturan `SalesDailyReminderService` yang ada.
6. Tambahkan link replay yang tidak dominan pada header/area bantuan Buku Saku. Replay tidak menghapus history dan tidak mengubah data Agenda/Lead.

**Tes tahap ini:** first visit redirect/render, step progression, skip, complete, retry/idempotency, replay, role/permission denial, reminder suppression selama onboarding, focus/ARIA contract, dan redirect aman ke named route.

### Tahap 5 — Verifikasi dan rollout

1. Tambahkan/ubah feature tests untuk setiap endpoint, query scope, migration changelog, dan rendered source contract. Jangan mengganti test existing Daily Planning menjadi fitur baru; kontrak penghapusan Daily Planning tetap harus lulus.
2. Jalankan `php artisan optimize:clear`, `php artisan view:cache`, focused PHPUnit, full PHPUnit bila perubahan query/authorization menyentuh monitoring, `vendor/bin/pint --dirty --format agent`, `npm run build`, `git diff --check`, dan `graphify update .` bila source code berubah.
3. Verifikasi browser dengan Playwright pada sekitar 390 px, 430 px, 1024 px, dan 1366 px: agenda triase, detail + upload foto, completion/tear, input lead berulang, tab selesai, onboarding skip/replay, keyboard Escape/focus, loading/empty/error/denied state, dan sticky action yang tidak menutup konten.
4. Audit `php artisan route:list` untuk middleware/order baru dan cek bahwa endpoint direct URL, export, AJAX, upload, task, completion, dan onboarding mengikuti permission/scope yang benar.
5. Pastikan tepat satu row changelog untuk entry baru dan halaman Changelog merender title/description Indonesia.

## Urutan commit yang disarankan saat implementasi

1. `test: define mobile agenda and onboarding contracts`
2. `feat: add sales agenda task and lead completion data`
3. `feat: redesign personal sales agenda for mobile`
4. `feat: simplify and complete personal lead workflow`
5. `feat: add sales pocketbook onboarding`
6. `test: verify mobile and role scope contracts`

Jangan membuat commit atau mengubah file aplikasi sebelum rencana ini disetujui.
