# Log Implementasi PRD OASIS CRM v3

Tanggal audit awal: 20 November 2026
Basis kode: `f42aa91` (`main` sebelum pekerjaan Fase 1)
Cabang kerja: `prd-v3/fase-1-wp-1-6-lead-stages`
PRD sumber kebenaran: `docs/product/PRD_OASIS_CRM_V3.md` versi 3.3 (D30–D33)

## Aturan kerja audit

- PRD v3 menjadi sumber perilaku produk.
- Kode yang dapat dijalankan menjadi sumber perilaku saat ini.
- Perubahan user worktree yang sudah ada tidak disentuh.
- WP-0 audit dan pencatatan selesai; WP-1.1/WP-1.2 runtime slice dan WP-1.3 role foundation selesai di branch ini.
- WP-1.1/WP-1.2 commits: `b2c351c`, `4ee24eb`, `47526ee`, `fe565dd`; pushed to `origin/prd-v3/fase-1-fondasi`.
- WP-1.3 commits after rebase onto `prd-v3/fase-1-fondasi`: `766a85a`, `e505d75`, `aab5101`, `ae888b7`, `e8738da`, `ec3082b`.
- WP-1.1/WP-1.2 settings page, resolver, permission migration, audit, cache invalidation, and flag facade are complete for registered initial keys. WP-1.3 role foundation is complete; consumer cutover remains pending.
- Focused verification before review fixes: 12 tests passed, 42 assertions. Review-fix verification: 13 tests passed, 46 assertions. Full-suite baseline remains 1,823 tests, 1,821 passed, 2 known failures listed below.
- Keputusan register konflik C-01–C-08 disetujui pemilik produk; C-09–C-11 ditambahkan dari review lanjutan.
- Cutover produksi tidak dieksekusi: mode graf kanonis, pemadaman sinkronisasi Sheet, pembekuan tab Talangan, penghapusan role lama, dan penghapusan kolom lama.
- Setiap aturan BR yang diimplementasikan nanti wajib punya tes yang menyebut ID BR.
- Setiap perubahan perilaku nanti wajib punya migrasi changelog idempoten dengan `version = null` bila nomor versi belum diberikan.

## Status paket kerja

| Paket | Fase | Cakupan | Status | BR utama |
|---|---:|---|---|---|
| WP-0 | 0 | Audit PRD v3, inventaris hardcode, impact map, baseline, snapshot permission | Selesai audit awal | Semua fase |
| WP-1.1 | 1 | Pengaturan Aturan: `app_settings`, resolver bertipe, audit perubahan | Selesai untuk consumer awal; aturan lain pindah bersama consumer. Commits `b2c351c`, `4ee24eb`, `fe565dd` | BR-06; semua BR bertanda ⚙ pada consumer terkait |
| WP-1.2 | 1 | Feature flag PRD v3 dan helper pembacaan tunggal | Selesai; four registered flags default mati. Commit `47526ee` | BR-50–BR-53, risiko transisi |
| WP-1.3 | 1 | Role `ass_manager`, atribut user, migrasi pemetaan role lama | Selesai untuk fondasi role, atribut, parity, command dry-run, form, dan import; D32 diselesaikan pada WP-1.10 | §4.1, BR-21, BR-25, D6, D21, D25; D32 ditunda |
| WP-1.4 | 1 | Graf organisasi kanonis dan pemantauan berjenjang | Selesai; irisan 1.4a–1.4f selesai. Tidak ada cutover produksi atau backfill write oleh agent | BR-50–BR-53 |
| WP-1.5 | 1 | Jam kerja, target 480, Jumat, akhir pekan, hari libur | Selesai; kalender Receipt/Agenda settings-backed, holiday nasional/cabang, free-day submission marker, reminder, UI, dan verifikasi penuh selesai | BR-01–BR-09 |
| WP-1.6 | 1 | Tahap Lead Baru–UTJ, Gugur, kunci setelah UTJ | Selesai implementasi; flag default mati, belum cutover produksi | BR-10–BR-14 |
| WP-1.7 | 1 | Status/event transaksi, alasan Mundur, NUP baru | Selesai implementasi; flag default mati; belum cutover produksi | BR-20–BR-36 |
| WP-1.8 | 1 | Istilah glosarium dan navigasi | Selesai; label tampilan, navigasi, ekspor, AI, notifikasi, metadata izin, dan changelog diselaraskan tanpa mengubah identifier internal | §3, D15 |
| WP-1.9 | 1 | Privasi AI dan backup terpisah | Selesai implementasi; perlu verifikasi operator restore | BR-70–BR-73, BR-81 |
| WP-1.10 | 1 | Penutup Fase 1: changelog, dokumentasi, verifikasi penuh | Selesai; laporan fase dan operator handoff tersedia; cutover produksi tetap menunggu operator | Semua fase 1 |
| WP-2.1 | 2 | Alih Lead dan antrean lead tanpa pemilik | Berjalan | BR-54–BR-55 |
| WP-2.2 | 2 | Izin Akses Khusus | Selesai; grant Lead Sales/agenda dengan expiry, turunan, workspace, edit, revoke, export, dan AI scope; commit `32a4c37`, coverage `5713bd2`, `6a8f034`, `e557619`; menunggu merge | BR-53 |
| WP-2.3 | 2 | Penanda perhatian | Selesai; commit `8956ebf` sudah di-merge ke `prd-v3/fase-2-pemantauan-data` | §7.4, BR-08, BR-09 |

### Desain singkat WP-2.3

- Pakai satu service agregat untuk definisi aktivitas terakhir Lead: `lead_date`, riwayat tahap `changed_at`, kunjungan lokasi `created_at`, alih Lead `created_at`, dan komentar/catatan manusia pada Lead `created_at`. `updated_at` tidak dipakai. Service dipakai daftar, filter, ringkasan, export, dan AI; query daftar besar memakai subquery/agregat SQL agar tidak N+1.
- Pakai `SalesAgendaSubmissionAttentionService` untuk status Rencana Harian belum dikirim. Sumber kanonis `SalesAgendaDaySubmission`; hari kerja/libur ditentukan `SalesWorkCalendarService`; batas jam baru settings-backed dengan default 10:00 dan validasi dalam jam kerja.
- Tambah index additive hanya bila belum tersedia untuk pola agregat aktivitas: `(sales_lead_id, changed_at)`, `(sales_lead_id, created_at)` pada kunjungan dan alih Lead, serta `(commentable_type, commentable_id, created_at)` pada komentar.
- Badge, filter, dan ringkasan mengikuti scope organisasi, workspace, grant WP-2.2, mode hierarki/summary WP-1.4, dan `resolveStageV3`; Sales hanya melihat Lead sendiri.
- Scheduler membuat satu `UserNotification` ringkasan per Koordinator per hari kerja dengan kunci idempoten tanggal. Tidak ada email/WhatsApp dan tidak ada notifikasi pada akhir pekan/hari libur.
- Kandidat berikutnya, bukan WP-2.3: “Kegiatan tertaut Lead” melalui `content_items.sales_lead_id` dan picker Lead pada form kegiatan.
- Bukti tes: `CoordinatorSalesMonitoringServiceTest`, `SalesAgendaSubmissionAttentionServiceTest`, `AttentionSummaryNotificationTest`, `SalesMonitoringProjectionTest`, dan tes AI fokus; mencakup badge/filter scope, aktivitas terakhir, hari libur/akhir pekan, cutoff settings-backed, UTJ/Gugur, antrean tanpa pemilik, dan idempotensi notifikasi.
- Changelog: migrasi `2026_11_22_231500_add_wp23_attention_changelog.php`; migrasi index aktivitas dan kunci unik notifikasi: `2026_11_22_224300_add_wp23_lead_attention_activity_indexes.php`.
- Sumber komentar kini tersimpan di kolom `comments.source`; migrasi mengklasifikasikan komentar lama berbasis `user_id` (`human` bila terisi, `system` bila NULL) secara idempoten. Agregat tetap hanya menghitung `human` dan mengecualikan soft-delete.
| WP-2.4 | 2 | Status peralihan cabang dan laporan paritas | Merged ke fase-2 pada `6479a081` | §9.3 |
| WP-2.5 | 2 | Dana Talangan di OASIS dan cicilan | Paket A berjalan; desain disetujui | BR-42–BR-44 |
| WP-2.6 | 2 | Kembali ke NUP di Workspace Konsumen | Merged ke fase-2 pada `657f0ac` | BR-20–BR-22 |
| WP-2.7 | 2 | Penutup Fase 2: changelog, dokumentasi, verifikasi penuh | Belum mulai; dikerjakan setelah WP-2.8 lalu WP-2.9 | Semua fase 2 |
| WP-2.8 | 2 | Riwayat Tim: gabungan perubahan Lead dan agenda seluruh turunan | Selesai di `prd-v3/wp-2.8-riwayat-tim`; review scope dan batas 92 hari selesai | BR-50–BR-52 |
| WP-2.9 | 2 | Penautan Lead ↔ Konsumen | Direncanakan setelah WP-2.8, sebelum WP-2.7; saran + konfirmasi manusia dari OASIS, pilihan Tanpa Lead/Data Lama, jangkar `[SYS] ID Konsumen`/`ID Konsumen Awal`, uji di SALINAN workbook dengan lead dummy | BR terkait penautan Lead ↔ Konsumen |
| WP-3.1 | 3 | WhatsApp satu nomor | Belum mulai | BR-60–BR-64 |
| WP-3.2 | 3 | Asisten AI per peran | Belum mulai | BR-70–BR-72 |
| WP-3.3 | 3 | Push PWA dan preferensi notifikasi | Belum mulai | §7.11 |
| WP-3.4 | 3 | Kalender gabungan | Belum mulai | §7.11 |
| WP-3.5 | 3 | Dashboard Pimpinan dan laporan rapat | Belum mulai | §7.12 |
| WP-3.6 | 3 | Pensiun modul transisi per cabang lulus | Belum mulai; operator menjalankan cutover | §9.3 |
| WP-3.7 | 3 | Penutup akhir: runbook cutover, dokumentasi, log | Belum mulai | Semua fase |

