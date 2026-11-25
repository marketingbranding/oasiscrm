# Ringkasan Rilis Fase 1 + Fase 2 OASIS CRM

## Status keputusan

Fase 2 sudah terintegrasi ke branch `prd-v3/fase-2-pemantauan-data` melalui merge `9c08e41` (`--no-ff`). Ringkasan ini mencakup seluruh 71 migrasi yang akan ikut bila branch Fase 2 digabung ke `origin/main`.

Sumber daftar migrasi:

```powershell
git diff --name-only --diff-filter=A origin/main origin/prd-v3/fase-2-pemantauan-data -- database/migrations
```

`main` belum disentuh. Keputusan merge Fase 2 ke `main` menunggu pemilik produk.

Verifikasi setelah merge WP-2.7:

- `composer test`: 2287 tes, 2286 lulus, 1 skip, 15371 assertions.
- `vendor/bin/pint --dirty --test`: lulus.
- `git diff --check`: lulus.
- `npm run build`: lulus.
- Merge WP-2.7: `9c08e41`.
- Ringkasan ini: `6b4b01c`.

## Daftar paket kerja

| Fase/WP | Cakupan | Integrasi / commit utama |
|---|---|---|
| Fase 1 / WP-1.1–1.3 | Pengaturan, atribut role, Staff Admin | Riwayat merge Fase 1 |
| Fase 1 / WP-1.5–1.9 | Kalender agenda, tahap Lead, status transaksi, glossary, AI backup | Riwayat merge Fase 1 |
| Fase 2 / WP-2.1 | Alih Lead dan antrean tanpa pemilik | Merge `830e8c8` |
| Fase 2 / WP-2.2 | Izin Akses Khusus | Merge `ee8207c` |
| Fase 2 / WP-2.3 | Penanda perhatian | Merge `80807b3` |
| Fase 2 / WP-2.4 | Status migrasi cabang dan paritas | Merge `6479a08` |
| Fase 2 / WP-2.5 | Dana Talangan dan cicilan | Merge `edf32d6` |
| Fase 2 / WP-2.6 | Kembali ke NUP | Merge `657f0ac` |
| Fase 2 / WP-2.7 | Penutup, UAT, verifikasi penuh | Merge `9c08e41` |
| Fase 2 / WP-2.8 | Riwayat Tim | Merge `10f9731` |
| Fase 2 / WP-2.9 | Penautan Lead ke Konsumen | Merge `dea710e` |
| Fase 2 / WP-2.9b | Penautan Lead untuk Sheet dan NUP | Merge `15bdbde` |

## Migrasi baru yang ikut ke `main`

Kolom **Mengubah data** berarti `up()` menulis atau mengisi data operasional, permission, role, setting, atau changelog. Kolom **Bisa gagal** menyebut syarat yang dapat menghentikan migrasi atau menyebabkan perhatian operator.

### Fase 1 — fondasi PRD v3

