# Log Implementasi PRD OASIS CRM v3

Tanggal audit awal: 20 November 2026
Basis kode: `f42aa91` (`main` sebelum pekerjaan Fase 1)
Cabang kerja: `prd-v3/fase-1-fondasi`
PRD sumber kebenaran: `docs/product/PRD_OASIS_CRM_V3.md` versi 3.2

## Aturan kerja audit

- PRD v3 menjadi sumber perilaku produk.
- Kode yang dapat dijalankan menjadi sumber perilaku saat ini.
- Perubahan user worktree yang sudah ada tidak disentuh.
- WP-0 hanya audit dan pencatatan. Belum ada perubahan product code.
- Cutover produksi tidak dieksekusi: mode graf kanonis, pemadaman sinkronisasi Sheet, pembekuan tab Talangan, penghapusan role lama, dan penghapusan kolom lama.
- Setiap aturan BR yang diimplementasikan nanti wajib punya tes yang menyebut ID BR.
- Setiap perubahan perilaku nanti wajib punya migrasi changelog idempoten dengan `version = null` bila nomor versi belum diberikan.

## Status paket kerja

| Paket | Fase | Cakupan | Status | BR utama |
|---|---:|---|---|---|
| WP-0 | 0 | Audit PRD v3, inventaris hardcode, impact map, baseline, snapshot permission | Selesai audit awal | Semua fase |
| WP-1.1 | 1 | Pengaturan Aturan: `app_settings`, resolver bertipe, audit perubahan | Belum mulai | Semua BR bertanda ⚙ |
| WP-1.2 | 1 | Feature flag PRD v3 dan helper pembacaan tunggal | Belum mulai | BR-50–BR-53, risiko transisi |
| WP-1.3 | 1 | Role `ass_manager`, atribut user, migrasi pemetaan role lama | Diblokir sampai register konflik disetujui | §4.1, D6, D21, D25 |
| WP-1.4 | 1 | Graf organisasi kanonis dan pemantauan berjenjang | Belum mulai; menunggu laporan paritas | BR-50–BR-53 |
| WP-1.5 | 1 | Jam kerja, target 480, Jumat, akhir pekan, hari libur | Belum mulai | BR-01–BR-09 |
| WP-1.6 | 1 | Tahap Lead Baru–UTJ, Gugur, kunci setelah UTJ | Belum mulai | BR-10–BR-16 |
| WP-1.7 | 1 | Status/event transaksi, alasan Mundur, NUP baru | Belum mulai | BR-20–BR-36 |
| WP-1.8 | 1 | Istilah glosarium dan navigasi | Belum mulai | §3, D15 |
| WP-1.9 | 1 | Privasi AI dan backup terpisah | Belum mulai | BR-70–BR-73 |
| WP-1.10 | 1 | Penutup Fase 1: changelog, dokumentasi, verifikasi penuh | Belum mulai | Semua fase 1 |
| WP-2.1 | 2 | Alih Lead dan antrean lead tanpa pemilik | Belum mulai | BR-54–BR-55 |
| WP-2.2 | 2 | Izin Akses Khusus | Belum mulai | BR-53 |
| WP-2.3 | 2 | Penanda perhatian | Belum mulai | §7.4 |
| WP-2.4 | 2 | Status peralihan cabang dan laporan paritas | Belum mulai | §9.3 |
| WP-2.5 | 2 | Dana Talangan di OASIS dan cicilan | Belum mulai | BR-42–BR-44 |
| WP-2.6 | 2 | Kembali ke NUP di Workspace Konsumen | Belum mulai | BR-20–BR-22 |
| WP-2.7 | 2 | Penutup Fase 2: changelog, dokumentasi, verifikasi penuh | Belum mulai | Semua fase 2 |
| WP-3.1 | 3 | WhatsApp satu nomor | Belum mulai | BR-60–BR-64 |
| WP-3.2 | 3 | Asisten AI per peran | Belum mulai | BR-70–BR-72 |
| WP-3.3 | 3 | Push PWA dan preferensi notifikasi | Belum mulai | §7.11 |
| WP-3.4 | 3 | Kalender gabungan | Belum mulai | §7.11 |
| WP-3.5 | 3 | Dashboard Pimpinan dan laporan rapat | Belum mulai | §7.12 |
| WP-3.6 | 3 | Pensiun modul transisi per cabang lulus | Belum mulai; operator menjalankan cutover | §9.3 |
| WP-3.7 | 3 | Penutup akhir: runbook cutover, dokumentasi, log | Belum mulai | Semua fase |

