# Laporan Penutupan Fase 1 PRD v3

Tanggal: 21 November 2026
Rujukan operator: `docs/product/PRD_V3_CUTOVER_RUNBOOK.md`

## Status per WP

| WP | Status | BR/D dan nama tes utama |
|---|---|---|
| WP-1.1 | Selesai | BR-06; `AppSettingResolverTest`, `AppSettingTest` |
| WP-1.2 | Selesai | BR-50–BR-53; `FeatureFlagTest` |
| WP-1.3 | Selesai | D6, D21, D25; `PrdV3RoleAttributesTest`, `PrdV3RolePermissionParityTest`, `PrdV3RoleMigrationCommandTest` |
| WP-1.4 | Selesai, cutover menunggu operator | BR-50–BR-53; organisasi/parity/scope authorization tests |
| WP-1.5 | Selesai | BR-01–BR-09; working-hours, holiday, receipt, reminder tests |
| WP-1.6 | Selesai, flag mati | BR-10–BR-16; `SalesLeadStageAuthorizationTest`, `SalesLeadStageV3CompatibilityTest` |
| WP-1.7 | Selesai, flag mati | BR-20–BR-36; `ConsumerV2ProcessTest`, transaction status/backfill tests |
| WP-1.8 | Selesai | §3/D15; glossary/navigation tests |
| WP-1.9 | Selesai, restore operator | BR-70–BR-73/BR-81; `AiChatTest`, `DatabaseBackupTest` |
| WP-1.10 | Selesai implementasi, merge main menunggu review | D32; `PrdV3D32ConsumerInputTest`, `ConsumerMigrationReconciliationTest`, `PrdV3RolePermissionParityTest` |

## D32 dan permission

Inventaris `consumer_progress.manage` dicatat di `PRD_V3_IMPLEMENTATION_LOG.md`. Rekonsiliasi Marison dipisah lebih dulu ke `consumer_migration.reconcile`, lalu `consumer_progress.manage` ditambahkan ke profil `sas`.

Tes D32 membuktikan:

- SAS dapat input Data Konsumen, NUP, dan form/event Proses Penjualan di branch/project sendiri.
- URL langsung dan JSON ke branch/project lain ditolak.
- SAS ditolak dari `admin.marison-migrations.*`.
- Delta snapshot SAS hanya `consumer_progress.manage`; profil Admin Cabang, SAM, dan Umum tidak berubah.
- Role yang sudah punya `consumer_progress.manage` menerima `consumer_migration.reconcile`, sehingga akses lama tidak hilang.

## Migrasi database

- `2026_11_21_230001_add_consumer_migration_reconcile_permission.php`
- `2026_11_21_230002_add_sas_consumer_input_permission.php`
- Migrasi changelog WP-1.9 dan migrasi fondasi role/feature flag/consumer tetap dijalankan oleh `php artisan migrate --force`.
- Kedua migrasi D32 idempoten dan menulis changelog kategori `changed`.

## Perintah artisan operator

```bash
php artisan oasis:database-backup
php artisan consumer:kavling-holder-audit
php artisan oasis:roles-v3-migrate --dry-run
php artisan sales-lead:stage-v3-backfill --dry-run
php artisan consumer:transaction-status-v3-backfill --dry-run
php artisan organization:backfill --dry-run
php artisan organization:parity
php artisan organization:cutover-health
php artisan migrate --force
php artisan optimize:clear
php artisan view:cache
```

Urutan, output, go/no-go, rollback, jeda observasi, dan kondisi restore ada di runbook.

## Feature flag dan default

| Flag | Default | Cutover |
|---|---:|---|
| `org_hierarchical_monitoring` | `false` | Setelah parity graf bersih dan observasi 7 hari |
| `lead_stages_v3` | `false` | Satu cabang uji |
| `transaction_events_v3` | `false` | Satu cabang uji |

## Konflik/keputusan menunggu

- Cutover role menunggu dry-run salinan DB produksi dan persetujuan operator.
- Mode graf kanonis menunggu parity bersih dan observasi 7 hari.
- Sinkronisasi Sheet dan opsi `Gugur` berubah bertahap sesuai runbook.
- Restore Hostinger belum dieksekusi agent; operator wajib melakukan uji restore ke lingkungan sementara.
- Data legacy tidak diberi riwayat palsu.

## Risiko

- Data produksi berbeda dari fixture snapshot; dry-run operator menjadi sumber keputusan.
- Aktivasi flag tanpa jeda dapat memperluas scope atau mengubah status transaksi.
- Backup manual yang tidak diunduh tidak cukup sebagai bukti pemulihan.
- Hostinger backup dan backup manual memiliki prosedur akses berbeda; checksum dan uji restore wajib dicatat.

## Bukti verifikasi

- D32 focused suite: `19 tests, 53 assertions` pass.
- Sebelum perubahan D32: `composer test` `2003 tests, 14163 assertions` pass.
- Pint pass.
- `git diff --check` pass pada perubahan teruji.
- Build dan graphify pass pada closeout sebelumnya; jalankan ulang sebelum merge.