| File | Perubahan | Mengubah data? | Bisa gagal di produksi? | Reversibel |
|---|---|---:|---|---|
| `2026_11_20_000001_create_app_settings_table.php` | Membuat tabel pengaturan aplikasi | Tidak | Tidak, kecuali tabel sudah ada | Ya |
| `2026_11_20_000002_add_settings_permission_and_changelog.php` | Menambah permission pengaturan dan pemetaan superadmin; changelog | Ya | Ya, bila role/permission dasar hilang | Ya, data permission/changelog perlu tinjauan |
| `2026_11_20_191749_add_settings_form_and_resolver_hardening_changelog.php` | Changelog hardening form/resolver | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_20_200027_add_prd_v3_user_attributes_and_ass_manager.php` | Kolom atribut user dan role `ass_manager`; backfill role/user | Ya | Ya, bila role/data user tidak konsisten | Tidak aman setelah data produksi diisi |
| `2026_11_20_201500_fix_app_setting_cache_reads_changelog.php` | Changelog perbaikan cache pengaturan | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_20_210001_harden_prd_v3_staff_role_profiles.php` | Menyesuaikan profil role lama dan Staff Admin | Ya | Ya, bila role/permission target tidak ada | Tidak aman tanpa snapshot permission |
| `2026_11_20_210002_correct_staff_scope_level.php` | Memperbaiki `scope_level` user Staff | Ya | Ya, bila role Staff tidak ada | Tidak aman setelah akses dipakai |
| `2026_11_20_210003_complete_prd_v3_legacy_role_profiles.php` | Melengkapi profil role lama dan mapping permission | Ya | Ya, bila permission/role dasar hilang | Tidak aman tanpa snapshot permission |
| `2026_11_21_000001_add_hierarchical_organization_scope_changelog.php` | Changelog scope organisasi berjenjang | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_21_000002_add_sales_monitoring_projection_changelog.php` | Changelog projection pemantauan Sales | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_21_000003_add_sales_monitoring_read_only_changelog.php` | Changelog alat baca pemantauan | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_21_000004_add_sales_monitoring_coordinator_isolation_changelog.php` | Changelog isolasi Koordinator | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_21_085905_create_holidays_table.php` | Membuat tabel hari libur | Tidak | Tidak, kecuali tabel sudah ada | Ya sebelum data produksi |
| `2026_11_21_090000_add_free_day_marker_to_sales_agenda_day_submissions.php` | Menambah penanda hari bebas agenda | Tidak | Ya, bila schema/data lama tidak kompatibel | Ya sebelum data bergantung |
| `2026_11_21_090401_add_sales_agenda_calendar_changelog.php` | Changelog kalender agenda | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_21_102004_add_wp15_review_fixes_changelog.php` | Changelog perbaikan review WP-1.5 | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_21_114841_add_lead_follow_up_list_changelog.php` | Changelog daftar follow-up Lead | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_21_120000_add_sales_lead_stage_v3_fields.php` | Kolom tahap Lead v3 dan riwayat tahap | Tidak | Ya, bila perubahan schema gagal | Ya sebelum data riwayat dipakai |
| `2026_11_21_120000_backfill_sales_lead_stage_v3.php` | Mengisi `lead_stage` NULL untuk semua `sales_leads` | Ya | Ya, bila data Lead rusak atau DB berhenti | Tidak; `down()` sengaja tidak membalik backfill |
| `2026_11_21_130000_add_lead_stages_v3_changelog.php` | Changelog tahap Lead v3 | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_21_135443_add_sheet_stage_pull_fixes_changelog.php` | Changelog perbaikan pull tahap Sheet | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_21_140000_add_canonical_transaction_status_fields.php` | Kolom status transaksi kanonis | Ya | Ya, bila schema/data lama tidak kompatibel | Tidak aman setelah status ditulis |
| `2026_11_21_150000_add_transaction_event_types.php` | Tipe event transaksi | Tidak | Ya, bila tabel event tidak kompatibel | Ya sebelum event baru dipakai |
| `2026_11_21_160000_add_nup_provenance_fields.php` | Provenance NUP | Tidak | Ya, bila schema lama tidak kompatibel | Ya sebelum data baru dipakai |
| `2026_11_21_164320_enforce_active_and_sold_kavling_assignment_uniqueness.php` | Unique constraint kavling aktif/terjual | Tidak sebelum constraint | **Ya — migrate berhenti bila ada kavling dengan >1 assignment aktif/terjual yang belum dilepas. Syarat: duplikat harus 0 di salinan DB produksi.** | Tidak aman setelah constraint/data produksi dipakai |
| `2026_11_21_173947_add_wp17_transaction_status_safety_changelog.php` | Changelog keamanan status transaksi | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_21_180528_add_wp17_completion_reporting_safety_changelog.php` | Changelog keamanan laporan penyelesaian | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_21_181500_add_wp18_glossary_labels_changelog.php` | Changelog glossary label | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_21_181501_align_wp18_glossary_permission_labels.php` | Mengubah label permission glossary | Ya | Ya, bila permission target hilang | Ya dengan snapshot label |
| `2026_11_21_220000_add_wp19_ai_backup_changelog.php` | Changelog backup AI | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_21_230001_add_consumer_migration_reconcile_permission.php` | Permission rekonsiliasi migrasi konsumen dan mapping role | Ya | Ya, bila permission/role dasar hilang | Tidak aman tanpa snapshot permission |
| `2026_11_21_230002_add_sas_consumer_input_permission.php` | Mapping permission input konsumen ke SAS | Ya | Ya, bila role/permission SAS hilang | Tidak aman tanpa snapshot permission |

**Catatan khusus backfill Lead:** `2026_11_21_120000_backfill_sales_lead_stage_v3.php` mengisi semua `sales_leads.lead_stage` yang NULL saat migrasi. Ini tetap terjadi meski flag `lead_stages_v3` mati. Pada gladi, catat jumlah baris yang diubah dan durasi migrasi.

### Fase 2 — pemantauan dan migrasi data

| File | Perubahan | Mengubah data? | Bisa gagal di produksi? | Reversibel |
|---|---|---:|---|---|
| `2026_11_22_062618_create_sales_lead_transfers_table.php` | Tabel alih Lead | Tidak | Tidak, kecuali tabel sudah ada | Ya sebelum data produksi |
| `2026_11_22_062619_create_sales_lead_unowned_queues_table.php` | Tabel antrean Lead tanpa pemilik | Tidak | Tidak, kecuali tabel sudah ada | Ya sebelum data produksi |
| `2026_11_22_062621_add_wp21_lead_transfer_changelog.php` | Changelog alih Lead | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_22_062622_add_closed_fields_to_sales_lead_unowned_queues.php` | Kolom penutupan antrean | Tidak | Ya, bila schema lama tidak kompatibel | Ya sebelum data baru dipakai |
| `2026_11_22_062624_add_wp21_review_fixes_changelog.php` | Changelog review WP-2.1 | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_22_171824_add_lead_queue_page_changelog.php` | Changelog halaman antrean Lead | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_22_180707_create_access_grants_table.php` | Tabel izin akses khusus dan changelog | Ya, changelog | Ya, bila tabel/permission dasar bermasalah | Ya sebelum grant produksi |
| `2026_11_22_215130_add_br53_access_grants_changelog.php` | Changelog izin akses | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_22_224300_add_wp23_lead_attention_activity_indexes.php` | Index aktivitas Lead/notifikasi | Tidak | Ya, bila index gagal karena data/schema | Ya |
| `2026_11_22_231500_add_wp23_attention_changelog.php` | Changelog penanda perhatian | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_22_234524_add_source_to_comments_table.php` | Kolom `comments.source`; klasifikasi komentar lama human/system | Ya | Ya, bila tabel komentar/data lama bermasalah | Kolom reversibel; klasifikasi lama tidak aman dibalik tanpa audit |
| `2026_11_23_000000_create_branch_migration_states_table.php` | Tabel status migrasi cabang dan seed status awal | Ya | Ya, bila data cabang/schema tidak konsisten | Tidak aman setelah status produksi diubah |
| `2026_11_23_000001_add_backfill_relations_to_dana_talangans.php` | Relasi Dana Talangan dan backfill hubungan | Ya, termasuk changelog | Ya, bila relasi lama ambigu | Tidak aman setelah data relasi dipakai |
| `2026_11_23_000001_add_branch_migration_permission.php` | Permission status migrasi dan mapping role | Ya | Ya, bila role/permission dasar hilang | Tidak aman tanpa snapshot permission |
| `2026_11_23_000002_add_wp24_input_matrix_changelog.php` | Changelog input matrix WP-2.4 | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_23_000002_add_wp25_backfill_review_changelog.php` | Changelog review backfill Dana Talangan | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_23_000003_add_wp24_consumer_write_guard_changelog.php` | Changelog guard tulis konsumen | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_23_000003_create_dana_talangan_payments.php` | Tabel pembayaran Dana Talangan dan changelog | Ya, changelog | Ya, bila tabel sudah ada/schema gagal | Tidak aman setelah pembayaran produksi masuk |
| `2026_11_23_000004_add_auto_lunas_tracking_to_dana_talangans.php` | Kolom pelacakan lunas otomatis | Ya, changelog | Ya, bila data/schema lama tidak kompatibel | Tidak aman setelah tracking dipakai |
| `2026_11_23_000005_add_dana_talangan_report_source_changelog.php` | Changelog sumber laporan Dana Talangan | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_23_000006_update_dana_talangan_report_changelog.php` | Changelog perbaikan periode/keamanan laporan | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_23_112625_create_consumer_parity_snapshots_table.php` | Tabel snapshot paritas konsumen | Tidak | Tidak, kecuali tabel sudah ada | Ya sebelum snapshot produksi |
| `2026_11_23_112626_add_wp24_b_parity_changelog.php` | Changelog paritas | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_23_120000_add_wp24_c_cutover_changelog.php` | Changelog cutover cabang | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_23_121000_add_wp24_c_parallel_gate_changelog.php` | Changelog gerbang paralel | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_24_080047_add_team_history_changelog.php` | Changelog Riwayat Tim | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_24_090000_refine_team_history_scope_changelog.php` | Changelog scope Riwayat Tim | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_24_100000_create_consumer_lead_link_decisions_table.php` | Tabel keputusan penautan Lead | Ya, struktur/index | Ya, bila index unique menemukan duplikat | Tidak aman setelah keputusan produksi masuk |
| `2026_11_24_100001_add_wp29_consumer_lead_link_changelog.php` | Changelog penautan Lead konsumen | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_24_100003_add_wp29_review_fixes_changelog.php` | Changelog review WP-2.9 | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_24_100004_expand_wp29b_decision_subjects.php` | Kolom/index keputusan Sheet dan NUP; perluasan subjek | Ya, backfill keputusan | Ya, bila FK/index atau data keputusan konflik | Tidak aman setelah keputusan produksi masuk |
| `2026_11_24_100005_create_consumer_lead_link_conflicts_table.php` | Tabel konflik carry-over penautan Lead | Tidak | Tidak, kecuali tabel sudah ada | Ya sebelum konflik produksi |
| `2026_11_24_100006_allow_non_application_link_subjects.php` | Membuat `consumer_application_id` nullable untuk Sheet/NUP | Tidak | Ya, bila perubahan FK/schema gagal | Tidak; `down()` sengaja tidak mengembalikan NOT NULL |
| `2026_11_24_170532_add_consumer_link_carry_over_changelog.php` | Changelog carry-over penautan | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_24_202006_add_nup_transaction_link_fallback_changelog.php` | Changelog fallback link NUP | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_24_210434_add_uat_label_format_changelog.php` | Changelog label UAT dan format laporan | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_24_220300_fix_bulk_legacy_decision_race_changelog.php` | Changelog konfirmasi Data Lama | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_24_220721_add_manual_subject_lead_search_changelog.php` | Changelog pencarian Lead manual | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |
| `2026_11_24_222905_close_wp27_subject_panel_changelog.php` | Changelog perbaikan panel Sheet/NUP | Ya | Tidak, kecuali tabel changelog bermasalah | Ya |

