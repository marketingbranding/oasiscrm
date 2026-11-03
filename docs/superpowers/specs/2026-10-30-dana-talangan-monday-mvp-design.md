# Dana Talangan Monday MVP Design

## Intent

Make the existing Dana Talangan module operationally usable for Monday operations using the supplied workbook contract, without creating a second implementation or redesigning the finance domain.

## Goals

- Keep the existing CRUD, project/branch scope, decimal money cast, optimistic locking, soft-delete, and Google bridge behavior.
- Make `Nominal` visible in the operational list, create/edit forms, summary, XLSX export, and template.
- Add the minimum useful Marketing filter and preserve all active filters in export.
- Add a simple filtered summary for record count, nominal total, status counts, and safely derivable upcoming/overdue commitments.
- Normalize new/imported values for money, age, dates, booleans, and workbook status values.
- Represent blank historical `Status Cicilan` as canonical `belum_ditentukan`; preserve `sanggup`, `tidak_sanggup`, and `lunas`.
- Preserve local OASIS data when bridge operations fail and expose the existing sync status/error.
- Add focused regression tests and verify desktop/mobile workflow behavior.

## Non-goals

- No accounting ledger, installment schedule, approval chain, collection scoring, WhatsApp automation, or finance dashboard.
- No organization graph, RBAC redesign, Buku Saku Sales, Pengeluaran, or unrelated KPR changes.
- No mass import of the supplied workbook and no global historical cleanup.
- No new synchronization architecture or replacement of existing project/branch relationships.

## Constraints

- The canonical bridge remains the existing `2026` Google Sheet contract with business columns and hidden OASIS metadata columns.
- Metadata fields remain system-managed and are never normal form inputs.
- New records use real dates, integer age, boolean operational flags, decimal money, and canonical status values.
- Existing records without `project_id` remain readable; new and edited records continue to resolve an active project within branch and user scope.
- All list, summary, and export queries use the same authorization scope.

## Approaches considered

1. **Targeted Monday slice (selected):** extend the existing list/form/export/import paths and add focused tests. Lowest risk and directly covers the operational gaps.
2. **Bridge-first hardening:** improve only workbook parsing and synchronization. Safer in isolation but leaves Monday users without the required amount/reporting visibility.
3. **Broad module migration:** introduce a new shared mapping/status layer and refactor all screens. Not justified for Monday and increases regression risk.

## Design decisions

- Use `belum_ditentukan` as the normalized application representation for blank Status Cicilan. It is selectable for new records and accepts blank historical workbook cells.
- Add `Nominal` as a numeric input and display it as localized currency only at presentation/export boundaries; do not store currency symbols or floats.
- Filter commitments by `Tanggal` for the primary period filter; count overdue commitments only when `tgl_komitmen` is non-null and earlier than today, and upcoming commitments only when it is today or later.
- Keep Marketing as a text snapshot filter because existing Dana Talangan records use `nama_marketing`; do not introduce a user relationship migration.
- Keep the existing bridge’s owned/shared field model, UUID identity, tombstone behavior, and conflict handling unchanged except for compatible status/money normalization.

## Verification target

Focused feature tests will cover create/edit persistence, money precision, blank and non-blank commitment dates, all four status states, Marketing/period/project/branch filters, summary and export fields, import normalization, scope denial, and bridge compatibility. Then run the required Laravel cache/test/format/build checks and desktop/mobile browser verification.