## Baseline verifikasi

Perintah:

```text
composer test
```

Hasil baseline pada commit `f42aa91`:

- 1.811 tests
- 1.809 passed
- 2 failed
- 13.108 assertions
- durasi 195.321 ms

Kegagalan pre-existing, tidak diperbaiki pada WP-0:

1. `Tests\Feature\SalesAgendaWorkspaceRenderTest::test_receipt_heading_matches_today_tomorrow_and_other_planned_dates`
2. `Tests\Feature\SalesAgendaWorkspaceRenderTest::test_populated_receipt_keeps_weekly_navigation_with_exact_date_links`

Keduanya berupa assertion markup HTML, bukan exception aplikasi:

- `test_receipt_heading_matches_today_tomorrow_and_other_planned_dates`: test memakai `tomorrow` sebagai hari kerja, tetapi baseline dijalankan pada Jumat 20 November 2026 sehingga `tomorrow` adalah Sabtu. Response merender heading akhir pekan, bukan `RENCANA BESOK`; assertion gagal pada `SalesAgendaWorkspaceRenderTest.php:36`.
- `test_populated_receipt_keeps_weekly_navigation_with_exact_date_links`: response memuat kelas `sales-week-navigation-day is-active is-weekend`, sedangkan assertion mencari substring persis `class="sales-week-navigation-day is-active"`; assertion gagal pada `SalesAgendaWorkspaceRenderTest.php:376`.

Keduanya tidak terbukti terkait PRD v3 dan tidak diperbaiki pada WP-0. Tetap baseline sampai paket kerja terpisah menanganinya.

## Inventaris hardcode aturan PRD

### Rencana Harian Sales / Agenda

| Aturan | Lokasi saat ini | Temuan |
|---|---|---|
| Target lama 420 menit | `app/Models/SalesDailyPlan.php:19-21` | `REQUIRED_WORK_MINUTES` dan alias `TOTAL_WORK_MINUTES` bernilai `420`. |
| Target lama 420 menit | `app/Services/SalesDailyPlanScheduleService.php:44,60,67-75` | Perhitungan, submit, draft, dan pesan error memakai konstanta model/teks `420`. |
| Jam mulai lama 09.00 | `app/Services/SalesDailyPlanScheduleService.php:12,81-87` | `START_MINUTE = 09.00`; istirahat 12.00–13.00; selesai 17.00. Tidak ada Jumat berbeda. |
| Jam mulai lama 09.00 | `app/Services/SalesDailyPlanService.php:659-662` | `allocateSchedule()` memakai 09.00, 12.00, 13.00, 17.00 langsung. |
| Target lama 420 menit | `app/Services/SalesDailyPlanService.php:297,307-309,448-450,511-513,535-552` | Validasi submit, revisi, batas durasi, dan pesan error memakai `420`. |
| Target lama 420 menit | `app/Http/Controllers/Crm/SalesDailyPlanController.php:55,76,154` | Sisa awal, validasi request, dan flash sukses memakai `420`. |
| Target lama 420 menit | `app/Http/Requests/Crm/StoreSalesDailyPlanRequest.php`, `StoreSalesDailyPlanAgendaRequest.php`, `UpdateSalesDailyPlanAgendaRequest.php`, `ReviseSalesDailyPlanRequest.php` | Rule maksimum durasi memakai `max:420`. |
| Permission description lama | `app/Support/PermissionCatalog.php:89` | Deskripsi permission masih menyebut submit tepat 420 menit. |
| Onboarding lama | `app/Services/SalesPocketbookOnboardingService.php:19` | Teks target 420, Senin–Kamis 09.00–17.00, Jumat 08.30–17.00. |
| Aturan target baru yang sudah ada di subsistem lain | `app/Services/SalesAgendaReceiptService.php:66-89,219`, `app/Models/SalesAgendaDaySubmission.php:10` | Receipt sudah memakai 480, Senin–Kamis 08.00, Jumat 07.30, istirahat sesuai PRD. Daily Plan lama memakai jalur berbeda. Dua implementasi belum disatukan. |
| Pengingat 15 menit | `app/Console/Commands/SendSalesAgendaReminders.php:65-68`, `app/Services/SalesAgendaReminderService.php:28-31`, `resources/js/crm-sync.js:324` | Nilai PRD BR-06 dan TTL sinkronisasi ada sebagai literal, belum berasal dari Pengaturan. |
| Penanda lead stale | `app/Services/CoordinatorSalesMonitoringService.php:201-204` | Default `2` hari dan batas 1–14 belum berasal dari Pengaturan, padahal §7.4 menyatakan X hari configurable. |
| Batas jam kirim rencana | Tidak ditemukan implementasi terpisah | PRD §7.4 menyebut penanda rencana belum dikirim sampai jam tertentu ⚙. Belum ada sumber Pengaturan. |
| Interval notifikasi/presence | `resources/js/notifications.js:20`, `resources/js/presence.js:9,62`, `resources/views/components/crm/page-presence.blade.php:10`, `app/Services/CollaborationNotificationService.php:69`, `app/Services/SystemHealthService.php:103,110`, `config/presence.php` | Interval 60 detik/offline threshold dipakai pada browser, Blade, service, dan config. Dicatat sebagai konfigurasi operasional terkait, bukan otomatis aturan BR tanpa bukti PRD. |
| Retensi bukti foto minimum 60 hari | `app/Support/SalesAgendaEvidenceRules.php:22`, `app/Http/Controllers/Crm/SalesAgendaEvidenceArchiveController.php:73-74`, `resources/views/crm/sales-pocketbook/evidence-archives.blade.php:8` | Batas minimum 60 hari hardcoded pada rule, validasi request, dan form purge. Ini arsip bukti agenda; belum berasal dari Pengaturan aturan. |
| Preset jam dan break pada JS agenda | `resources/js/sales-agenda-slot.js:27-28` | Break 12.00–13.00, Jumat mulai break 11.30 masih literal di frontend; harus mengikuti resolver yang sama dengan backend pada WP-1.5. |
| Preset durasi agenda | `resources/js/sales-agenda-workspace.js:360,413`, `resources/views/crm/sales-pocketbook/partials/agenda-receipt.blade.php:214` | Preset 30/60/90/120 menit literal UI. Bukan target harian, tetapi perlu dipastikan tidak bertentangan dengan Pengaturan saat jadwal dipindahkan. |

