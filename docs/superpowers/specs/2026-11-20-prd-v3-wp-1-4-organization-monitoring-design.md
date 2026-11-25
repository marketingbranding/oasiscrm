# Desain WP-1.4: Graf Organisasi dan Pemantauan Berjenjang

Tanggal: 2026-11-20
Basis: merge `f1702ca` (`prd-v3/fase-1-fondasi`)
PRD: v3.3, BR-50–BR-53

## Intent

Aktifkan pemantauan organisasi berjenjang tanpa membuat lapisan otorisasi baru. Semua keputusan scope tetap berada di `OrganizationScopeService`, memakai irisan:

- hubungan atasan–bawahan dari sumber baca yang dipilih;
- cakupan kerja cabang/proyek yang sudah berlaku;
- permission dan policy yang sudah ada.

Perilaku baru dikendalikan `org_hierarchical_monitoring`, default mati. Mode sumber baca graf kanonis tetap saklar terpisah `organization.graph_mode`, dioperasikan pemilik produk melalui proses dry-run/paritas dan cutover D33.

## Goals

- BR-50: hubungan atasan–bawahan untuk pemantauan berasal dari `organization_assignments` saat mode graf kanonis aktif.
- BR-51: pemantau melihat seluruh turunan dalam cakupan kerja; bawahan langsung mendapat detail penuh, turunan lebih jauh mendapat ringkasan.
- BR-52: `manager`, `ass_manager`, dan `supervisor` dengan `scope_level=pusat` mencakup semua cabang; role `pusat` tetap mengikuti permission modul.
- Hanya Koordinator dapat memutasi data tim; pemantau lain read-only.
- Isolasi antar tim untuk Koordinator tetap berlaku, termasuk URL langsung, AJAX, ekspor, autocomplete, notifikasi/deep link, dan alat AI Chat.
- BR-53 belum diimplementasikan. Sediakan titik sambung di `OrganizationScopeService` tanpa tabel, UI, grant, atau bypass baru.
- Setiap irisan dapat direview dan dipush sendiri. Tidak merge ke cabang fase sebelum 1.4f selesai.

## Non-goals

- Tidak menjalankan cutover produksi, migrasi data produksi, backfill, atau pengaktifan flag.
- Tidak menghapus `supervisor_user_id`, `sales_coordinator_sales`, role lama, atau jalur legacy.
- Tidak membuat service/policy/scope layer kedua.
- Tidak membuat fitur Izin Akses Khusus BR-53.
- Tidak mengubah perilaku saat `org_hierarchical_monitoring=false`.

## Constraints

- Mode graf baca dan flag monitoring adalah dua saklar independen.
- Flag hidup wajib dapat diuji pada mode baca `legacy`, `shadow`, dan `graph`.
- Mode legacy dengan flag mati menjadi baseline kompatibilitas.
- Semua endpoint langsung tetap melewati permission, workspace, `OrganizationScopeService`, dan policy record.
- Data sensitif tetap mengikuti aturan yang ada; ringkasan turunan jauh tidak menambah kolom sensitif.
- Ekspor memakai scope dan tingkat detail yang sama dengan layar.
- Perubahan perilaku memerlukan migrasi changelog idempoten.
- Tes tiap BR menyebut ID BR.

## Pendekatan

### Dipilih: cutover tipis bertahap

Gunakan service yang sudah ada: `OrganizationGraphModeService`, `OrganizationGraphService`, `OrganizationScopeService`, `OrganizationGraphShadowService`, dan `OrganizationCutoverHealthService`. Tambahkan flag hanya pada titik semantik scope. Migrasikan konsumen dengan helper scope yang sama, bukan dengan aturan lokal.

Kelebihan: diff kecil, fallback jelas, mode baca dapat dibandingkan, risiko regresi terisolasi per slice.

### Ditolak: migrasi semua konsumen sekaligus