## Keputusan WP-2.2

- Subjek grant memakai `subject_user_id` dan `include_descendants`; kasus utama pengganti Koordinator saat cuti.
- Modul hanya `sales_leads` dan `sales_daily_plans`; `can_edit` hanya Koordinator Sales, tanpa Alih Lead.
- `expires_at` wajib, dibatasi `access_grants.max_days` default 90 hari; grant aktif dievaluasi saat query.
- Cabut memakai `revoked_at`, `revoked_by`, alasan, audit, dan notifikasi in-app. Grant tidak melewati workspace grantee dan tidak bergantung pada flag pemantauan hierarki.

## WP-1.6 — Tahap Lead v3

Status: implementasi selesai di branch `prd-v3/fase-1-wp-1-6-lead-stages`. Flag `lead_stages_v3` tetap default mati; operator belum melakukan cutover produksi.

- BR-10–BR-11: kolom `lead_stage` additive, tahap kanonis Lead Baru–UTJ, Gugur, backfill kompatibilitas tanpa riwayat palsu, serta Berkas/Akad tetap fakta historis. Bukti: `tests/Feature/SalesLeadStageV3MigrationTest.php`, `tests/Feature/SalesLeadStageV3Test.php`, `tests/Feature/SalesLeadStageV3CompatibilityTest.php`.
- BR-12: Follow-up tidak ditambahkan sebagai tahap. Hasil Cek Lokasi `follow up` kini masuk daftar terbatas `Perlu dihubungi` pada Buku Saku Sales dengan query scope Lead yang sama. Bukti: `tests/Feature/SalesLeadSiteVisitResultsTest.php::test_br12_follow_up_visit_appears_in_perlu_dihubungi_list_without_new_lead_stage`.
- BR-13: Gugur memakai daftar alasan `sales_lead.gugur_reasons`, audit `sales_lead_stage_histories`, idempotensi operation UUID, dan penolakan setelah UTJ. Pull status Sheet `Gugur` tidak mengubah tahap OASIS saat flag aktif.
- BR-14: Sales tidak dapat mengubah Lead setelah UTJ/Gugur; policy tetap memeriksa scope workspace dan permission. Role tambahan tidak menaikkan hak. Pull `Gugur` dari Sheet dicatat sebagai rekonsiliasi `remote_gugur_owned_by_oasis`. Bukti: `tests/Feature/SalesLeadStageAuthorizationTest.php`, `tests/Feature/SalesLeadLifecycleSyncTest.php::test_br14_v3_pull_does_not_import_gugur_from_sheet`.
- Spreadsheet: kontrak `lead` menerima status `UTJ`/`Gugur`; ID sinkronisasi tetap dipertahankan. Tab `akad` dibaca sebagai fakta transaksi, bukan tahap Lead. Bukti: `tests/Feature/SalesLeadStageSpreadsheetCompatibilityTest.php`, `tests/Feature/SalesLeadLifecycleSyncTest.php::test_akad_requires_valid_date_is_idempotent_and_does_not_downgrade`.
- Flag-off: `lead_stages_v3` default `false`; `tests/Feature/SalesLeadStageV3CompatibilityTest.php::test_br10_and_br14_flag_off_keeps_legacy_status_form_and_json_behavior` memastikan jalur legacy tetap berjalan.
- Dry-run lokal: `php artisan sales-lead:stage-v3-backfill --dry-run` menghasilkan tabel kosong dan `Kasus perlu tinjau: 0` pada database lokal bersih; perintah read-only menolak eksekusi tanpa `--dry-run`.
- Tes fokus terakhir: 60 tests passed, 311 assertions untuk stage, bridge/lifecycle sync, Gugur, contract, authorization, dan compatibility. Perubahan juga mencakup filter Lead aktif flag-on, proteksi mutasi setelah UTJ/Gugur, pull tahap v3 dari Sheet, push Gugur opsional, serta backfill query builder.
- Migration changelog idempoten: `2026_11_21_130000_add_lead_stages_v3_changelog.php`, `2026_11_21_114841_add_lead_follow_up_list_changelog.php`.
### Langkah operator sebelum mengaktifkan `lead_stages_v3`

Runbook untuk WP-3.7:

1. Tambahkan opsi `Gugur` ke dropdown `status_lead` pada setiap workbook cabang.
2. Jalankan `php artisan sales-lead:stage-v3-backfill --dry-run` di salinan DB produksi. Tinjau semua `Kasus perlu tinjau` sebelum mengubah data.
3. Aktifkan flag bertahap, mulai dari satu cabang. Pantau sync issue `lead_stage_backward`, `remote_gugur_owned_by_oasis`, dan `sheet_missing_gugur_option`.

Dengan flag hidup, status `UTJ` dari Sheet diterima sebagai klaim tahap Sales tanpa menunggu Data Konsumen. `UTJ` terverifikasi dibaca dari fakta transaksi.

Jangan aktifkan flag atau mematikan sinkronisasi sebelum review selesai.

Batas: tidak mengubah alih Lead, NUP, event transaksi, role migration, pemadaman sinkronisasi Sheet, atau aktivasi flag produksi.

## WP-1.5 — Kalender kerja Receipt/Agenda

Status: implementasi berjalan. Receipt/Agenda memakai `SalesWorkCalendarService` sebagai resolver bersama dengan nilai runtime dari `AppSettingCatalog` untuk target, jam masuk/pulang, dan istirahat Senin–Kamis/Jumat. Daily Plan legacy tetap memakai target 420 menit dan tidak dimigrasikan.

- BR-01–BR-04: defaults Receipt/Agenda 480 menit, jam Senin–Kamis 08.00–17.00, Jumat 07.30–17.00, break-aware effective minutes, exact target submit, dan override settings diuji di `SalesWorkCalendarServiceTest` serta `SalesAgendaReceiptWorkflowTest`.
- BR-08–BR-09: tabel `holidays` mendukung libur nasional dan cabang, CRUD Superadmin berada di Pengaturan, scope cabang diuji, target submission dan reminder plan tidak dipicu pada hari bebas; pencatatan agenda tetap tersedia.
- Frontend membaca payload `data-schedule` dari resolver server; test client menguji perubahan jam/target tanpa literal client sebagai sumber utama.
- Semua test kelas Agenda yang memakai tanggal relatif memakai `Tests\\Concerns\\FreezesSalesAgendaTime` dengan waktu `2026-11-18 10:00:00`; test eksplisit tetap dapat mengubah waktu sendiri.
- Migration changelog idempoten: `2026_11_21_090401_add_sales_agenda_calendar_changelog.php`.

