# AGENTS.md — Aturan Kerja Agent di OASIS CRM

File ini adalah **satu-satunya** aturan kerja untuk AI coding agent (Claude Code, opencode, Codex, dll.) di repo ini. `CLAUDE.md` hanya memuat file ini. Jangan menulis aturan kerja di tempat lain; kalau perlu aturan baru, tambahkan di sini.

Isi file ini dibagi tiga jenis:
- **WAJIB**: aturan yang tidak boleh dilanggar.
- **FAKTA**: kondisi kode saat ini yang sudah diverifikasi.
- **TARGET**: arah perubahan menurut PRD v3.

---

## 1. Urutan Sumber Kebenaran (WAJIB)

| Pertanyaan | Sumber yang dipakai |
|---|---|
| *Bagaimana produk seharusnya berperilaku?* | `docs/product/PRD_OASIS_CRM_V3.md` (PRD v3). Aturan bisnisnya ada di §6 (BR-xx), istilah di §3, dan keputusan di §13. |
| *Bagaimana kode berperilaku sekarang?* | Kode yang bisa dijalankan: routes, models, policies, services, migrations, tests. |
| *Detail form per tahap Proses Penjualan* | `docs/product/PRD_OASIS_CRM_Consumer_Database_Platform_V2.md`, **kecuali** bagian yang direvisi PRD v3 §6.3–6.4 (NUP, status transaksi, event). |
| *Migrasi data Marison/sheet* | `MARISON_V2_TO_OASIS_MIGRATION_CONTRACT_v1.md` |
| *Standar tampilan* | `docs/UI_STANDARDS.md` |
| *Setup, deploy, operasional* | `README.md`, `HOSTINGER_DEPLOYMENT.md` |

Dokumen lain di `docs/` (audit, rencana fase lama, `plans/`) adalah **catatan sejarah**. Dokumen itu boleh dibaca untuk konteks, tetapi tidak mengalahkan PRD v3 maupun kode.

**Cara memutuskan:**
1. Jika tugas meminta penerapan item PRD v3 berlabel [Perbaikan] atau [Baru], **ubah kode agar sesuai PRD v3**. Ini bukan pelanggaran "pertahankan perilaku produksi".
2. Jika tugas tidak menyentuh item tersebut, **pertahankan perilaku kode saat ini**.
3. Jika kode dan PRD v3 berbeda di hal yang **tidak** diatur jelas oleh PRD v3: jangan menebak. Catat di `docs/product/PRD_V3_IMPLEMENTATION_LOG.md` (bagian *Konflik*), lanjutkan pekerjaan lain yang tidak bergantung pada hal itu, lalu tanyakan kepada pemilik produk.
4. **Jangan mengarang** route, slug role, slug permission, tabel, service, atau komponen. Verifikasi dulu dengan pencarian di repo dan `php artisan route:list`.

---

## 2. Bentuk Proyek (FAKTA)

- Laravel 13, PHP 8.3, Blade + Alpine.js, Tailwind CSS, Vite, SortableJS. Tidak ada React/Vue/Livewire/Redis/WebSocket.
- MySQL di produksi. SQLite untuk lokal dan PHPUnit (`:memory:`, cache/session array, queue sync, mail array).
- Routes: `routes/web.php`, `routes/auth.php`. Jadwal: `routes/console.php`. Scheduler wajib dipanggil tiap menit oleh host.
- Backend: `app/Http/Controllers`, `app/Http/Requests`, `app/Models`, `app/Policies`, `app/Services`, `app/Enums`, `app/ValueObjects`, `app/Support`.
- UI: halaman di `resources/views/crm`, komponen `resources/views/components/crm` (`x-crm.*`), modul Alpine di `resources/js` (didaftarkan di `resources/js/app.js`), token CSS di `resources/css/app.css`.
- Tes: `tests/Feature` dan `tests/Unit`.

### Perintah