Memperbesar blast radius dan menyulitkan pembuktian bahwa flag mati tidak berubah.

### Ditolak: lapisan otorisasi paralel

Menduplikasi scope/policy dan berisiko membuat hasil layar, ekspor, AJAX, dan AI berbeda.

## Kontrak scope

`OrganizationScopeService` menjadi satu titik semantik:

1. Jika flag mati, jalur sekarang dipertahankan byte-for-byte secara perilaku.
2. Jika flag hidup dan viewer adalah `supervisor`, `branch_manager`, `manager`, atau `ass_manager`, user pemantauan berasal dari seluruh turunan graf yang valid, lalu diintersect dengan cabang/proyek/workspace/permission.
3. Jika `scope_level=pusat` pada `manager`, `ass_manager`, atau `supervisor`, cakupan cabang menjadi semua cabang aktif yang diizinkan permission; bukan semua data tanpa permission.
4. `branch_manager` tetap dibatasi workspace/cabangnya.
5. `sales_coordinator` hanya melihat anggota Sales aktif yang memiliki assignment tim aktif dan valid; Koordinator A tidak mendapat data Koordinator B.
6. Titik sambung BR-53 menerima konteks viewer/module/subject, tetapi selalu mengembalikan tidak ada grant sampai WP-2.2.
7. Aksi `manage`, `update`, `delete`, `sync`, dan mutasi lain tidak otomatis naik hak karena flag. Hanya Koordinator mendapat mutasi tim sesuai permission/policy yang sudah terdaftar.

## Irisan implementasi

### 1.4a — Pembacaan graf dan paritas

- Pastikan `OrganizationGraphModeService` memilih sumber hubungan tanpa mengubah aturan monitoring.
- Tambahkan resolver/parity report untuk membandingkan ID user legacy vs graf per viewer, module, action, dan branch context.
- Tambahkan perintah dry-run/laporan operator. Perintah tidak menulis cutover dan tidak mengaktifkan mode.
- Tes BR-50: mode legacy, shadow, graph; hasil legacy vs graph sama pada fixture paritas; mismatch dan integrity failure terlihat di laporan.
- Push slice sendiri.

### 1.4b — Semantik scope tunggal

- Tambahkan flag check di `OrganizationScopeService`, bukan di controller/view/policy konsumen.
- Implementasikan pemantau berjenjang, irisan workspace, dan `scope_level=pusat`.
- Uji flag hidup/mati pada ketiga mode baca graf.
- Tes BR-51/BR-52 dan direct URL untuk cabang/proyek di luar scope.
- Push slice sendiri.

### 1.4c — Inventaris konsumen

Inventaris dan migrasi semua konsumen yang memakai scope monitoring:

- Lead Tim;
- Rencana Harian Tim;
- Riwayat Tim;
- ekspor;
- KPI/agregat;
- autocomplete/filter options;
- notifikasi/deep link;
- alat AI Chat;
- route AJAX dan endpoint sinkron/baca terkait.

Catat file, route, service, scope/action, dan status di implementation log. Tiap konsumen mendapat tes URL langsung: flag hidup menolak/tidak menampilkan tim lain; flag mati mempertahankan baseline.

### 1.4d — Detail berdasarkan jarak

- Bawahan langsung: detail penuh sesuai permission modul.
- Turunan lebih jauh: hanya nama lead, Sales pemilik, proyek, tahap/status, dan tanggal aktif terakhir.
- Terapkan serializer/query/export yang sama untuk layar dan ekspor.
- Jangan mengandalkan penyembunyian kolom di Blade sebagai kontrol akses.
- Tes BR-51 untuk layar, AJAX, dan ekspor.

### 1.4e — Mutasi read-only