### Ruling WP-1.5

- `work_hours_v3` dihapus dari `AppSettingCatalog`, `FeatureFlagTest`, dan resolver test. Receipt/Agenda sudah menjadi jalur aktif sesuai C-01; gating tidak diperlukan karena perilaku live sudah sesuai PRD. Daily Plan legacy tetap memakai jalur 420 menit.

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

WP-0 tidak memperbaiki failure. WP-1.5 akan mengunci tanggal tes memakai `Carbon::setTestNow`, karena perilaku ini terkait aturan akhir pekan BR-08.

Verifikasi WP-1.4 pada Sabtu, 21 November 2026:

- Baseline `f1702ca` (sebelum WP-1.4): 8 tes gagal dari 8 tes yang diuji.
- WP-1.4 `3284c69`: 8 tes gagal dari 8 tes yang diuji.
- Nama dan hasil identik pada kedua commit: `SalesAgendaEvidenceTest::test_agenda_records_and_evidence_are_preserved_in_the_direct_workspace`, `SalesAgendaOnlyWorkspaceTest::test_sales_workspace_renders_receipt_date_context_and_add_plan_action`, `SalesAgendaWorkspaceInteractionMarkupTest::test_mobile_modal_transitions_cover_shared_modal_and_agenda_sheet`, `SalesAgendaWorkspaceInteractionMarkupTest::test_draft_receipt_exposes_edit_delete_actions_and_submitted_receipt_hides_them`, `SalesAgendaWorkspaceRenderTest::test_receipt_heading_matches_today_tomorrow_and_other_planned_dates`, `SalesAgendaWorkspaceRenderTest::test_existing_draft_shows_a_resume_action_and_resume_query_opens_receipt`, `SalesAgendaWorkspaceRenderTest::test_populated_receipt_keeps_weekly_navigation_with_exact_date_links`, dan `SalesAgendaWorkspaceRenderTest::test_today_navigation_marks_only_the_selected_week_day_as_active`.
- WP-1.4 dengan waktu dikunci sementara ke `2026-11-18 10:00:00` memakai bootstrap PHPUnit tidak di-commit: 8 tes lulus dari 8 tes yang diuji.
- Kesimpulan: 8 failure adalah baseline bergantung hari Sabtu, bukan regresi WP-1.4 atau perubahan `OrganizationScopeService`.
- Kriteria WP-1.5: semua tes agenda wajib menetapkan waktu uji sendiri dengan `Carbon::setTestNow` dan suite agenda harus kembali ke 0 failure tanpa bergantung hari eksekusi.

Latest verification after WP-1.1/WP-1.2 and review fixes:

- Focused settings/flags suite: 13 passed, 46 assertions.
- Review regression coverage: browser-rendered field names use `settings[<key>]` with dots encoded as `__`; failed submissions retain encoded old input and matching errors.
- Resolver regression coverage: DB read failure returns catalog default without caching; recovery reads stored value without `Cache::flush`.
- Full suite baseline: 1,823 tests, 1,821 passed, 2 failures; same two known `SalesAgendaWorkspaceRenderTest` baseline failures above.
- `vendor/bin/pint --dirty --format agent`: passed.
- `npm run build`: passed.
- `git diff --check`: passed.
- `php artisan route:list --name=admin.app-settings`, `php artisan optimize:clear`, and `php artisan view:cache`: passed.
- WP-1.3 focused role suite: 13 tests passed, 74 assertions.
- WP-1.3 former staff-related regressions are fixed: legacy primary `staff` permission fallback and denial for unknown non-null `staff_type`.
- WP-1.3 full suite: 1,837 tests, 1,835 passed, 2 failed, 13,286 assertions; both failures are `SalesAgendaWorkspaceRenderTest::test_receipt_heading_matches_today_tomorrow_and_other_planned_dates` and `SalesAgendaWorkspaceRenderTest::test_populated_receipt_keeps_weekly_navigation_with_exact_date_links`. Full suite remains not green.
- WP-1.3 final checks: `vendor/bin/pint --dirty --format agent`, `git diff --check`, `php artisan optimize:clear`, `php artisan view:cache`, and `graphify update .` passed.
- No production cutover executed. `oasis:roles-v3-migrate` production execution waits for owner-reviewed dry-run on a production database copy (D33).
- Review fixes: deployed RBAC migration `2026_10_20_000001_add_rbac_policy_foundation_roles.php` restored unchanged; legacy role profiles are completed by `2026_11_20_210003_complete_prd_v3_legacy_role_profiles.php`. Staff permission checks memoize one legacy profile per `User` instance. User create/update and XLSX import reject legacy admin/sas/sam selection, require `staff_type` for staff, and allow `scope_level=pusat` only for manager/ass_manager/supervisor.

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

Status: selesai untuk fondasi runtime dan halaman Superadmin; consumer migration tetap paket berikutnya.

- Main files: `database/migrations/2026_11_20_000001_create_app_settings_table.php`, `app/Models/AppSetting.php`, `app/Support/AppSettingCatalog.php`, `app/Services/AppSettingResolver.php`, `app/Services/AppSettingService.php`, `app/Http/Requests/Crm/UpdateAppSettingsRequest.php`, `app/Http/Controllers/Crm/AppSettingController.php`, `resources/views/crm/app-settings/index.blade.php`, `routes/web.php`.
- Permission/audit: `system.settings_manage` registered in `PermissionCatalog`, idempotent permission/changelog migration, existing Superadmin mapping, per-key `ActivityLog` event `app_setting_updated`.
- Registered rule keys: `sales_agenda.reminder_lead_minutes` default `15` (`BR-06`) and `sales_monitoring.stale_lead_days` default `2`. Missing rows use code defaults; stored values are typed, validated, cached, and invalidated after commit.
- Authorization: direct GET/PUT routes require registered permission, system administration middleware, primary Superadmin, active account, and non-impersonating mutation.
- Tests: `tests/Unit/AppSettingResolverTest.php` and `tests/Feature/AppSettingTest.php`; focused suite covers defaults, overrides, BR-06, direct access denial, supplemental-role non-escalation, invalid/unknown values, service boundary validation, audit, and contact-field exclusion.
- Not included: remaining PRD ⚙ consumers, `holidays`, Daily Plan/Receipt migration, and production cutover.

### WP-1.2 — Feature flag PRD v3

Status: selesai untuk shared default-off helper; consumers remain gated for later packages.

- Main files: `app/Support/FeatureFlag.php`, shared `AppSettingCatalog`/`AppSettingResolver`.
- Canonical registered keys in this implementation: `org_hierarchical_monitoring`, `lead_stages_v3`, and `transaction_events_v3`; all default `false`.
- Tests: `tests/Unit/FeatureFlagTest.php` covers exact key set, default-off behavior, and explicit true override through shared resolver.
- Scoped rollout follow-up: `FeatureFlag::enabled()` is rejected for `lead_stages_v3` and `transaction_events_v3`; callers must use `enabledForBranch()` or `branchScope()`.
- `branchScope()` returns `all`, `none`, or selected branch IDs. Pocketbook stage filters classify legacy stage fields versus V3 `lead_stage`; mixed branch queries apply each interpretation by `branch_id`.
- Before guard: `enabled()` converted scoped array values to `false`, so mode `branches` incorrectly forced every global caller to legacy behavior. Commit `759b3d6` had no global PHP service callers, but Blade pocketbook callers remained and could misrender or fail after guard; fixed in this follow-up.
- No consumer branch, graph mode activation, sheet cutover, or production rollout executed.

### WP-1.3 — Role dan atribut user

Status: selesai untuk fondasi role, atribut, permission parity, dan command migrasi terkontrol. Tidak ada production cutover.