```bash
composer setup                     # instal, konfigurasi lokal, migrate, build
composer dev                       # server, queue, log, Vite
composer test                      # seluruh suite
php artisan test --filter=NamaTes  # tes terfokus
vendor/bin/pint --dirty --test     # cek format PHP yang berubah
git diff --check
npm run build                      # wajib bila JS/CSS/markup Tailwind berubah
php artisan route:list
php artisan optimize:clear && php artisan view:cache   # setelah ubah route/controller/middleware/provider/Blade
```

Tidak ada skrip npm untuk lint, type-check, atau tes.

---

## 3. Alur Kerja Setiap Tugas (WAJIB)

1. **Pahami dulu.** Telusuri jalurnya: route → middleware → controller → FormRequest/policy → service → query ber-scope → view/JS → tes yang ada. Jika `graphify-out/graph.json` ada, mulai dengan `graphify query "<pertanyaan>"`.
2. **Rencanakan.** Tulis dulu daftar file yang akan diubah, aturan BR yang diterapkan, dan tes yang akan ditambah.
3. **Ubah sekecil mungkin.** Controller tetap tipis. Logika bisnis masuk ke service. Pakai komponen dan utilitas yang sudah ada.
4. **Uji.** Tambahkan tes terfokus (§9), lalu jalankan tes terkait, `pint --dirty --test`, `git diff --check`, dan `npm run build` bila frontend berubah.
5. **Catat.**
   - Tambahkan entri Changelog bila perilaku berubah (§10).
   - Perbarui README/AGENTS bila perilaku yang terdokumentasi berubah.
   - Perbarui log implementasi PRD v3 bila mengerjakan item PRD v3.
   - Setelah mengubah kode, jalankan `graphify update .` bila graphify terpasang.
6. **Jangan menyentuh perubahan lain** di working tree yang bukan milik tugasmu.

---

## 4. Otorisasi dan Scope (WAJIB)

### 4.1 Rumus otorisasi
Sebuah aksi hanya boleh dilakukan bila **semua** syarat ini terpenuhi:
1. akun aktif, terverifikasi, dan sudah mengganti kata sandi;
2. user memiliki **permission terdaftar** yang dibutuhkan;
3. user boleh masuk ke modulnya (termasuk allowlist Sales dan mode pemeliharaan modul);
4. target berada dalam scope user (cabang/proyek/tim/turunan/milik sendiri);
5. policy record mengizinkan.

Aturan ini berlaku sama untuk halaman, URL langsung, ekspor, AJAX, sinkron, aksi massal, autocomplete, notifikasi/deep link, KPI/agregat, job latar belakang, dan **alat Asisten AI**. **Menyembunyikan menu bukan otorisasi.**

### 4.2 Permission
- FAKTA: `users.role_id` adalah sumber permission. `role_user` (role tambahan) hanya untuk cek `hasRole()` lama dan **tidak memberi permission**. Superadmin mendapat wildcard hanya untuk permission yang terdaftar. Tidak ada override permission per user.
- WAJIB, saat menambah permission:
  1. daftarkan di `PermissionCatalog`;
  2. tetapkan pemetaan default per role secara sadar;
  3. deploy lewat migrasi idempoten (jangan hanya lewat seeder);
  4. tambahkan tes untuk middleware, policy, query, dan UI.
- Jangan membandingkan role mentah (`role->slug === ...`) jika permission/policy bisa menyatakan aturannya.

### 4.3 Service scope (pakai, jangan duplikasi)
- `WorkspaceAccessService`: cabang/proyek tempat user boleh bekerja (`branch_user`, `project_user`).
- `OrganizationScopeService`: irisan scope permission (own/team/assigned/branch/all) dengan workspace.
- `OrganizationGraphService`: graf atasan–bawahan (`organization_assignments`), meliputi riwayat, turunan, pencegahan siklus, dan pemindahan transaksional.
- `ReportingHierarchyService`, `SalesTeamScopeService`: fasad kompatibilitas di atas graf.