### Lead

| Aturan | Lokasi saat ini | Temuan |
|---|---|---|
| Tahap lama | `app/Models/SalesLead.php:19-35` | `documents_completed_at` dan `akad_at` masih tahap resmi, bertentangan dengan BR-10–BR-11. |
| Urutan tahap lama | `app/Models/SalesLead.php:28-35`, `app/Http/Requests/Crm/UpdateSalesLeadStageRequest.php:27,50-59`, `app/Services/SalesLeadService.php:399-405` | Validasi, maju, dan mundur membaca urutan lama sampai Akad. |
| Keterangan Akad dari lead | `resources/views/crm/sales-pocketbook/_lead-lifecycle.blade.php:202`, `resources/views/crm/sales-pocketbook/index.blade.php:342,653` | UI masih menganggap `akad_at` tahap lead. |
| Status Gugur | `app/Models/SalesLead.php` dan alur stage terkait | Tidak ada field `gugur_reason` pada model saat audit. Daftar alasan configurable belum ada. |
| Batas wewenang Sales | Policy/request/service lead lifecycle terkait | Belum disatukan dengan aturan BR-14 berbasis tahap baru dan Pengaturan. |

### Transaksi dan NUP

| Aturan | Lokasi saat ini | Temuan |
|---|---|---|
| Status transaksi | `app/Services/ConsumerOperationalService.php:24-26,69-117` | Status internal masih mencakup `Pindah Kavling`, `Reject`, dan status terminal legacy. PRD menetapkan Lanjut/Mundur; event harus dipisahkan. |
| Ganti Nama | `app/Services/ConsumerApplicationLifecycleService.php:124-179` | Implementasi masih menulis `application_status=REPLACED` dan `transaction_status=DIGANTI_KONSUMEN`; PRD mengharuskan event Ganti Nama, bukan status. |
| Mundur tanpa alasan | `app/Services/ConsumerApplicationLifecycleService.php:69-77` | `mundur()` tidak menerima alasan; `withdraw_reason` belum ditemukan di migrasi. |
| NUP | `app/Models/ConsumerNup.php:15-18`, `app/Services/ConsumerNupService.php:33-115` | Model belum memiliki `source_application_id`, `issue_id`, `entry_type`; create manual belum mensyaratkan asal UTJ/Kembali ke NUP. Konversi sudah idempoten lewat `converted_application_id`. |
| Kavling dan transaksi | `app/Services/ConsumerKavlingLifecycleService.php`, `app/Models/ConsumerApplication.php` | Mekanisme kavling atomik sudah ada dan perlu dipertahankan; perubahan event harus memakai service ini, bukan mutasi baru di controller. |