- Implementasi: role `ass_manager` memakai mapping permission `manager`; `users.sales_type`, `users.staff_type`, dan `users.scope_level` nullable serta di-cast sebagai string; mapping permission Staff Admin memilih `staff_type` dan mempertahankan snapshot pre-v3.
- Legacy mapping: `admin→admin_cabang`, `sas→sales`, `sam→marketing`, `staff lama→umum`; `oasis:roles-v3-migrate --dry-run` read-only; eksekusi aktual transaksional, idempoten, mempertahankan ID user dan supplemental membership, lalu menandai role legacy tidak aktif. `scope_level` Staff Admin selalu `cabang` (atau tetap null bila tidak disentuh); `pusat` tidak diinferensikan dari `branch_id` null.
- BR/D mapping: §4.1 role/atribut foundation; BR-21/D21 menjaga ID user dan supplemental membership saat transisi; BR-25/D25 memetakan `scope_level` dari penempatan cabang; D6 menjaga transisi operator-controlled lewat dry-run tanpa mutasi. Permission parity diuji terhadap `tests/Fixtures/permission_snapshot_pre_v3.json`; supplemental role tidak menaikkan permission.
- Focused tests: `tests/Feature/PrdV3RoleAttributesTest.php`, `tests/Feature/PrdV3RolePermissionParityTest.php`, `tests/Feature/PrdV3RoleMigrationCommandTest.php` — atribut/role, parity exact, supplemental-role non-escalation, migration backfill, dry-run no-write, idempotent mapping, scope backfill, legacy primary `staff` permission fallback, and unknown non-null `staff_type` denial. Result: 16 tests passed, 82 assertions before final precedence fix; latest affected suite result: 52 tests passed, 336 assertions. `PrdV3RolePermissionParityTest::test_br_staff_type_permissions_match_pre_v3_snapshot` compares each canonical `staff_type` permission set (`admin_cabang`, `sales`, `marketing`, `umum`) against `tests/Fixtures/permission_snapshot_pre_v3.json`; `test_br_supplemental_role_does_not_elevate_staff_permissions` verifies supplemental role isolation.
- Former staff-related regressions are fixed. Full-suite verification: `composer test` / PHPUnit JUnit result `1,837 tests, 1,835 passed, 2 failed, 13,286 assertions`; the only failures are `SalesAgendaWorkspaceRenderTest::test_receipt_heading_matches_today_tomorrow_and_other_planned_dates` and `SalesAgendaWorkspaceRenderTest::test_populated_receipt_keeps_weekly_navigation_with_exact_date_links`. These are baseline date-dependent failures scheduled for WP-1.5; WP-1.3 keeps same failure count and names. Full suite remains not green; no claim of full-suite success.
- Snapshot permission fixture adalah artefak database yang dikomit (`tests/Fixtures/permission_snapshot_pre_v3.json`), bukan bukti staging/production karena identitas database sumber tidak diketahui. Fixture distribution: 3 admin, 1 sas, 1 sam, 2 staff. Ini berbeda dari dry-run lokal SQLite: 8 admin_cabang (`[2,3,4,5,6,7,8,9]`). Dry-run lokal terbaru dijalankan setelah tes; hasil rinci dicatat pada output command, bukan dianggap data produksi. Production migration not executed.
- Form user dan bulk import sudah dikerjakan di WP-1.3. D32 selesai di WP-1.10 melalui dua migrasi terpisah: `2026_11_21_230001_add_consumer_migration_reconcile_permission.php` dan `2026_11_21_230002_add_sas_consumer_input_permission.php`. Graf organisasi kanonis dan pemantauan berjenjang tetap WP-1.4; role production migration tetap menunggu dry-run operator.

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

Status: desain disetujui; irisan 1.4a–1.4f sedang berjalan. Tidak ada cutover produksi atau backfill write oleh agent.

#### Keputusan WP-1.4

- 1.4e read-only hanya berlaku untuk data tim Sales: Lead, rencana harian/agenda, dan riwayat tim. Modul lain tetap mengikuti permission/policy saat ini.
- `canEditHistoricalOwnedLead` untuk Branch Manager dan Admin Cabang tetap berlaku sampai WP-2.1 Alih Lead; keputusan ini wajib ditinjau ulang pada WP-2.1.
- `scope_level=pusat` tidak menambah permission. Untuk `manager`, `ass_manager`, dan `supervisor`, atribut ini hanya memperluas scope permission `*_branch` ke semua cabang. Permission `*_own` dan `*_assigned` tidak diperluas.
- Ringkasan turunan jauh hanya berlaku untuk data tim Sales. Allowlist Lead: nama Lead, Sales pemilik, proyek, tahap, dan tanggal aktif terakhir. Allowlist rencana harian/agenda: nama Sales, tanggal, total menit/target, status kirim, jumlah kegiatan, dan jumlah kegiatan terlambat. Allowlist riwayat tim: waktu, jenis perubahan, pelaku, dan objek. Nilai sebelum/sesudah tidak ditampilkan.
- Jarak mengikuti edge `REPORTS_TO` yang saat ini efektif, lalu disaring dengan scope workspace. Pengecualian: `scope_level=pusat` untuk `manager`, `ass_manager`, dan `supervisor` tidak dibatasi cabang sesuai BR-52.
- 1.4a hanya menghasilkan laporan parity/dry-run read-only. Laporan harus machine-readable dan tidak mengaktifkan source mode. Pemilik produk mengoperasikan `organization.graph_mode`; agent tidak melakukan cutover produksi atau backfill write.
- D33, yaitu dry-run salinan production yang ditinjau pemilik, masih pending. Jangan menyatakan cutover produksi sudah dilakukan.
- `canEditHistoricalOwnedLead` dicatat sebagai agenda review WP-2.1.

### Keputusan WP-2.1 — Alih Lead

1. BR-55: deactivate Sales tidak diblokir. Akses dicabut seketika; form menampilkan jumlah lead aktif dan peringatan. Lead aktif non-UTJ/Gugur masuk antrean `Lead tanpa pemilik aktif` milik Koordinator dari tim aktif terakhir Sales. Jika tidak ada Koordinator aktif, hanya Superadmin dapat menangani antrean.
2. Tim Koordinator berarti assignment aktif saat aksi alih. Pengecualian hanya untuk menentukan pemilik antrean berdasarkan tim aktif terakhir Sales nonaktif.
3. Alih massal atomik. Kegagalan menyebut lead penyebab.
4. Notifikasi hanya in-app dan activity log. Jenis notifikasi kanonis: `lead_transferred_to_me`, agar dapat dipakai push pada WP-3.3.
5. `canEditHistoricalOwnedLead` dicabut untuk Branch Manager dan Admin Cabang; keduanya read-only. Perubahan pemilik hanya melalui Alih Lead Koordinator atau antrean Superadmin saat tidak ada Koordinator aktif.

#### Bukti implementasi WP-2.1

- Review fixes: `sales_user_id` tetap menyimpan Sales nonaktif; antrean hanya penanda. Transfer antrean memakai writer tunggal `SalesLeadTransferService::transfer`, menutup antrean dengan `closed_by`/`closed_at`, dan mencatat history/activity/notifikasi dalam transaksi sama. Stage `NULL` masuk antrean. Assignment aktif (`ended_at IS NULL`) diprioritaskan sebelum riwayat terbaru. Pull Sheet tidak menimpa owner lokal; konflik `sales_pic` dicatat sebagai `remote_owner_conflicts_local_transfer`.