Jangan menulis ulang logika scope di controller atau Blade.

### 4.4 Role
- FAKTA (seeder saat ini): `superadmin`, `pusat`, `manager`, `branch_manager`, `supervisor`, `sales_coordinator`, `sales`, `sas`, `sam`, ditambah role lama `admin` dan `staff`.
- `pusat` **bukan** superadmin. Role ini hanya mendapat permission yang dipetakan.
- `RestrictSalesModuleAccess` membatasi Sales (role utama) ke allowlist. Jangan memperlebar allowlist tanpa sengaja. Role tambahan tidak boleh menembus batas ini.
- TARGET (PRD v3 §4):
  - role baru `ass_manager`;
  - `admin`, `sas`, `sam`, dan `staff` lama digabung ke role `staff` (Staff Admin) dengan atribut `staff_type` (`admin_cabang`/`sales`/`marketing`/`umum`). Hak akses tiap orang wajib sama dengan `tests/Fixtures/permission_snapshot_pre_v3.json`;
  - atribut `sales_type` dan `scope_level`;
  - pemantauan **berjenjang** mengikuti graf organisasi;
  - pimpinan dengan `scope_level=pusat` melihat semua cabang.

  Sampai migrasinya selesai, **kode lama harus tetap berfungsi**. Jangan menghapus role lama tanpa migrasi data dan pemetaan permission.

---

## 5. Domain dan Istilah (WAJIB)

- Gunakan **istilah di PRD v3 §3** untuk UI, nama variabel baru, laporan, dan pesan error. Label Indonesia dipertahankan. Jangan menggantinya dengan istilah SaaS/akuntansi generik.
- Invarian yang tidak boleh dilanggar:
  - Lead berakhir di **UTJ** atau **Gugur**. Lead tidak menyimpan tahap Proses Penjualan.
  - **Satu kavling hanya dipegang satu transaksi berstatus Lanjut.** NUP tidak memegang kavling.
  - `id_transaksi` tidak boleh berubah.
  - Pindah Kavling, Ganti Nama, Ganti Bank, Kembali ke NUP, Mundur, dan Koreksi Data adalah **event yang diaudit**, bukan tahap.
  - Konversi lead/NUP menjadi konsumen bersifat **idempoten**.
  - Data lama tidak diberi riwayat palsu.
- Angka aturan bisnis yang ditandai ⚙ di PRD v3 (jam kerja, target menit, daftar alasan, batas kirim WA, dll.) **harus dibaca dari Pengaturan**. Jangan menambah hardcode baru. FAKTA: nilai 420 menit dan 09.00 masih tertanam di `SalesDailyPlan::REQUIRED_WORK_MINUTES` dan service Daily Plan. Ini adalah target perbaikan.

---

## 6. Data dan Integrasi (WAJIB)

### 6.1 Google Sheets
- Kredensial Google hanya ada di konfigurasi environment/storage. Kredensial tidak boleh di-commit, di-log, atau muncul di tes.
- Konstruksi service Google bisa gagal. Di tes, **mock** service yang berhubungan dengan Google sebelum me-resolve service yang bergantung padanya.
- **Database sync** dan **Konsumen Progress sync** adalah dua sistem terpisah dengan cache dan tabel status masing-masing. **Jangan digabung.**
- Nomor baris sheet, nama, nomor HP, NIK, dan ID bisnis buatan **bukan identitas permanen**, kecuali kontrak modulnya menyatakan lain. ID baris cache Konsumen Progress berubah di setiap sinkron.
- Buku Saku lifecycle:
  - Membuat lead melakukan append ke sheet `lead` di dalam transaksi lokal. Tes tulis wajib me-mock `SalesLeadSpreadsheetWriter`, dan tes pull wajib me-mock `GoogleSheetsApiService`.
  - Identitas pull terikat per cabang. `external_sync_id`/`oasis_sync_id` stabil, sedangkan yang lain hanya konteks dan tidak boleh menautkan lintas cabang.
  - Workbook cabang JPR tidak punya `data_ceklok`. Jangan membuat fallback lintas cabang.