### Role, scope, dan pengaturan

| Aturan | Lokasi saat ini | Temuan |
|---|---|---|
| Role Ass. Manager | `app/Support/PermissionCatalog.php:160-269` dan role seeder terkait | `ass_manager` belum ditemukan pada catalog role permission. |
| Atribut user | `app/Models/User.php:22-47,99-116` | `sales_type`, `staff_type`, `scope_level` belum ada pada fillable/casts dan belum ditemukan pada migrasi. |
| Gabung SAS/SAM/admin | `app/Support/PermissionCatalog.php:241-269` | Role lama masih memiliki pemetaan terpisah; migrasi pemetaan belum dibuat. Tidak boleh menghapus role lama sebelum backfill dan verifikasi. |
| Mode graf organisasi | `config/organization.php`, `app/Services/OrganizationGraphModeService.php` | Mode default `legacy`; mode graph aman hanya `local/testing`, allowlist env, dan diagnostic. Ini feature gate yang tepat untuk transisi, tetapi belum menjadi mode kanonis produksi. |
| Backfill organisasi | `app/Console/Commands/OrganizationBackfill.php` | `organization:backfill --dry-run` dan controlled write sudah ada. Operator harus menjalankan dry-run/paritas; agent tidak menjalankan write/cutover produksi. |
| Health gate organisasi | `app/Services/OrganizationCutoverHealthService.php` | Gate parity, integrity, shadow, writer evidence sudah ada. Hasil eksplisit menyatakan `production_read_source=legacy` dan `production_cutover_performed=false`. |
| Scope organisasi | `app/Services/OrganizationScopeService.php`, `OrganizationGraphService.php`, `ReportingHierarchyService.php`, `SalesTeamScopeService.php` | Fasad dan fallback legacy/canonical sudah ada. Audit Fase 1 harus mengubah perilaku hanya melalui service ini, bukan scope baru di controller/view. |
| Pengaturan aturan | Tidak ditemukan tabel/model `app_settings` atau halaman aturan | Pengaturan terpusat untuk jam, alasan, batas, retensi, dan izin nama AI belum ada. Existing operational maintenance settings bukan pengganti `app_settings`. |
| Hari libur | Tidak ditemukan tabel/model `holidays` | BR-08/BR-09 belum memiliki sumber data. Weekend saat ini hanya diuji lewat `isWeekend()`/weekday. |
| Izin Akses Khusus | Tidak ditemukan tabel/model `access_grants` | Isolasi tim dan exception lintas tim belum punya model data PRD. Dijadwalkan Fase 2, tetapi scope contract Fase 1 harus tidak menghalangi. |

### AI dan privasi

| Aturan | Lokasi saat ini | Temuan |
|---|---|---|
| Asisten read-only | `app/Services/AiAssistantService.php`, `app/Services/AiToolRegistry.php` | Tool yang terdaftar hanya baca. Tool execute tetap harus diaudit ulang terhadap scope canonical dan peran per PRD. |
| Scope alat | `app/Services/AiToolRegistry.php:68-88,494-571` | Ada pemeriksaan branch/module dan project scope. Ini harus dipertahankan; jangan menambah bypass berbasis prompt. |
| Data yang diserialisasi | `app/Services/AiToolRegistry.php:514-522,549-557,635-647` | Hasil tool memakai nama, kavling, proyek, tahap, cabang. PII konsumen tidak tampak pada mapping utama, tetapi kontrak sanitasi belum eksplisit dan perlu tes BR-70. |
| Potensi kebocoran | `app/Services/AiToolRegistry.php:594-600` | Tool informasi cabang mengirim `phone` dan `email`; bukan data konsumen, tetapi perlu keputusan/tes agar data pribadi yang tidak perlu tidak masuk provider. |
| Prompt pengguna | `app/Services/AiAssistantService.php:328-350` | Pesan user dikirim sebagai message provider tanpa sanitizer field PII. BR-70 membutuhkan boundary yang tidak mengirim NIK/HP/alamat ke provider, termasuk input dan context. |
| Kunci API | `app/Services/AiProviderService.php:38-49` | API key dibaca server-side dari config dan tidak dikirim ke browser; log provider hanya menyimpan provider/model/kelas exception. |

### Retensi dan operasional