- Route registry: sebelum WP-2.1, `php artisan route:list --except-vendor` menghasilkan 349 route aplikasi dan 176 route yang cocok dengan `config/oasis_modules.php` (`sales_pocketbook` mencakup `sales-leads.*`). Sesudah WP-2.1, hasilnya 351 route aplikasi dan 178 route cocok. Perubahan route hanya penambahan: `POST buku-saku-sales/leads/transfers` (`sales-leads.transfers.store`) untuk alih tunggal/massal dan `GET buku-saku-sales/leads/unowned-queue` (`sales-leads.unowned-queue`) untuk antrean. Tidak ada route dihapus. Angka 178 bukan route baru seluruhnya; dua route baru menaikkan jumlah route registry Buku Saku Sales dari 176 menjadi 178.
- Deactivate, akses mantan Sales, antrean tim terakhir, fallback Superadmin, dan terminal exclusion: `SalesLeadDeactivationQueueTest::test_br55_deactivation_is_immediate_and_active_lead_enters_last_team_queue`, `SalesLeadDeactivationQueueTest::test_br55_without_active_coordinator_leaves_queue_for_superadmin_only`, `SalesLeadDeactivationQueueTest::test_br55_deactivation_excludes_utj_and_gugur_leads`.
- Tim aktif, alasan Pengaturan, atomik massal, dan notifikasi in-app: `SalesLeadTransferServiceTest::test_br54_coordinator_transfers_owned_lead_inside_active_team_and_notifies_target`, `SalesLeadTransferServiceTest::test_d27_coordinator_cannot_transfer_to_sales_outside_active_team`, `SalesLeadTransferServiceTest::test_br54_bulk_transfer_is_atomic_and_names_invalid_lead`.
- Riwayat `sales_lead_transfers` immutable: `SalesLeadTransferContractTest::test_br54_transfer_history_cannot_be_changed_or_deleted`.
- `canEditHistoricalOwnedLead` BM/Admin Cabang read-only dan endpoint 403: `SalesLeadHistoricalReadOnlyTest::test_br54_branch_manager_and_admin_cabang_cannot_edit_historical_owned_lead`, `BranchTransferHistoricalAccessTest::test_original_branch_admin_and_branch_manager_can_edit_historical_lead`, `BranchTransferHistoricalAccessTest::test_original_branch_admin_and_branch_manager_can_update_historical_lead_through_http_without_reassigning_owner`.
- Penolakan Sales, Supervisor, Branch Manager, Manager, dan Admin Cabang pada URL langsung serta JSON: `SalesLeadTransferServiceTest::test_br54_sales_supervisor_branch_manager_manager_and_admin_cabang_cannot_transfer_by_direct_url_or_json`.

- Route/controller: organization workspace, supervisor/coordinator monitoring, dashboard leadership.
- Service utama: `OrganizationGraphService`, `OrganizationScopeService`, `ReportingHierarchyService`, `SalesTeamScopeService`, `OrganizationGraphModeService`, `OrganizationCutoverHealthService`.
- Query: `SalesPocketbookController`, `SupervisorSalesPocketbookController`, `CoordinatorSalesMonitoringService`, `SupervisorSalesMonitoringService`, weekly metrics.
- UI: monitor per bawahan langsung, ringkas untuk turunan, filter hanya per bawahan langsung.
- Operator: `organization:backfill --dry-run`, parity/health report. Tidak ada production write oleh agent.
- Test: `OrganizationScopeServiceTest`, `OrganizationWorkspaceAuthorizationTest`, `OrganizationGraphModeWorkflowTest`, parity tests, direct endpoint and branch/project boundary tests.

#### WP-1.4 Task 4 — Inventaris consumer monitoring Sales (1.4c–1.4d)

Status: selesai 1.4c–1.4d. Flag `org_hierarchical_monitoring` tetap default-off; tidak ada cutover produksi.

- Lead Tim / monitoring coordinator: `CoordinatorSalesMonitoringService` dipakai oleh `CoordinatorSalesLeadWorkspaceController` pada route `sales-pocketbook.index`; `resolve`, `todayWorkspace`, `salesDetail`, dan `agendaBase` memakai irisan `OrganizationScopeService` + `WorkspaceAccessService` + `SalesTeamScopeService`. Filter `sales_id` forged diuji lewat direct URL dan ditolak (BR-51).
- Supervisor/Admin Branch monitoring: `SupervisorSalesMonitoringService` dan `AdminBranchSalesMonitoringService` sudah memakai `OrganizationScopeService` untuk branch/project/user scope. Tidak diubah.
- Rencana Harian Tim/agenda: coordinator monitoring memakai shared scope. Sales personal agenda `SalesAgendaController::index` sudah membatasi owner ke user sendiri dan branch/project ke authorization scope; baseline flag-off tidak membuktikan bypass baru, sehingga controller tidak diubah.
- Lead options AJAX: `SalesLeadOptionController` sebelumnya hanya memeriksa workspace branch. Saat flag aktif, sekarang memeriksa `OrganizationScopeService::branchIds($user, 'sales_pocketbook', 'view')`; saat flag mati, jalur lama tetap. Direct AJAX branch luar scope diuji (BR-51).
- Export: coordinator export memanggil `CoordinatorSalesMonitoringService::resolve(..., false)`; supervisor export memakai monitoring service; export bersama `sales-pocketbook.export` memakai projection yang sama untuk Admin Cabang. XLSX diuji lewat `PhpSpreadsheet\IOFactory`, termasuk header dan nilai ringkasan.
- Notifikasi/deep link dan komentar: `NotificationController`, `CommentableAccessService`, dan `CommentMentionService` memakai policy/shared scope; tidak diubah.
- Team history route/service: tidak ditemukan. Team History tetap absent; tidak diinventasikan atau diinventalkan.
- Sales monitoring AI tool: tidak ada tool khusus pemantauan Sales di `AiToolRegistry` dan tidak ditambahkan. `AiToolRegistry` tetap memiliki 8 generic read-only tools.
- Konsumen, Dana Talangan, Expenses: tidak dipindah ke distant-summary mode; consumer yang ada tidak ditambah.
- 1.4d distant-summary projection implemented: `SalesMonitoringProjectionService` menerima viewer, owner ID, type, dan row; saat flag mati atau jarak 0–1, mengembalikan model lama. Saat flag hidup dan jarak >1, mengembalikan allowlist Lead/agenda tanpa model penuh. `ReportingHierarchyService::distance` memakai sumber legacy/shadow/graph sesuai mode. Coordinator, Supervisor, dan Admin Branch memakai hasil projection identik untuk layar dan export.
- Forbidden distant fields tested: phone, notes, agenda title, location, activity result, evidence. Direct URL/export boundary tests remain in consumer suites.
- Exact notification/deep-link coverage: `UniversalCommentNotificationTest::test_br51_sales_notification_deep_link_rechecks_target_scope`, `test_open_is_recipient_only_marks_read_redirects_with_fragment_and_reauthorizes`, dan `test_notification_service_skips_inactive_and_inaccessible_recipients`; coverage menguji recipient-only access, target reauthorization, scope rejection, read state, redirect fragment, dan recipient filtering.
- Round 2 projection fixes: Coordinator `actionableLeads`, `failedSyncLeads`, detail week items, dan backlog memakai shared projection; lead activity memakai latest status-history timestamp; agenda summary menghitung seluruh late activity owner/date; collection projection melakukan cache distance dan receipt summary per viewer/owner/date; Coordinator/Supervisor export summary dipetakan ke header yang sesuai.
- Round 3 HTTP/export coverage: flag-on diuji untuk Coordinator, Supervisor, dan Admin Cabang pada hierarchy distance >1; layar hanya menampilkan nama/status atau ringkasan agenda, sementara nomor telepon, catatan, judul agenda, lokasi, hasil, dan bukti tetap tersembunyi. `sales-pocketbook.index`, `coordinator-leads.export`, Supervisor exports, dan `sales-pocketbook.export` membaca projection contract; flag-off test suite tetap memakai model detail lama.
- Export date boundary fixed: projected summary date strings are normalized before PhpSpreadsheet conversion.
- Full-suite result: fresh `php artisan test --compact` dengan `CACHE_STORE=array` menghasilkan 1.887 test, 13.473 assertion, 8 failure, 0 error. Jumlah failure tetap sama dan tetap terbatas pada Sales personal workspace markup/evidence; belum ada perbandingan parent-branch sebelum/sesudah.
- Changelog: migrasi idempoten `2026_11_21_000002_add_sales_monitoring_projection_changelog.php` menambahkan satu entri perubahan ringkasan pemantauan, default flag tetap mati.

#### WP-1.4 Task 5 — Batas mutasi baca-saja (1.4e)