- Huruf besar/kecil nama tab `PSJB` berpengaruh.
- Dana Talangan saat ini memakai **satu sheet global** (tab `Talangan`) dengan tulis langsung.
- TARGET (PRD v3 §9.2–9.3): OASIS menjadi sumber utama dan sheet turun menjadi arsip. Peralihan dilakukan **per cabang** lewat `branch_migration_states` untuk data konsumen. Untuk Dana Talangan peralihannya **global**, karena sheet-nya satu. Selama transisi, jangan mematikan sinkron cabang yang belum lulus migrasi.

### 6.2 Data dan uang
- Uang disimpan sebagai decimal, bukan float.
- Data operasional yang diaudit (pengeluaran, talangan, lead, transaksi, komentar, impor) **dibatalkan atau di-soft-delete**, tidak dihapus permanen, kecuali dirancang eksplisit.
- Migrasi bersifat **tambahan**. Jangan menambah kolom non-null tanpa default atau backfill. Periksa perilaku FK saat delete terhadap kebutuhan audit. Jangan me-rollback migrasi lifecycle/permission/undangan/komentar/pengeluaran/impor setelah ada data produksi.
- XLSX hanya memakai PhpSpreadsheet yang sudah ada. OpenSpout terpasang tetapi tidak dipakai.

### 6.3 Data pribadi dan AI
- NIK disamarkan di tampilan. Membuka NIK lengkap butuh permission khusus dan dicatat.
- **NIK, nomor HP, dan alamat tidak pernah dikirim ke penyedia AI** (BR-70). Nama boleh dikirim, kecuali dimatikan di Pengaturan.
- Asisten AI hanya memakai alat **baca** dengan scope dan otorisasi yang sama dengan layar biasa. Output alat dan jawaban model diperlakukan sebagai teks tidak tepercaya. Jangan menambah alat AI yang bisa menulis data tanpa ruang lingkup produk, konfirmasi, audit, dan tes otorisasi.
- Kunci API (AI, gateway WhatsApp, VAPID) disimpan di environment atau terenkripsi di server, dan tidak pernah dikirim ke browser.

### 6.4 PWA
Service worker **tidak boleh** meng-cache halaman yang butuh login atau data CRM.

---

## 7. UI (WAJIB)

Detail lengkap ada di `docs/UI_STANDARDS.md`. Ringkasan wajibnya:
- Pakai komponen `x-crm.*` dan token CSS yang sudah ada. **Jangan membuat arsitektur kedua** untuk modal, toast, picker, tabel, notifikasi, atau konflik.
- Perilaku browser memakai modul Alpine. Pakai picker tanggal/bulan/jam yang sudah ada. Tidak ada library UI baru, migrasi Bootstrap, atau penulisan ulang ke React/Vue.
- Pertahankan identitas OASIS: padat dan operasional, sudut kotak/minim lengkung, struktur kuning/hitam, aksen modul yang terkendali, dan label status yang jelas. Jangan mendesain ulang menjadi SaaS generik.
- Form wajib punya label yang terlihat, error inline, kontrol yang bisa dioperasikan keyboard, fokus yang terlihat, dialog yang bisa ditutup dengan Escape, dan tata letak yang usable di HP. Tabel boleh di-scroll horizontal di HP.
- Aksi destruktif diberi label jelas, dikonfirmasi, dan berwarna merah semantik (bukan warna aksen modul).
- Jangan memakai `alert()`/`confirm()` bawaan browser, `x-html` untuk konten tidak tepercaya, `x-trap` (Alpine Focus tidak terpasang), atau navigasi yang hanya muncul saat hover.
- Jangan membuat menu tingkat atas untuk setiap halaman CRUD/konfigurasi. Jangan membuat sidebar terpisah per role.

---

## 8. Hal yang Dilarang (WAJIB)