| Aturan | Lokasi saat ini | Temuan |
|---|---|---|
| Retensi activity log minimal 12 bulan | `app/Models/ActivityLog.php`, command cleanup terkait | Tidak ditemukan kebijakan cleanup/retensi activity log 12 bulan yang configurable. `config/notifications.php` hanya mengatur notifikasi terbaca 180 hari. |
| Bukti foto | `app/Services/SalesAgendaEvidenceArchiveService.php`, `app/Console/Commands/ArchiveSalesAgendaEvidence.php` | Arsip mingguan dan purge sudah ada; aturan ≥60 hari berasal dari jalur bukti agenda, bukan Pengaturan aturan PRD. |
| Backup terpisah | Dokumentasi/deployment | PRD mewajibkan backup MySQL harian di storage terpisah dan uji pulih tiap 3 bulan. Belum dibuktikan dari kode; perlu runbook/operator check, bukan migrasi yang mengarang provider. |

## Impact map: jalur kode yang akan disentuh

### WP-1.1 — Pengaturan aturan dan feature flag

- Data: migration `app_settings`, resolver bertipe, audit perubahan, cache invalidation.
- Route/controller/view: halaman Pengaturan Superadmin, named routes, validasi, inline errors.
- Service: setting resolver, feature-flag helper, consumers Daily Plan/Agenda/Reminder.
- Test: default setting, override setting, audit, flag mati/hidup, authorization direct URL.

### WP-1.3 — Role dan atribut user

- Route: `routes/web.php` pada route admin user, role, dan organisasi.
- Middleware: auth, account state, permission, module access, scope checks.
- Controller: `app/Http/Controllers/Crm/AdminUserController.php`, import/proposal controller, role administration controller.
- Request/policy: `app/Http/Requests/AdminUserStoreRequest.php`, `AdminUserUpdateRequest.php`, policy dan authorization service terkait.
- Service: `UserAdministrationService`, `UserLifecycleService`, `CrmAuthorizationService`, `PermissionCatalog`.
- Data/UI: `users`, `roles`, `role_permission`; form admin user, import template/parser, role matrix.
- Test: user authorization, role mapping, direct URL, additional role non-escalation, migration backfill, permission parity fixture.

### WP-1.2 — Feature flag PRD v3

- Data/service: feature-flag keys, typed read helper, default-off transition behavior.
- Consumers: graph mode, lead lifecycle, work-calendar and sheet migration gates.
- Test: flag defaults, enabled/disabled branches, direct endpoint behavior, no production cutover.

### WP-1.4 — Graf organisasi dan pemantauan berjenjang

- Route/controller: organization workspace, supervisor/coordinator monitoring, dashboard leadership.
- Service utama: `OrganizationGraphService`, `OrganizationScopeService`, `ReportingHierarchyService`, `SalesTeamScopeService`, `OrganizationGraphModeService`, `OrganizationCutoverHealthService`.
- Query: `SalesPocketbookController`, `SupervisorSalesPocketbookController`, `CoordinatorSalesMonitoringService`, `SupervisorSalesMonitoringService`, weekly metrics.
- UI: monitor per bawahan langsung, ringkas untuk turunan, filter hanya per bawahan langsung.
- Operator: `organization:backfill --dry-run`, parity/health report. Tidak ada production write oleh agent.
- Test: `OrganizationScopeServiceTest`, `OrganizationWorkspaceAuthorizationTest`, `OrganizationGraphModeWorkflowTest`, parity tests, direct endpoint and branch/project boundary tests.

### WP-1.5 — Kalender kerja

- Data: migration `holidays`, audit update; settings consumed from WP-1.1.
- Service: workday calendar, `SalesDailyPlanScheduleService`, `SalesDailyPlanService`, `SalesAgendaReceiptService`, reminder services.
- Existing consumers: daily plan requests/controller, `SalesDailyPlan`, permission descriptions, onboarding copy, receipt submission.
- Test: BR-01–BR-09; default values and changed setting values; Friday; weekend; national/branch holiday; exact 480; no reminder for free day; activity reminder still works on free day.

### WP-1.6 — Lead lifecycle

- Data: additive lead stage/reason fields; preserve `documents_completed_at` and `akad_at` as historical facts.
- Route/controller/request/policy: lead stage update, close Gugur, convert consumer, detail and monitoring endpoints.
- Service/model: `SalesLead`, `SalesLeadService`, `SalesLeadLifecycleService`, status history, spreadsheet bridge/sync/import adapters.
- UI: lead stage labels, Gugur reason, transaction-linked process facts, no Akad/Berkas as lead stage.
- Test: BR-10–BR-16, old-data migration/backfill, idempotent conversion, Sales lock after UTJ, coordinator/SAS exception path.