Status: selesai. Saat `org_hierarchical_monitoring` aktif, `SalesLeadPolicy` menolak mutasi Lead tim dari Supervisor, Manager, Ass. Manager, Branch Manager, dan Admin Cabang melalui URL form maupun JSON; Koordinator tetap mengikuti batas tim yang sudah ada. `SalesLeadCompletionService` juga menolak bypass `manage_own` yang stale. `canEditHistoricalOwnedLead` untuk Branch Manager/Admin Cabang tetap dipertahankan. Flag mati mempertahankan perilaku lama. Admin Cabang hanya-baca untuk Lead saat flag hidup sesuai matriks PRD dan snapshot permission: role ini tidak memiliki `sales_pocketbook.manage_own`, `sales_pocketbook.manage_team`, atau `sales_pocketbook.manage_all`.

- Tidak ada route Daily Plan aktif atau route Team History khusus; tidak menambah route baru.
- Riwayat Tim belum ada di kode. Review WP-1.4 menetapkan paket kerja baru Fase 2: **WP-2.8 Riwayat Tim**, gabungan perubahan Lead dan agenda seluruh turunan, detail penuh untuk bawahan langsung, satu baris ringkas untuk turunan jauh, filter per bawahan langsung, dan tetap mematuhi flag `org_hierarchical_monitoring`.
- Modul Work Planner dan modul non-pemantauan tidak diubah.
- Tes BR-51: `tests/Feature/SalesMonitoringReadOnlyMutationTest.php`, suite terkait 59 tes lulus.
- Changelog: migrasi idempoten `2026_11_21_000003_add_sales_monitoring_read_only_changelog.php`.

#### WP-1.4 Task 6 — Isolasi Koordinator lintas endpoint (1.4f)

Status: selesai. Saat `org_hierarchical_monitoring` aktif, Coordinator hanya menerima pilihan Sales dari tim aktifnya pada autocomplete Lead. URL langsung dan JSON untuk export, komentar, serta monitoring Lead asing tetap ditolak oleh scope yang sudah ada. Tidak ada tool AI khusus pemantauan Sales atau route Team History; keduanya tidak ditambahkan.

- Bypass ditemukan: `SalesLeadOptionController` mengembalikan daftar Sales dari sheet tingkat cabang tanpa penyaringan tim. Saat flag aktif, daftar `sales` kini diiriskan dengan `CoordinatorLeadTeamService::currentSales()` dan cabang target. Flag mati tidak berubah.
- Tes BR-53: `SalesMonitoringScopeBoundaryTest` menguji autocomplete JSON, export direct/JSON, komentar direct/JSON, dan Lead options cabang luar.
- Changelog: migrasi idempoten `2026_11_21_000004_add_sales_monitoring_coordinator_isolation_changelog.php`.

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

### WP-1.7 — Status/event transaksi dan NUP

Status: implementasi selesai di branch `prd-v3/wp-1.7-transaction-nup`. Flag `transaction_events_v3` tetap default mati; tidak ada production cutover.

- BR-20–BR-25: NUP dan lifecycle transaksional tersedia. NUP dari Lead wajib berasal dari Lead berstatus UTJ dan tidak memegang kavling; konversi NUP ke transaksi membutuhkan kavling, idempoten melalui `converted_application_id`, menolak kavling di luar scope atau yang sudah dipegang transaksi Lanjut, dan Kembali ke NUP hanya menerima transaksi Lanjut. NUP menyimpan provenance `entry_type`, `source_application_id`, dan `issue_id`; data lama tanpa provenance dibaca sebagai `legacy`. Bukti: `tests/Feature/ConsumerV2ProcessTest.php`.
- BR-30–BR-32: status transaksi kanonis `LANJUT`, `MUNDUR`, `DITUTUP`; status legacy dipetakan untuk pembacaan tanpa menghapus kolom lama. `Mundur` memerlukan alasan dari Pengaturan, melepas kavling, mempertahankan tahap/proses, dan menulis event bertipe terpisah dari histori proses. Bukti: `tests/Feature/ConsumerCanonicalDomainContractTest.php`, `tests/Feature/ConsumerLifecycleHttpTest.php`, `tests/Feature/ConsumerOperationalTest.php`.
- BR-33–BR-34: Ganti Nama dan Pindah Kavling menulis event transaksi bertipe, mempertahankan `id_transaksi`, menjaga relasi replacement, dan menolak perpindahan ke kavling terisi tanpa mutasi setengah jalan. Ganti Bank juga menulis event tanpa mengganti histori proses bank. Bukti: `tests/Feature/ConsumerLifecycleOrchestrationTest.php`, `tests/Feature/ConsumerOperationalTest.php`.
- BR-35–BR-36: PSJB dan SLIK dapat dicatat dalam urutan fleksibel; data historis masuk dari posisi proses saat ini tanpa membuat riwayat palsu. Pemetaan status transaksi legacy hanya tersedia sebagai laporan read-only. Bukti: `tests/Feature/ConsumerV2ProcessTest.php`, `tests/Feature/ConsumerTransactionStatusBackfillCommandTest.php`.
- Exact migrations: `database/migrations/2026_11_21_140000_add_canonical_transaction_status_fields.php` menambah `closed_by_event` dan `withdraw_reason` pada `consumer_applications` serta changelog idempoten; `database/migrations/2026_11_21_150000_add_transaction_event_types.php` menambah `event_type` dan index `consumer_stage_events_application_event_time_index` serta changelog idempoten; `database/migrations/2026_11_21_160000_add_nup_provenance_fields.php` menambah `entry_type`, `source_application_id`, `issue_id`, index, FK nullable, dan changelog idempoten; `database/migrations/2026_11_21_164320_enforce_active_and_sold_kavling_assignment_uniqueness.php` menegakkan satu assignment `active`/`sold` yang belum dilepas per kavling dengan preflight sebelum perubahan dan index baru sebelum index lama; `database/migrations/2026_11_21_173947_add_wp17_transaction_status_safety_changelog.php` mencatat perlindungan pembacaan status legacy.
- Feature flag: `transaction_events_v3` terdaftar di `app/Support/AppSettingCatalog.php` dengan default `false`; jalur flag-off mempertahankan perilaku legacy dan menolak penulisan event transaksi v3. Bukti: `tests/Unit/FeatureFlagTest.php`, `tests/Feature/ConsumerOperationalTest.php`, `tests/Feature/ConsumerLifecycleOrchestrationTest.php`.
- Dry-run: `php artisan consumer:transaction-status-v3-backfill --dry-run` menghasilkan JSON pemetaan status legacy, kategori, kasus perlu tinjau, dan daftar nilai `transaction_status` DISTINCT beserta jumlah/pemetaan; command menolak eksekusi tanpa `--dry-run`. `php artisan consumer:kavling-holder-audit` melaporkan duplicate holder dan gagal non-zero bila ada. Tidak ada command write/backfill yang dijalankan.
- Verification: focused consumer suite 88 tests passed, 346 assertions. Tidak ada production cutover: flag tidak diaktifkan, status legacy tidak di-backfill, sinkronisasi Sheet tidak dimatikan, dan data produksi tidak disentuh.
- Langkah operator sebelum mengaktifkan `transaction_events_v3`: (1) jalankan `consumer:kavling-holder-audit` pada salinan DB produksi sebelum deploy migrasi WP-1.7; bila ada duplikat, rapikan lebih dulu karena migrasi akan menolak; (2) jalankan `consumer:transaction-status-v3-backfill --dry-run` pada salinan DB produksi, lalu tinjau semua nilai DISTINCT dan kasus `needs_review`, termasuk `CLOSED`; (3) aktifkan flag pada satu cabang uji terlebih dahulu.
- Conflicts/open limits: C-04/C-05 tetap berlaku sampai mapping dry-run ditinjau per cabang; status legacy dan kolom lama dipertahankan. NUP legacy tanpa provenance tetap `legacy` dan perlu review; data lama tidak diberi riwayat palsu. Pengaturan alasan Mundur memakai resolver yang sudah ada, tetapi migrasi belum memindahkan seluruh aturan transaksi lain ke Pengaturan. Jalur baca status tak dikenal kini aman (`LANJUT` + `needs_review`), sedangkan `CLOSED` tetap perlu tinjau. Batas assignment DB memakai implementasi driver-specific SQLite partial index/MySQL generated column; migration abort sebelum drop index bila preflight menemukan duplicate holder.