- Registrasi mandiri publik.
- Otorisasi hanya lewat menu yang disembunyikan.
- Logika scope cabang/proyek/tim yang diduplikasi di controller/view.
- Mempercayai role ID, kelas morph, akses cabang/proyek, data pratinjau, atau format uang yang dikirim browser.
- URL aplikasi yang di-hardcode (pakai named route).
- Menyimpan atau mencatat di log kata sandi, token undangan, atau token reset dalam bentuk teks.
- Identitas model eksternal yang didasarkan pada nomor baris sheet.
- Dependensi baru bila yang ada sudah cukup. Jika memang perlu (mis. pustaka Web Push), tulis alasannya di deskripsi PR.
- Arsitektur kedua untuk notifikasi, toast, konflik, picker, XLSX, atau akses.
- Redis, Reverb, WebSocket, atau framework frontend baru tanpa ruang lingkup eksplisit.
- Commit `.env`, kredensial, JSON service account, atau secret.

Jika sebuah tugas memang sengaja mengubah salah satu larangan ini, tuliskan alasan, jalur migrasi, dampak kompatibilitas, dan tesnya di change set yang sama.

---

## 9. Tes (WAJIB)

- Tes Feature untuk otorisasi, scope, route langsung, ekspor, sinkron, mutasi, dan jalur gagal yang penting. Tes Unit untuk service atau parser yang terisolasi.
- Untuk setiap permission atau data ber-scope baru, tes:
  - role yang diizinkan dan yang ditolak;
  - role tambahan yang tidak menaikkan hak akses;
  - endpoint langsung;
  - permintaan ke cabang/proyek di luar akses.
- Untuk setiap aturan BR dari PRD v3, ada minimal satu tes yang **menyebut ID-nya** di nama atau komentar tes (mis. `test_br04_submit_requires_exact_target_minutes`). Untuk aturan ⚙, tes dengan nilai bawaan **dan** nilai yang diubah.
- Mock semua integrasi eksternal (Google, LLM, gateway WhatsApp, Web Push). Tes tidak boleh melakukan panggilan jaringan.

### Checklist sebelum menyatakan selesai
- [ ] Tes terkait hijau. Untuk perubahan lintas modul, `composer test` hijau.
- [ ] `vendor/bin/pint --dirty --test` dan `git diff --check` bersih.
- [ ] `npm run build` sukses bila frontend berubah.
- [ ] Migrasi bisa berjalan di database kosong **dan** di database berisi data lama (tes backfill).
- [ ] Entri Changelog ditambahkan bila perilaku berubah.
- [ ] Dokumentasi yang terdampak sudah diperbarui (README, file ini, log implementasi PRD v3).
- [ ] Tidak ada secret, data pribadi, atau URL yang di-hardcode.

---

## 10. Changelog Aplikasi (WAJIB)

Setiap perubahan yang memengaruhi perilaku, UI, alur, data, otorisasi, integrasi, konfigurasi, atau error yang terlihat pengguna wajib menyertakan **satu** entri Changelog di change set yang sama:
- Dikirim lewat **migrasi idempoten** dengan `DB::table('changelogs')->updateOrInsert([...version, title], [...])`.
- Judul dan deskripsi dalam bahasa Indonesia. Kategori: `added`, `fixed`, `changed`, atau `removed`. `created_by = null`.
- **Jangan mengarang nomor versi.** Jika tidak diberikan, `version = null`.
- `down()` hanya menghapus entri yang ditambahkan migrasi itu.
- Hasil yang berkaitan erat digabung menjadi satu entri.

Tidak perlu entri untuk perubahan yang hanya berupa tes, dokumentasi, format, instruksi agent, artefak build, atau refactor tanpa perubahan perilaku.

---

## 11. Jebakan yang Sudah Diketahui (FAKTA)