### WP-1.7 — Status/event transaksi dan NUP

- Data: additive NUP fields and `withdraw_reason`; preserve old status columns for transition.
- Service: `ConsumerApplicationLifecycleService`, `ConsumerOperationalService`, `ConsumerNupService`, `ConsumerEntryService`, `ConsumerKavlingLifecycleService`.
- Controller/request/view: Workspace Konsumen lifecycle forms and direct routes.
- Test: BR-20–BR-36, exact status set, reason required, event audit, kavling release/atomic move, immutable `id_transaksi`, historical import without fake history.

### WP-1.8/WP-1.9 — Istilah, AI privacy, backup evidence

- UI: rename only where PRD glosarium changes meaning; do not build second component system.
- AI: `AiAssistantService`, `AiToolRegistry`, `AiProviderService`, provider HTTP tests with fake responses.
- Ops: document backup contract and health check; never commit credentials or run production backup/cutover.
- Test: BR-70–BR-73; no NIK/HP/address in provider messages, same scope as screen, read-only tools, server-only secret handling.

### WP-1.10 — Penutup Fase 1

- Verify all phase 1 BR tests, changelog migrations, docs, and operator handoff before phase acceptance.

## Konflik, risiko, dan keputusan yang harus dijaga

| ID | Temuan | Dampak | Keputusan kerja |
|---|---|---|---|
| C-01 | Dua jalur rencana harian: Daily Plan lama 420/09.00 dan Receipt/Agenda yang sudah 480/08.00/07.30. | Perubahan parsial bisa membuat angka dan jadwal berbeda di layar, API, dan laporan. | Satukan melalui resolver Pengaturan dan satu kalender kerja; migrasi additive; tes kedua jalur. |
| C-02 | PRD meminta mode graf kanonis Fase 1, aturan repo melarang agent mengeksekusi cutover produksi. | Implementasi tidak boleh mengubah production read source langsung. | Bangun capability, diagnostic, feature flag, dan runbook. Operator mengaktifkan setelah dry-run/parity lulus. |
| C-03 | Lead masih menganggap Berkas Awal Lengkap/Akad sebagai tahap. | Penghapusan langsung dapat menghilangkan fakta historis. | Pertahankan kolom lama sebagai fakta/keterangan; hentikan sebagai stage baru; backfill tanpa riwayat palsu. |
| C-04 | Status transaksi legacy mencampur status dan event. | Laporan dan filter dapat berubah semantik. | Tambah event/audit dan mapping transisi; jangan drop kolom lama sebelum semua cabang lulus migrasi. |
| C-05 | NUP manual saat ini belum membuktikan UTJ dan belum punya field asal PRD. | Data NUP lama tidak otomatis bisa dikategorikan tanpa aturan backfill. | Tambah field nullable/additive; tandai data lama sebagai legacy/needs review bila asal tidak dapat dibuktikan; jangan mengarang UTJ. |
| C-06 | Kontrak BR-70 belum eksplisit pada boundary provider dan input user. | PII bisa masuk prompt/tool result walau mapping utama sudah terbatas. | Buat sanitizer teruji dan audit payload provider sebelum mengubah tool AI. |
| C-07 | Pengaturan aturan dan hari libur belum ada. | Memindahkan literal ke config env saja tidak memenuhi PRD. | Gunakan tabel `app_settings`/`holidays` sesuai §10, audit perubahan, cache scoped per request. |
| C-08 | PRD mengatakan semua angka ⚙ dari Pengaturan, tetapi bukti foto/retensi dan beberapa reminder memakai config/literal lama. | Sebagian aturan tetap sulit diubah tanpa deploy. | Inventaris ini menjadi acceptance list; tiap literal yang termasuk BR ⚙ dipindah atau diberi alasan teknis tertulis. |

### Register konflik lengkap untuk persetujuan pemilik produk