### WP-1.8/WP-1.9 — Istilah, AI privacy, backup evidence

- WP-1.8: label modul dan navigasi utama memakai `Tugas & Konten`; area rencana sales memakai `Rencana Harian Sales`, `Rencana Harian Tim/Cabang`, dan `Kegiatan`; route, tabel, enum, key request, serta nilai data internal tidak diubah.
- WP-1.9: `AiProviderService` menyaring NIK, nomor HP, email, alamat, dan telepon dari payload provider pada satu titik; nama dikendalikan `ai.send_names` (default aktif). `oasis:database-backup` memakai dump gzip dan retensi Pengaturan 7 hari sebagai alat manual pra-cutover; backup utama memakai Hostinger. Pemulihan wajib diuji operator memakai runbook.
- UI: rename only where PRD glosarium changes meaning; do not build second component system.
- AI: `AiAssistantService`, `AiToolRegistry`, `AiProviderService`, provider HTTP tests with fake responses.
- Ops: document backup contract and health check; never commit credentials or run production backup/cutover.
- Test: BR-70–BR-73; no NIK/HP/address in provider messages, same scope as screen, read-only tools, server-only secret handling.

### WP-1.10 - Penutup Fase 1

Status: selesai pada branch `prd-v3/wp-1.10-fase1-closeout`; merge phase branch menunggu review.

- D32 inventory: `consumer_progress.manage` dipakai input Data Konsumen/NUP/Proses Penjualan dan event di `routes/web.php`, FormRequest, controller, service, navigation, Blade; juga dipakai rekonsiliasi Marison pada route `admin.marison-migrations.*`, `ConsumerMigrationReconciliationRequest`, dan `ConsumerMigrationReconciliationService`. Karena rekonsiliasi di luar scope D32, commit pertama memisahkannya ke `consumer_migration.reconcile`; role yang sebelumnya memiliki `consumer_progress.manage` menerima izin baru. Commit kedua memberi `consumer_progress.manage` ke profil `sas` saja.
- D32 tests: `tests/Feature/PrdV3D32ConsumerInputTest.php`, `tests/Feature/PrdV3RolePermissionParityTest.php`, dan `tests/Feature/ConsumerMigrationReconciliationTest.php`. SAS tested own branch/project, foreign direct URL/JSON denial, Marison reconciliation denial, exact snapshot delta, and unchanged Admin Cabang/SAM/Umum profiles.

- Semua paket WP-1.1–WP-1.9 dicatat; WP-1.6, WP-1.7, dan graf organisasi tetap menunggu gate/cutover operator.
- Laporan verifikasi dan operator handoff: `docs/product/PRD_V3_PHASE_1_CLOSEOUT_REPORT.md`.
- `composer test`: 2003 tests, 14163 assertions; Pint, diff check, build, migrasi kosong, dan graphify pass.
- Backup Hostinger menjadi backup utama; command OASIS hanya backup manual pra-cutover.

### WP-2.4 — Paritas status transaksi Workspace

Status: Selesai setelah Paket A, B, dan C.

Ringkasan Paket WP-2.4:
- Paket A — state migrasi cabang dan guard input/sinkron: `e574127`.
- Paket B — laporan paritas cabang, snapshot, matching bertingkat, metrik, UI, ekspor, dan tes: `adb4642`.
- Paket C — gate cutover berbasis snapshot LULUS/freshness, override pemilik dengan audit, rollback, status paritas di halaman migrasi, dan runbook: `c9412b5`.

WP-2.4 ditutup pada branch `prd-v3/wp-2.4-c-cutover-gate` dan merged ke `prd-v3/fase-2-pemantauan-data` pada `6479a081`. Cutover produksi tetap dilakukan operator, bukan agent.

Guard input OASIS kini mencakup `ConsumerEntryService`, `ConsumerNupService`, seluruh mutasi publik `ConsumerOperationalService`, `ConsumerApplicationLifecycleService` (Mundur, Pindah Kavling, Ganti Konsumen), dan `ConsumerProcessService` (Garansi, Kendala). Pengecualian sengaja: `ConsumerPasteImportService`, importer proses historis, importer Marison, dan `ConsumerMigrationReconciliationService` tetap boleh menulis data status `migrasi` sebagai jalur migrasi/impor; jalur ini bukan input Workspace manual.

Paket A branch migration state (`e574127`) menetapkan state cabang dan blokir input Workspace berdasarkan status cabang; hash lama `bfc4c28` diperbaiki. Workspace Konsumen bawaan OASIS tetap dapat menulis pada status yang diizinkan. Inventaris boundary untuk Paket B/C dan WP-3.6:

- Cermin sheet: `DatabaseSheetSyncService`, `DatabaseSheetWriteService`, `KonsumenProgressSyncService`, `SalesLeadLifecycleSyncService`; `oasis_penuh` menolak tulis sheet atau melewati sinkronisasi/cache sheet.
- Bawaan OASIS: `ConsumerEntryService`, `ConsumerOperationalService`, `ConsumerNupService`, `ConsumerApplicationLifecycleService`, `ConsumerKavlingLifecycleService`; `oasis_penuh` tidak memblokir input `consumer_*`.
- Default state `sheet`; resolver read-only fallback tidak menulis; operator transition only.

Tab dan hitungan Mundur di Workspace masih membaca `consumer_applications.transaction_status` mentah. Laporan paritas dan dashboard wajib memakai pemetaan kanonis per baris: `REJECT SLIK`/`REJECT BANK` dan status reject terkait → `MUNDUR`, status Ganti Nama/Kembali ke NUP → `DITUTUP`, dan status aktif → `LANJUT`; nilai tak dikenal masuk `needs_review`. Perbaikan harus menjaga laporan gabungan lintas cabang dan tidak mengubah kolom legacy sebagai efek samping.

## WP-2.5 — Dana Talangan di OASIS

Status: Paket B berjalan; dry-run/confirm dan pembayaran awal sudah diimplementasikan.

Paket B berjalan:
- Dry-run backfill tidak menulis audit. `--confirm` menampilkan `DITOLAK` untuk ID tidak ada, sudah tertaut, atau bukan `KANDIDAT`, lalu mengembalikan exit code gagal.
- Workspace Konsumen menyediakan pencarian nama/kavling dengan scope cabang/proyek; pemilihan mengisi relasi dan data Dana Talangan. Input manual tetap tersedia.
- Pembayaran mendukung verifikasi langsung default, edit, soft delete, audit, status `lunas` otomatis dari total terverifikasi, pencabutan saat total turun, lebih bayar, dan bukti privat.
- Baseline suite sebelum Paket B: 2147 tes / 14618 assertions. Setelah perbaikan ini: 2158 tes / 14674 assertions.
- Tes fokus Dana Talangan saat ini: 9 tes pembayaran, seluruhnya lulus.
- Pembayaran memakai integer sen, tanpa ketergantungan bcmath. Nominal kosong tidak memicu Lunas; pembayaran belum terverifikasi tidak dihitung; Lunas manual tidak disentuh; status otomatis menyimpan status manual sebelumnya; bukti lama dihapus saat diganti dan dipertahankan saat soft delete.

Paket A review fix sudah di-push untuk review.

Review fix Paket A:
- Suite awal branch: 2144 tes / 14607 assertions.
- Form dan import sekarang menerima `id_transaksi`/`id_psjb` opsional. Audit bridge menunjukkan kontrak/fixture sheet `Talangan` saat ini hanya memiliki 16 header bisnis tanpa kedua kolom ID; bridge tetap kompatibel dan tidak memetakan header yang belum ada.
- `--apply` menerapkan `PASTI`; `--confirm=ID,ID` hanya menerapkan `KANDIDAT`. `PERLU DITINJAU` dan `TIDAK DITEMUKAN` ditolak.
- Setiap dry-run/apply/confirm dicatat di `activity_log` dengan ID talangan, aplikasi, kavling, kategori, alasan, dan aktor console.
- Kode kavling dinormalisasi dengan trim, huruf besar, dan penghapusan spasi/tanda hubung.
- Tes backfill menggunakan label BR-42; mencakup apply nyata, kandidat confirm, penolakan non-kandidat, konflik ID, dan normalisasi kavling.