## Flag dan environment pasca-rilis

`OASIS_NEW_WORKSPACE_ENABLED` adalah **environment variable**, bukan flag DB. Nilai default `false`; env ini harus `true` sebelum cabang pertama masuk status `paralel`. Setelah env berubah, jalankan `php artisan optimize:clear`.

Flag DB disimpan di tabel `app_settings`, dibaca oleh `AppSettingResolver` dan `FeatureFlag`, lalu diubah melalui halaman admin `/admin/pengaturan-aturan` oleh user dengan permission `system.settings_manage` dan middleware `system.admin:settings`. Default tetap mati:

- `org_hierarchical_monitoring=false` — flag global DB.
- `lead_stages_v3=false` — flag DB berscope cabang.
- `transaction_events_v3=false` — flag DB berscope cabang.
- `dana_talangan_primary_source=false` — flag DB berscope cabang.

Operator tidak mengaktifkan flag tanpa dry-run dan persetujuan pemilik. Jangan mematikan sinkronisasi Sheet atau mengubah sumber utama Dana Talangan tanpa keputusan cutover sesuai runbook.

## Perintah pasca-rilis mode pratinjau

Jalankan di salinan DB produksi, bukan produksi. Tinjau output sebelum cutover:

```powershell
php artisan organization:backfill --dry-run
php artisan sales-lead:stage-v3-backfill --dry-run
php artisan consumer:transaction-status-v3-backfill --dry-run
php artisan consumer-kavling:backfill preview --branch={cabang}
php artisan consumer:kavling-holder-audit
php artisan dana-talangan:backfill-relations
php artisan consumer-lead-link:carry-over --dry-run
php artisan consumer:parity-report {cabang} --xlsx
```

