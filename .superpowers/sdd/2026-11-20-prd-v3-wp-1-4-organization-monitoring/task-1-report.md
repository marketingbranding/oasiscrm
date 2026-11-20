# Task 1 report — WP-1.4 monitoring decisions

## Status

Selesai. Dokumentasi WP-1.4 diperbarui tanpa perubahan pada application code, tests, migrations, atau routes.

## Perubahan

- Memperbarui baris WP-1.4 pada `docs/product/PRD_V3_IMPLEMENTATION_LOG.md` menjadi desain disetujui dan irisan 1.4a–1.4f sedang berjalan.
- Menambahkan subsection `Keputusan WP-1.4`.
- Mencatat batas 1.4e read-only yang hanya berlaku untuk data tim Sales: Lead, rencana harian/agenda, dan riwayat tim.
- Mencatat keberlanjutan `canEditHistoricalOwnedLead` untuk Branch Manager dan Admin Cabang sampai WP-2.1 Alih Lead, termasuk kewajiban review pada WP-2.1.
- Mencatat aturan `scope_level=pusat`: tidak menambah permission; hanya memperluas permission `*_branch` untuk `manager`, `ass_manager`, dan `supervisor`; permission `*_own` dan `*_assigned` tidak diperluas.
- Mencatat allowlist ringkasan turunan jauh untuk Lead, rencana harian/agenda, dan riwayat tim, termasuk larangan nilai sebelum/sesudah.
- Mencatat jarak berdasarkan edge efektif `REPORTS_TO`, lalu filter scope workspace, dengan pengecualian pusat sesuai BR-52.
- Mencatat kontrak 1.4a sebagai laporan parity/dry-run read-only machine-readable tanpa aktivasi source mode.
- Mencatat bahwa pemilik produk mengoperasikan `organization.graph_mode`; agent tidak melakukan cutover produksi atau backfill write.
- Mencatat D33 sebagai pending owner-reviewed production-copy dry-run dan melarang klaim cutover produksi.

## Verification

Command:

```text
git diff --check
```

Result: passed, no output.

No application test run. Task is documentation-only, and brief requires only `git diff --check`.

## Scope

Committed files:

- `docs/product/PRD_V3_IMPLEMENTATION_LOG.md`
- `.superpowers/sdd/2026-11-20-prd-v3-wp-1-4-organization-monitoring/task-1-report.md`

The approved design and implementation plan files were pre-existing untracked working-tree content. They were not modified or staged.

## Commit

Commit message: `docs: record WP-1.4 monitoring decisions`