Kondisi yang sudah ada:
- `DanaTalangan` sudah menyediakan CRUD, import, export, scope cabang/proyek, soft delete, activity log, dan `project_id`.
- Bridge global sheet `Talangan` sudah menyediakan push/pull, mode bertahap, reconciliation, dan sync status.

Tambahan yang direncanakan:
- Paket A: relasi `consumer_application_id`/`kavling_id`, backfill aman `--dry-run` dan `--apply` hanya untuk kategori `PASTI`.
- Paket B: pembayaran terverifikasi sederhana, bukti privat opsional, sisa tagihan, lebih bayar, dan status Lunas otomatis.
- Paket C: laporan/ekspor tanpa PII dan flag sumber utama per cabang, default off; bridge global melewati baris cabang yang aktif flag.

Aturan penting: tidak ada tebakan nama saja, tidak ada approval berlapis, flag tidak diaktifkan oleh agent, dan bukti pembayaran tidak dikirim ke AI.

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
| C-01 | Daily Plan lama memakai 420 menit/09.00, sedangkan Receipt/Agenda memakai 480 menit/08.00/07.30. | `app/Models/SalesDailyPlan.php:19-21`; `app/Services/SalesDailyPlanScheduleService.php:12,81-87`; `app/Services/SalesAgendaReceiptService.php:66-89`; `app/Models/SalesAgendaDaySubmission.php:10` | Jalur Receipt/Agenda menjadi acuan. Daily Plan lama tetap memakai snapshot 420. Sebelum WP-1.5 dilaporkan route aktif dan jumlah data per tabel. | Disetujui. |
| C-02 | PRD meminta mode graf kanonis Fase 1, tetapi agent tidak boleh mengaktifkan cutover produksi. | `config/organization.php`; `app/Services/OrganizationGraphModeService.php`; `app/Services/OrganizationCutoverHealthService.php`; `app/Console/Commands/OrganizationBackfill.php` | `organization:backfill --dry-run` tanpa konflik tak terjelaskan; semua gate health lulus; ID terlihat per user legacy/graf sama minimal 7 hari. Pemilik produk menjalankan cutover. | Disetujui (D33). |
| C-03 | PRD menghapus `Berkas Awal Lengkap`/`Akad` sebagai tahap Lead, tetapi sheet lifecycle masih memakainya. | `app/Services/SalesLeadLifecycleSyncService.php:376-415`; `app/Services/SalesLeadSpreadsheetContract.php:34,78-86` | Tulis balik sheet lead maksimal `UTJ`/`Gugur`. Tab `akad` tetap dibaca sebagai fakta transaksi selama transisi, bukan tahap lead. | Disetujui. |
| C-04 | Status transaksi legacy mencampur status dan event. | `app/Services/ConsumerOperationalService.php:24-26,69-117`; `app/Services/ConsumerApplicationLifecycleService.php:124-179` | `Pindah Kavling` → `LANJUT` + event. `Reject` → `MUNDUR` dengan alasan proses atau `Reject (data lama)`. `DIGANTI_KONSUMEN`/`REPLACED` → `DITUTUP` + `closed_by_event=GANTI_NAMA`. DITUTUP hanya Ganti Nama/Kembali ke NUP; bukan aktif/gugur. | Disetujui (D31). |
| C-05 | NUP manual belum memiliki asal UTJ/Kembali ke NUP. | `app/Models/ConsumerNup.php:15-18`; `app/Services/ConsumerNupService.php:33-115` | NUP lama tanpa bukti memakai `entry_type=legacy`, perlu tinjau, belum dihitung UTJ sampai konfirmasi admin. | Disetujui. |
| C-06 | Boundary AI belum menyaring seluruh kontak dari payload provider. | `app/Services/AiAssistantService.php:328-350`; `app/Services/AiToolRegistry.php:594-600` | Hapus semua nomor HP dan email dari payload: konsumen, pegawai, cabang, input, dan tool informasi cabang. NIK/alamat tetap dilarang. | Disetujui. |
| C-07 | Tabel `app_settings` dan halaman Pengaturan Aturan belum ada. | Tidak ditemukan `app_settings`; `app/Services/OperationalMaintenanceService.php` hanya maintenance operasional. | Tambah resolver bertipe, audit, cache invalidation. Hanya Superadmin boleh mengubah Pengaturan. | Disetujui. |
| C-08 | Angka aturan dan teknis tersebar di code/config/frontend. | `app/Console/Commands/SendSalesAgendaReminders.php:65-68`; `app/Services/CoordinatorSalesMonitoringService.php:201-204`; `app/Support/SalesAgendaEvidenceRules.php:22`; `resources/js/presence.js:9,62` | Pengingat 15 menit (BR-06) dan stale 2 hari masuk Pengaturan. Retensi bukti 60 hari dan interval presence 60 detik tetap konstanta teknis; alasan didokumentasikan. | Disetujui. |
| C-09 | Penggabungan role wajib mempertahankan hak efektif per user dengan empat jenis Staff Admin. | `tests/Fixtures/permission_snapshot_pre_v3.json`; `app/Support/PermissionCatalog.php`; `database/seeders/RoleSeeder.php` | `admin→admin_cabang`, `sas→sales`, `sam→marketing`, `staff lama→umum`; tes exact permission parity. Commit terpisah menambah hak input NUP/Proses Penjualan SAS. Konversi sebelum UTJ oleh Admin Cabang, SAS, Koordinator. | Disetujui (D30, D32). |
| C-10 | Status Ditutup belum kanonis pada transaksi. | `app/Services/ConsumerOperationalService.php:24-26`; `app/Services/ConsumerApplicationLifecycleService.php:124-179` | Status kanonis `LANJUT`/`MUNDUR`/`DITUTUP`; DITUTUP hanya Ganti Nama/Kembali ke NUP; bukan aktif/gugur. | Disetujui (D31). |
| C-11 | Tiga versi jam kerja hidup: Daily Plan 420/09.00, Receipt 480/08.00/07.30, onboarding Jumat 08.30. | `app/Models/SalesDailyPlan.php:19-21`; `app/Services/SalesAgendaReceiptService.php:66-89`; `app/Services/SalesPocketbookOnboardingService.php:19` | Terjawab: Daily Plan 420 tidak punya route aktif; alur Sales yang dipakai adalah Receipt/Agenda. WP-1.5 menyatukan resolver jam kerja di alur Receipt, mempertahankan data Daily Plan lama, dan memperbarui teks onboarding Jumat 08.30. | Disetujui (C-01). |

## Daftar aturan BR untuk tes

| Paket | Tes wajib minimal |
|---|---|
| WP-1.1 | `tests/Unit/AppSettingResolverTest.php`, `tests/Feature/AppSettingTest.php`: defaults/overrides, `BR-06`, audit, direct URL, Superadmin-only access, invalid values, and service boundary |
| WP-1.2 | `tests/Unit/FeatureFlagTest.php`: exact four-key set, default-off, explicit enabled override, shared resolver source; consumer transition paths and `BR-52` remain WP-1.4/consumer scope |
| WP-1.3 | `tests/Feature/PrdV3RoleAttributesTest.php`, `tests/Feature/PrdV3RolePermissionParityTest.php`, `tests/Feature/PrdV3RoleMigrationCommandTest.php`: role/attribute validation, legacy role mapping, exact snapshot parity, no permission escalation via additional role, dry-run no mutation, idempotent migration, and scope backfill |
| WP-1.4 | `BR-50`, `BR-51`, `BR-52`, `BR-53` boundary and direct URL |
| WP-1.5 | `BR-01`–`BR-09`, nilai default dan nilai Pengaturan yang diubah |
| WP-1.6 | `BR-10`–`BR-16` |
| WP-1.7 | `BR-20`–`BR-25`, `BR-30`–`BR-36` |
| WP-1.8/WP-1.9 | `BR-70`–`BR-73`, backup/operator checks |
| WP-1.10 | fase 1 verification and documentation checks |
| WP-2.1 | `BR-54`, `BR-55` |
| WP-2.2 | `BR-53` |
| WP-2.5 | `BR-42`–`BR-44` |
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