| ID | Deskripsi konflik | Bukti kode | Usulan teknis | Keputusan pemilik produk diperlukan |
|---|---|---|---|---|
| C-01 | Daily Plan lama memakai 420 menit/09.00, sedangkan Receipt/Agenda memakai 480 menit/08.00/07.30. | `app/Models/SalesDailyPlan.php:19-21`; `app/Services/SalesDailyPlanScheduleService.php:12,81-87`; `app/Services/SalesAgendaReceiptService.php:66-89`; `app/Models/SalesAgendaDaySubmission.php:10` | Satu resolver Pengaturan dan kalender kerja. Simpan `target_minutes` serta jadwal efektif pada Daily Plan baru; rencana lama tetap memakai snapshot 420. | Setujui sumber kebenaran dan aturan transisi data lama. |
| C-02 | PRD meminta graf organisasi kanonis, tetapi aturan kerja melarang agent mengaktifkan cutover produksi. | `config/organization.php`; `app/Services/OrganizationGraphModeService.php`; `app/Services/OrganizationCutoverHealthService.php`; `app/Console/Commands/OrganizationBackfill.php` | Bangun feature flag, dry-run, laporan paritas, health gate, dan proyeksi kompatibilitas. Operator mengaktifkan setelah gate lulus. | Setujui kriteria parity dan pihak yang berwenang melakukan cutover. |
| C-03 | PRD menghapus `Berkas Awal Lengkap`/`Akad` sebagai tahap Lead, tetapi data historis dan sheet lifecycle masih memakainya sebagai fakta/status. | `app/Models/SalesLead.php:19-35`; `app/Services/SalesLeadLifecycleSyncService.php:27,376-414,454-478`; `app/Services/SalesLeadSpreadsheetContract.php:34,78-86`; `resources/views/crm/sales-pocketbook/_lead-lifecycle.blade.php:202`; `resources/views/crm/sales-pocketbook/index.blade.php:342,653` | Pertahankan `documents_completed_at`, `akad_at`, link, dan tab `akad` sebagai fakta legacy. Mapping aktif: status sheet `Akad` diproses sebagai fakta transaksi tertaut, bukan stage v3; status Lead v3 dibatasi sampai UTJ/Gugur. Jangan drop kolom/tab sebelum semua cabang lulus. | Putuskan nilai `status_lead` yang dikirim balik ke sheet saat Lead sudah UTJ tetapi tab `akad` tetap aktif; putuskan apakah workbook tetap menerima `Akad` legacy selama transisi. |
| C-04 | Status transaksi legacy mencampur status dan event seperti `Pindah Kavling`, `Reject`, dan `Diganti Konsumen`. | `app/Services/ConsumerOperationalService.php:24-26,69-117`; `app/Services/ConsumerApplicationLifecycleService.php:124-179` | Tambah event/audit dan mapping transisi additive. Pertahankan kolom lama sampai migrasi cabang selesai. | Setujui mapping status legacy ke Lanjut/Mundur dan definisi laporan historis. |
| C-05 | NUP manual belum membuktikan asal UTJ/Kembali ke NUP dan belum memiliki field asal PRD. | `app/Models/ConsumerNup.php:15-18`; `app/Services/ConsumerNupService.php:33-115` | Tambah field nullable `source_application_id`, `issue_id`, `entry_type`; data lama tidak dibubuhi asal fiktif, tandai perlu tinjau. | Setujui kategori untuk NUP lama tanpa bukti asal. |
| C-06 | Boundary AI belum eksplisit menyaring PII dari input user dan hasil tool. | `app/Services/AiAssistantService.php:328-350`; `app/Services/AiToolRegistry.php:514-522,549-557,594-600,635-647` | Sanitizer teruji sebelum provider; NIK/HP/alamat dibuang; nama mengikuti toggle Pengaturan; tool tetap read-only dan scoped. | Setujui apakah email/telepon pegawai pada tool informasi cabang juga harus dibuang. |
| C-07 | Tabel Pengaturan Aturan dan hari libur belum ada; config/env tidak cukup untuk override ter-audit. | Tidak ditemukan `app_settings`/`holidays`; `app/Services/OperationalMaintenanceService.php` hanya mengelola maintenance operasional. | Buat `app_settings`, `holidays`, resolver bertipe, cache invalidation, dan activity log. | Setujui daftar key, default, batas validasi, dan siapa boleh mengubah tiap key. |
| C-08 | Angka ⚙ tersebar pada code/config/frontend, termasuk reminder 15 menit, stale 2 hari, retensi bukti 60 hari, interval 60 detik, dan break agenda. | `app/Console/Commands/SendSalesAgendaReminders.php:65-68`; `app/Services/CoordinatorSalesMonitoringService.php:201-204`; `app/Support/SalesAgendaEvidenceRules.php:22`; `app/Http/Controllers/Crm/SalesAgendaEvidenceArchiveController.php:73-74`; `resources/js/sales-agenda-slot.js:27-28`; `resources/js/notifications.js:20`; `resources/js/presence.js:9,62` | Gunakan inventory sebagai acceptance list WP-1.1/WP-1.5. Pindahkan hanya angka yang PRD tandai ⚙ ke resolver; pertahankan konstanta teknis non-BR dengan alasan tertulis. | Setujui apakah retensi bukti 60 hari dan interval presence masuk Pengaturan Aturan atau tetap kebijakan teknis. |