- Koordinator tetap satu-satunya role yang dapat memutasi data tim.
- Supervisor, branch manager, manager, dan ass_manager hanya baca untuk data tim saat flag hidup.
- Uji setiap endpoint mutasi relevan sebagai SPV/BM/Manager/Ass. Manager wajib 403 bila tidak memiliki izin mutasi khusus.
- Uji URL langsung dan AJAX; menu tersembunyi bukan kontrol akses.
- Push slice sendiri.

### 1.4f — Isolasi Koordinator

- Koordinator A hanya melihat anggota dan data tim A.
- Koordinator A tidak dapat melihat, mengekspor, mengubah, autocomplete, menerima deep link, atau memakai alat AI untuk data tim B di cabang sama.
- Uji URL langsung, AJAX, ekspor, policy, query scope, notifikasi, dan AI tool.
- Push slice terakhir. Hanya setelah slice ini lulus seluruh suite dan review, merge ke cabang fase.

## Verifikasi per slice

Setiap slice menjalankan:

- tes Feature/Unit terfokus dengan nama atau komentar BR;
- `vendor/bin/pint --dirty --format agent`;
- `git diff --check`;
- `php artisan optimize:clear && php artisan view:cache` bila Blade/route/provider berubah;
- `npm run build` bila frontend berubah;
- `php artisan test --compact` untuk suite terkait;
- `graphify update .` setelah perubahan kode;
- full suite sebelum 1.4f merge.

Build Vite harus memakai dependency lokal yang tersedia. Jika `node_modules` belum ada, laporkan blocker; jangan menambah dependency tanpa persetujuan.

## Changelog dan operator safety

Perubahan perilaku flag dan scope mendapat satu migrasi changelog idempoten. Dry-run/laporan hanya membaca data dan menghasilkan bukti paritas. Operator pemilik produk yang memutuskan kapan `organization.graph_mode` berpindah ke `graph`; agent tidak mengeksekusi cutover.

## Keputusan pemilik produk

1.4e read-only hanya berlaku untuk data tim Sales: Lead, rencana harian/agenda, dan riwayat tim. Modul lain tetap mengikuti permission/policy sekarang. `canEditHistoricalOwnedLead` untuk BM dan Admin Cabang tetap berlaku sampai WP-2.1 Alih Lead; keputusan ini harus ditinjau di WP-2.1.

`scope_level=pusat` tidak menambah permission. Untuk `manager`, `ass_manager`, dan `supervisor`, scope ini memperluas permission berlingkup `*_branch` ke semua cabang. Permission `*_assigned` dan `*_own` tidak diperluas.

Mode ringkas hanya berlaku untuk data tim Sales. Allowlist detail turunan jauh:

- Lead: nama Lead, Sales pemilik, proyek, tahap, tanggal aktif terakhir.
- Rencana harian/agenda: nama Sales, tanggal, total menit/target, status kirim, jumlah kegiatan, jumlah kegiatan terlambat. Tanpa isi kegiatan, catatan, foto bukti, atau lokasi.
- Riwayat tim: waktu, jenis perubahan, pelaku, objek (kode Lead/nama Sales). Tanpa nilai sebelum/sesudah.

Konsumen, Dana Talangan, dan Pengeluaran bukan data pemantauan tim; tetap memakai scope sekarang tanpa mode ringkas.

Jarak mengikuti edge `REPORTS_TO` yang berlaku pada effective date, lalu disaring oleh cakupan kerja. Pengecualian: `scope_level=pusat` pada `manager`, `ass_manager`, dan `supervisor` tidak dibatasi cabang sesuai BR-52.

## Keputusan builder

- Nama perintah artisan, nama metode detail, dan bentuk report boleh mengikuti konvensi kode saat ini selama tidak mengubah kontrak di atas.
- Konsumen yang sudah memakai `OrganizationScopeService` tidak perlu dipindah secara mekanis; hanya ubah bila hasilnya belum melewati semantik 1.4b atau detail/mutasi melanggar BR.
- Semua role/permission ditentukan dari catalog, policy, dan query yang ada; tidak memakai role ID mentah.
