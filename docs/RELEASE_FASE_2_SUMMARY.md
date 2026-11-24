# Ringkasan Rilis Fase 2 OASIS CRM

## Status keputusan

Fase 2 sudah terintegrasi ke branch `prd-v3/fase-2-pemantauan-data` melalui merge `9c08e41` (`--no-ff`).

`main` belum disentuh. Merge Fase 2 ke `main` menunggu keputusan pemilik produk.

Verifikasi setelah merge:

- `composer test`: 2287 tes, 2286 lulus, 1 skip, 15371 assertions.
- `vendor/bin/pint --dirty --test`: lulus.
- `git diff --check`: lulus.
- `npm run build`: lulus.
- Push: `origin/prd-v3/fase-2-pemantauan-data` sudah memuat `9c08e41`.

## Daftar paket kerja Fase 2

| WP | Cakupan | Integrasi / commit utama |
|---|---|---|
| WP-2.1 | Alih Lead dan antrean tanpa pemilik | Merge `830e8c8` |
| WP-2.2 | Izin Akses Khusus | Merge `ee8207c` |
| WP-2.3 | Penanda perhatian | Merge `80807b3` |
| WP-2.4 | Status migrasi cabang dan laporan paritas | Merge `6479a08` |
| WP-2.5 | Dana Talangan di OASIS dan cicilan | Merge `edf32d6` |
| WP-2.6 | Kembali ke NUP di Workspace Konsumen | Merge `657f0ac` |
| WP-2.7 | Penutup Fase 2, UAT, verifikasi penuh | Merge `9c08e41`; sumber terakhir `7559a13`, perbaikan `125f99e` |
| WP-2.8 | Riwayat Tim | Merge `10f9731` |
| WP-2.9 | Penautan Lead ke Konsumen | Merge `dea710e` |
| WP-2.9b | Penautan Lead untuk Sheet dan NUP | Merge `15bdbde` |

Commit merge di atas tercatat pada first-parent branch Fase 2. Detail perilaku dan tes ada di `docs/product/PRD_V3_IMPLEMENTATION_LOG.md`.

## Migrasi baru Fase 2

| File | Perubahan | Reversibel |
|---|---|---|
| `2026_07_03_000000_create_konsumen_progress_cache_tables.php` | Cache baris Sheet Konsumen Progress | Ya, selama belum ada data produksi yang bergantung pada tabel |
| `2026_07_03_010000_create_database_sheet_cache_tables.php` | Cache sinkronisasi Database Sheet | Ya, dengan tinjauan data |
| `2026_08_28_000003_create_dana_talangan_bridge.php` | Tabel bridge, rekonsiliasi, dan status sinkronisasi Dana Talangan | Tidak aman rollback setelah data produksi masuk |
| `2026_10_03_121440_create_organization_assignments_table.php` | Graf penugasan organisasi | Tidak aman rollback setelah data organisasi masuk |
| `2026_11_24_100003_add_wp29_review_fixes_changelog.php` | Changelog review WP-2.9 | Ya, hanya entri changelog |
| `2026_11_24_100004_expand_wp29b_decision_subjects.php` | Kolom dan index keputusan untuk Sheet/NUP, termasuk unique active subject/lead | Tidak aman rollback setelah keputusan Sheet/NUP masuk |
| `2026_11_24_100005_create_consumer_lead_link_conflicts_table.php` | Tabel konflik carry-over penautan Lead | Tidak aman rollback setelah konflik produksi masuk |
| `2026_11_24_100006_allow_non_application_link_subjects.php` | `consumer_application_id` menjadi nullable untuk subjek Sheet/NUP | Tidak aman rollback; `down()` sengaja tidak mengembalikan NOT NULL |
| `2026_11_24_170532_add_consumer_link_carry_over_changelog.php` | Changelog carry-over penautan | Ya, hanya entri changelog |
| `2026_11_24_202006_add_nup_transaction_link_fallback_changelog.php` | Changelog fallback link NUP | Ya, hanya entri changelog |
| `2026_11_24_210434_add_uat_label_format_changelog.php` | Changelog label dan format laporan | Ya, hanya entri changelog |
| `2026_11_24_220300_fix_bulk_legacy_decision_race_changelog.php` | Changelog konfirmasi Data Lama | Ya, hanya entri changelog |
| `2026_11_24_220721_add_manual_subject_lead_search_changelog.php` | Changelog pencarian Lead manual dan label panel | Ya, hanya entri changelog |
| `2026_11_24_222905_close_wp27_subject_panel_changelog.php` | Changelog perbaikan panel Sheet/NUP | Ya, hanya entri changelog |

Migrasi Fase 2 dengan `down()` yang tidak aman tidak boleh di-rollback setelah data produksi masuk. Ikuti aturan rollback pada runbook.

## Flag dan environment pasca-rilis

Nilai default tetap mati. Operator tidak mengaktifkan tanpa dry-run dan persetujuan pemilik:

- `OASIS_NEW_WORKSPACE_ENABLED=true` — prerequisite sebelum cabang pertama masuk status `paralel`.
- `org_hierarchical_monitoring=false`
- `lead_stages_v3=false`
- `transaction_events_v3=false`
- `dana_talangan_primary_source=false`

Jalankan `php artisan optimize:clear` setelah perubahan environment. Jangan mematikan sinkronisasi Sheet atau mengubah sumber utama Dana Talangan tanpa keputusan cutover per cabang/global sesuai runbook.

## Perintah pasca-rilis mode pratinjau

Jalankan pada salinan DB produksi, bukan produksi, dan tinjau output sebelum cutover:

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

Perintah deploy produksi memerlukan keputusan pemilik dan tidak dijalankan di change set ini. Setelah keputusan rilis, operator mengikuti runbook, termasuk `php artisan migrate --force`, `php artisan optimize:clear`, cache sesuai hosting, restart worker, dan pemeriksaan scheduler.

## Risiko dan sisa

- Sisa uji lebar HP oleh pemilik produk.
- Penghitung panel penautan diperbarui setelah reload; belum menjadi pembaruan tanpa reload.
- Flag v3 tetap mati sampai smoke test, dry-run, dan approval pemilik.
- Migrasi keputusan Sheet/NUP dan konflik tidak aman di-rollback setelah data produksi masuk.
- Cutover organisasi, sumber utama Dana Talangan, dan pemadaman sinkronisasi Sheet tidak dijalankan oleh agent.
- Data UAT lokal tidak dibawa ke produksi; seed terakhir hanya untuk verifikasi lokal.

## Bukti UAT

Tidak menulis klaim UAT pemilik sebelum pengujian pemilik/asisten. Setelah pemilik melaporkan pengujian cabang ID 18, hasil yang dilaporkan: pencarian Lead manual HTTP 201 dengan toast dan riwayat; penolakan Lead tertaut tampil; perubahan Lead ke Tanpa Lead terkirim tanpa `sales_lead_id` HTTP 201. Catatan ini berasal dari laporan pemilik dan menjadi dasar penerimaan WP-2.7.