## Daftar aturan BR untuk tes

| Paket | Tes wajib minimal |
|---|---|
| WP-1.1 | `BR-52`, setting defaults/overrides, audit, and feature-flag access |
| WP-1.2 | Feature-flag defaults, enabled/disabled transition paths |
| WP-1.3 | role/attribute validation, legacy role mapping, no permission escalation via additional role |
| WP-1.4 | `BR-50`, `BR-51`, `BR-52`, `BR-53` boundary and direct URL |
| WP-1.5 | `BR-01`–`BR-09`, nilai default dan nilai Pengaturan yang diubah |
| WP-1.6 | `BR-10`–`BR-16` |
| WP-1.7 | `BR-20`–`BR-25`, `BR-30`–`BR-36` |
| WP-1.8/WP-1.9 | `BR-70`–`BR-73`, backup/operator checks |
| WP-1.10 | fase 1 verification and documentation checks |
| WP-2.1 | `BR-54`, `BR-55` |
| WP-2.2 | `BR-53` |
| WP-2.4 | `BR-42`–`BR-44` |
| WP-3.1 | `BR-60`–`BR-64` |

## File yang diaudit pada WP-0

- `docs/product/PRD_OASIS_CRM_V3.md`
- `app/Models/SalesDailyPlan.php`
- `app/Services/SalesDailyPlanScheduleService.php`
- `app/Services/SalesDailyPlanService.php`
- `app/Services/SalesAgendaReceiptService.php`
- `app/Services/SalesAgendaReminderService.php`
- `app/Services/CoordinatorSalesMonitoringService.php`
- `app/Models/SalesLead.php`
- `app/Services/SalesLeadService.php`
- `app/Services/SalesLeadLifecycleService.php`
- `app/Http/Requests/Crm/UpdateSalesLeadStageRequest.php`
- `app/Services/ConsumerOperationalService.php`
- `app/Services/ConsumerApplicationLifecycleService.php`
- `app/Models/ConsumerNup.php`
- `app/Models/ConsumerApplication.php`
- `app/Services/ConsumerNupService.php`
- `app/Models/User.php`
- `app/Support/PermissionCatalog.php`
- `app/Services/OrganizationGraphService.php`
- `app/Services/OrganizationScopeService.php`
- `app/Services/OrganizationGraphModeService.php`
- `app/Services/OrganizationCutoverHealthService.php`
- `app/Console/Commands/OrganizationBackfill.php`
- `app/Services/AiAssistantService.php`
- `app/Services/AiToolRegistry.php`
- `app/Services/AiProviderService.php`
- `app/Support/SalesAgendaEvidenceRules.php`
- `app/Http/Controllers/Crm/SalesAgendaEvidenceArchiveController.php`
- `resources/js/sales-agenda-slot.js`
- `resources/js/notifications.js`
- `resources/js/presence.js`
- `resources/js/crm-sync.js`
- relevant request/controller/view/test files located by route and symbol search
- `tests/Fixtures/permission_snapshot_pre_v3.json`

## WP-0 acceptance

- [x] PRD v3 read as source of truth.
- [x] Branch `prd-v3/fase-1-fondasi` created.
- [x] Baseline test run recorded, including pre-existing failures.
- [x] Hardcoded ⚙ rules inventoried.
- [x] Route/service/model/request/view/test impact mapped.
- [x] Conflicts and data-loss risks recorded.
- [x] No production cutover command executed.
- [x] No product code changed by WP-0.

## Langkah berikutnya

1. Review log ini terhadap keputusan produk dan batas migrasi.
2. Mulai WP-1.1 hanya setelah log diterima.
3. Sebelum WP-1.1, tambah migrasi additive, tes migrasi data lama, dan changelog idempoten.
4. Setelah setiap paket: focused tests, `vendor/bin/pint --dirty --test`, `git diff --check`, `npm run build` bila frontend berubah, lalu `graphify update .`.