Jangan menambahkan `--apply`, `--execute`, `--confirm`, atau opsi tulis pada gladi. `consumer:transaction-status-v3-backfill` wajib memakai `--dry-run`.

## Risiko dan sisa

- Sebelum migrate, duplikat assignment kavling aktif/terjual harus 0.
- Backfill `lead_stage` menulis data walau flag tahap Lead mati; catat jumlah baris dan waktu.
- Migrasi permission, role profile, label glossary, seed status cabang, klasifikasi komentar, dan changelog menulis data.
- Migrasi keputusan Sheet/NUP dan konflik tidak aman di-rollback setelah data produksi masuk.
- Sisa UAT: uji lebar HP oleh pemilik produk.
- Penghitung panel penautan diperbarui setelah reload; belum menjadi pembaruan tanpa reload.
- Cutover organisasi, sumber utama Dana Talangan, dan pemadaman sinkronisasi Sheet tidak dijalankan agent.

## Bukti UAT

Pemilik melaporkan pengujian cabang ID 18: pencarian Lead manual HTTP 201 dengan toast dan riwayat; penolakan Lead yang sudah tertaut tampil di panel; perubahan Lead ke Tanpa Lead terkirim tanpa `sales_lead_id` HTTP 201. Catatan ini berasal dari laporan pemilik setelah pengujian, bukan klaim sebelum pengujian.