- Permintaan JSON (`Accept: application/json`/AJAX) menerima error JSON (422/401), sedangkan form biasa menerima redirect dan error di session. Pengaturan ini ada di `shouldRenderJsonWhen` di `bootstrap/app.php`. **Jangan dikembalikan** ke hanya `api/*`, karena modal AJAX bergantung padanya.
- `canViewAllBranches()` berbasis permission dan lebih luas dari satu modul. Tetap pakai policy dan query scope target.
- Middleware `role:` melihat role tambahan, sedangkan resolusi permission tidak.
- Permission modul yang kasar tidak otomatis membuat scope organisasi.
- Route Branch/Project/Kavling memakai permission kelola, tetapi controller-nya masih mengecek superadmin. Memberi permission route saja belum membuka akses bagi role lain.
- CRUD Lead Source belum punya permission atau policy khusus. Ini celah lama: rancang otorisasinya dulu sebelum mengubahnya, jangan ditiru.
- `x-crm.sortable-th` adalah sortir dropdown lama. Gunakan sortir klik langsung.
- Override ukuran kontrol mobile di layout cukup luas. Uji overlay dan tabel yang padat dengan teliti.
- Graf organisasi: mode kanonis **belum aktif**. `users.supervisor_user_id` dan `sales_coordinator_sales` masih proyeksi kompatibilitas. Mode kanonis hanya diaktifkan setelah `organization:backfill --dry-run` dan laporan paritas bersih (PRD v3 Fase 1).
- Arsip bukti foto agenda disimpan di server yang sama. Arsip ini **bukan backup bencana**.

---

## 12. Implementasi PRD v3

- Rencana dan status pekerjaan dicatat di `docs/product/PRD_V3_IMPLEMENTATION_LOG.md`. Isinya: daftar paket kerja per fase, status, PR, tes BR, konflik yang ditemukan, dan keputusan yang menunggu.
- Satu fase = satu cabang git `prd-v3/fase-N-...`. Satu paket kerja = satu atau beberapa commit yang bisa ditinjau sendiri.
- Perubahan perilaku yang berisiko (pemantauan berjenjang, tahap lead, jam kerja, peralihan sumber data) dipasang di balik **feature flag di Pengaturan** supaya bisa diaktifkan bertahap dan dimatikan lagi tanpa rollback migrasi.
- Agent **membangun kemampuan dan perintah artisan**. Agent **tidak** mengeksekusi peralihan di produksi, misalnya mengaktifkan mode kanonis, mematikan sinkron cabang, atau membekukan sheet Talangan. Itu dilakukan operator setelah meninjau laporan dry-run.

---

## 13. graphify

Repo ini punya knowledge graph di `graphify-out/` bila sudah dibangkitkan.
- Untuk pertanyaan tentang kode, mulai dengan `graphify query "<pertanyaan>"`. Gunakan `graphify path "<A>" "<B>"` untuk relasi dan `graphify explain "<konsep>"` untuk konsep tertentu.
- Jika `graphify-out/wiki/index.md` ada, gunakan untuk navigasi umum. Baca `graphify-out/GRAPH_REPORT.md` hanya untuk tinjauan arsitektur luas.
- File di `graphify-out/` yang berubah setelah hook adalah hal biasa dan bukan alasan untuk melewatinya.
- Setelah mengubah kode, jalankan `graphify update .`.
- Jika pengguna mengetik `/graphify`, gunakan skill/instruksi graphify yang terpasang lebih dulu.

---

## 14. Laravel Boost

Blok di bawah ini dibuat dan diperbarui otomatis oleh Laravel Boost. **Jangan diedit manual.** Jika ada yang bertentangan dengan bagian 1–13, yang berlaku adalah bagian 1–13.

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.3. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record a rule with `record-rule` only when the user explicitly asks for one. Instructions for the work at hand are not rules, no matter how emphatic: "remove this typo", "use X here" are work to do, not rules to record. Never record a rule on your own initiative, as a byproduct of a change, or to summarize what you just did. When the user does ask, pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Use `record-rule` rather than your native memory or notes tool, because native memory is personal and session-scoped, while only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>
