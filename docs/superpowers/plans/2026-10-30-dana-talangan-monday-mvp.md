# Dana Talangan Monday MVP Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the existing Dana Talangan module operationally usable for Monday by closing the workbook, reporting, input-normalization, and UI gaps without changing its authorization or synchronization architecture.

**Architecture:** Extend the current Dana Talangan controller, requests, Blade views, importer, exporter, and bridge normalizers in place. Keep `DanaTalangan` as the single local dataset, retain `project_id`/`branch_id` scope enforcement, and use the existing bridge UUID/tombstone/conflict workflow. Add no new finance subsystem or dependency.

**Tech Stack:** Laravel 13, PHP 8.3, PHPUnit, Blade, Alpine.js, PhpSpreadsheet, existing Google Sheets bridge services, SQLite test database.

**Spec:** `docs/superpowers/specs/2026-10-30-dana-talangan-monday-mvp-design.md`

## Global Constraints

- Preserve existing authorization, project/branch scope, decimal money storage, optimistic locking, soft-delete, and Google bridge behavior.
- Keep OASIS metadata fields system-managed; never expose `oasis_sync_id`, `oasis_deleted_at`, or `oasis_deleted_by` as normal form fields.
- Normalize new values to real dates, integer age, booleans, decimal money, and canonical status values.
- Represent blank historical `Status Cicilan` as `belum_ditentukan`; preserve `sanggup`, `tidak_sanggup`, and `lunas`.
- Do not mass-import the supplied workbook or modify organization graph, global RBAC, Buku Saku Sales, or Pengeluaran.
- Do not add a dependency or a second synchronization architecture.
- All list, summary, export, import, and detail paths must apply the same authorization scope.
- Any behavior change requires one idempotent Indonesian Oasis Changelog migration entry.

## Review Focus

- Workbook money strings such as `1,100,000`, `Rp1,072,173`, and `9876543210.99` must not become malformed or lose decimal precision. **Test:** importer/bridge normalization and store-to-export assertions in Tasks 1 and 2.
- Workbook age strings such as `25 th` must become integer `25`, while invalid decimal/scientific ages remain rejected. **Test:** import normalization/rejection in Task 1.
- Blank Status Cicilan must be visible as “Belum Ditentukan”, not silently treated as Lunas or rejected. **Test:** import, create/update validation, filter, summary, and export in Tasks 1–3.
- Filters and summaries must match the same scoped records as the table, including exact end dates and Marketing. **Test:** scoped filtered index/export and summary assertions in Task 3.
- A failed bridge write must leave the local record intact with a useful failure state. **Test:** existing failure path plus a Monday regression assertion in Task 4.

---

### Task 1: Normalize workbook input and canonical statuses

**Files:**
- Modify: `app/Http/Requests/Crm/StoreDanaTalanganRequest.php`
- Modify: `app/Http/Requests/Crm/UpdateDanaTalanganRequest.php`
- Modify: `app/Imports/DanaTalanganImport.php`
- Modify: `app/Services/DanaTalanganSpreadsheetContract.php`
- Modify: `app/Services/DanaTalanganBridgeService.php`
- Modify: `app/Services/DanaTalanganGoogleService.php`
- Modify: `app/Http/Controllers/Crm/DanaTalanganController.php`
- Test: `tests/Feature/DanaTalanganInteractionTest.php`
- Test: `tests/Feature/DanaTalanganBridgeTest.php`

**Interfaces:**
- Produces canonical status values `belum_ditentukan`, `sanggup`, `tidak_sanggup`, and `lunas` for all new/imported/synchronized records.
- Produces decimal strings with two fractional digits or `null` for empty nominal values.
- Keeps legacy 14-column imports compatible while reading the current header-driven workbook layout with `Nominal`.

- [ ] **Step 1: Write failing tests** for a current workbook row containing `Nominal = "Rp1,072,173"`, `Umur = "25 th"`, blank `Status Cicilan`, and optional blank `TGL Komitmen`; assert import succeeds with nominal `1072173.00`, age `25`, nullable commitment, and status `belum_ditentukan`. Add tests for `1100000`, `1,100,000`, and `9876543210.99` normalization, and retain rejection tests for invalid ages/statuses.
- [ ] **Step 2: Run the focused tests** with `php artisan test --compact tests/Feature/DanaTalanganInteractionTest.php tests/Feature/DanaTalanganBridgeTest.php`; confirm the new cases fail because the current positional importer rejects `25 th`, has no nominal mapping, and maps blank status incorrectly.
- [ ] **Step 3: Implement the minimum normalization changes:** read import columns by normalized header names with a legacy positional fallback; parse `Umur` by accepting an integer followed by optional `th` text; add string-safe money normalization for Indonesian and English thousands/decimal separators; map blank/“belum ditentukan” status to `belum_ditentukan`; allow that value in requests, controller bulk options, spreadsheet contract, active bridge, and legacy Google service.
- [ ] **Step 4: Run the focused tests** again and confirm all import and bridge normalization cases pass without changing scope or metadata behavior.
- [ ] **Step 5: Commit** with `git add` limited to Task 1 files and `git commit -m "fix: normalize dana talangan workbook values"`.

### Task 2: Make Nominal operational in forms, list, export, and template

**Files:**
- Modify: `app/Models/DanaTalangan.php`
- Modify: `resources/views/crm/dana-talangan/index.blade.php`
- Modify: `resources/views/crm/dana-talangan/create.blade.php`
- Modify: `resources/views/crm/dana-talangan/edit.blade.php`
- Modify: `app/Exports/DanaTalanganExport.php`
- Test: `tests/Feature/DanaTalanganInteractionTest.php`
- Test: `tests/Feature/DanaTalanganGoogleSyncTest.php`

**Interfaces:**
- `DanaTalangan::formattedNominal(): string` returns a precision-safe Indonesian display such as `Rp9.876.543.210,99` and `—` for null.
- Export and template column order follows the current operational contract: `No, Tanggal, Nama Konsumen, Kav, Proyek, Pinjam Nama, Pekerjaan, Status Kawin, Umur, Marketing, TGL Komitmen, Nominal, Penyelesaian, Konfirmasi, Status Cicilan` (plus Cabang only in the import template).

- [ ] **Step 1: Write failing tests** asserting the create/edit forms contain a labeled decimal Nominal field, the index renders nominal, the detail payload includes nominal, the export contains a `Nominal` header/value, and the template contains the current nominal column and shifted validations. Add a precision assertion for `9876543210.99` through persistence and export.
- [ ] **Step 2: Run the focused UI/export tests** and confirm they fail because the current forms/list/export/template omit Nominal.
- [ ] **Step 3: Implement the minimum changes:** add a precision-safe model formatter; add required optional decimal inputs to all three form variants; include nominal in the list and detail fields; add sortable nominal support; add nominal to export/template with a numeric cell and two-decimal number format; keep metadata out of all normal form fields.
- [ ] **Step 4: Run the focused tests** and inspect generated XLSX headers/cells with PhpSpreadsheet to verify nominal is numeric and remains exact to two decimals.
- [ ] **Step 5: Commit** with `git add` limited to Task 2 files and `git commit -m "feat: expose dana talangan nominal"`.

### Task 3: Add Marketing filtering and filtered operational summary

**Files:**
- Modify: `app/Http/Controllers/Crm/DanaTalanganController.php`
- Modify: `resources/views/crm/dana-talangan/index.blade.php`
- Modify: `app/Exports/DanaTalanganExport.php` only if filter-related export metadata requires it
- Test: `tests/Feature/DanaTalanganInteractionTest.php`
- Test: `tests/Feature/DanaTalanganGoogleSyncTest.php`

**Interfaces:**
- Index and export accept `marketing`, alongside existing branch/project/status/search/date/month parameters.
- View data includes `selectedMarketing`, `marketingOptions`, and summary keys `total`, `nominal`, `sanggup`, `tidak_sanggup`, `lunas`, `belum_ditentukan`, `upcoming_commitments`, and `overdue_commitments`.
- Summary and table use identical authorized filters; overdue/upcoming counts use non-null `tgl_komitmen` against today.

- [ ] **Step 1: Write failing tests** for Marketing filtering, exact date end inclusion, project/branch/status combinations, summary nominal/status/commitment counts, active-filter chips, and export preserving Marketing plus all date/project/status filters. Assert a branch-scoped user cannot use filters/export to see another branch/project.
- [ ] **Step 2: Run the focused tests** and confirm the new filter/summary cases fail because Marketing is not accepted and summary has no nominal/commitment data.
- [ ] **Step 3: Implement the minimum controller/query changes:** derive authorized Marketing options from visible records, apply Marketing to list/summary/export, normalize blank status filtering to include null/empty legacy records, sum decimal nominal without float conversion, and calculate commitment counts with Carbon date comparisons. Add the filter control, active chip, summary cards, and preserved export parameters in the existing toolbar/modal.
- [ ] **Step 4: Run focused tests** and verify the returned collection, summary, and XLSX rows are the same filtered scope.
- [ ] **Step 5: Commit** with `git add` limited to Task 3 files and `git commit -m "feat: add dana talangan operational filters"`.

### Task 4: Preserve bridge compatibility and add the required changelog

**Files:**
- Modify: `app/Services/DanaTalanganBridgeService.php` if Task 1 leaves bridge-specific compatibility gaps
- Modify: `app/Services/DanaTalanganSpreadsheetContract.php` if Task 1 leaves contract-specific compatibility gaps
- Create: `database/migrations/2026_10_30_180000_add_dana_talangan_monday_mvp_changelog.php`
- Test: `tests/Feature/DanaTalanganBridgeTest.php`
- Test: `tests/Feature/DanaTalanganBridgeCommandTest.php`

**Interfaces:**
- Existing push/pull direction, UUID identity, hidden metadata columns, tombstones, three-way conflicts, and dry-run semantics remain unchanged.
- Local create/update remains successful when push is disabled or fails; the record remains stored with `local`, `pending_*`, or `sync_failed` status and safe error feedback.

- [ ] **Step 1: Write failing tests** for bridge payload round-trip including nominal and `belum_ditentukan`, dry-run non-mutation, and failed push preserving the local record with `sync_failed`/error state. Add an idempotent changelog migration assertion.
- [ ] **Step 2: Run the focused bridge/command tests** and confirm any compatibility failure before implementation.
- [ ] **Step 3: Implement only compatibility fixes required by the failing tests; add one idempotent migration entry with Indonesian title/description and `down()` deleting only that exact entry. Do not alter bridge ownership, conflict, or metadata rules.
- [ ] **Step 4: Run the focused bridge/command tests** and verify dry-run remains mutation-free and failure never destroys local data.
- [ ] **Step 5: Run the migration twice in the test contract** and confirm exactly one changelog row exists.
- [ ] **Step 6: Commit** with `git add` limited to Task 4 files and `git commit -m "fix: keep dana talangan bridge monday compatible"`.

### Task 5: Full verification and review

**Files:**
- Review all Dana Talangan files changed in Tasks 1–4.
- Do not modify organization graph, Buku Saku Sales, or Pengeluaran files.

- [ ] **Step 1: Run `php artisan optimize:clear` and `php artisan view:cache`.**
- [ ] **Step 2: Run the full suite with `php artisan test --compact`.**
- [ ] **Step 3: Run `vendor/bin/pint --dirty --test`, `git diff --check`, and `npm run build`.**
- [ ] **Step 4: Verify routes and middleware with `php artisan route:list --path=dana-talangan`.**
- [ ] **Step 5: Start/reuse the existing development server and use Playwright at approximately 1440×900 and 390×844. Exercise navigation, list, filters, create, edit, status, commitment, Nominal, Penyelesaian, confirmation, validation, and export; inspect console logs, failed requests, horizontal overflow, modal focus/close behavior, and mobile completion of the workflow.
- [ ] **Step 6: Run the code-review checklist across correctness, readability, architecture, security/scope, performance, and regressions. Confirm only the focused Dana Talangan files plus the design/plan/changelog are in the final diff.
- [ ] **Step 7: Create one focused checkpoint commit if any verification-only fixes remain, then record the final commit hash and Monday readiness report.

## Self-review coverage

- Workbook contract: covered by Tasks 1, 2, and 4; metadata remains hidden and system-managed.
- CRUD workflow: covered by Tasks 1 and 2 plus browser verification.
- Filters/reporting/export: covered by Task 3 and browser verification.
- Access/scope and bridge failure preservation: covered by Tasks 3 and 4 and existing policy tests.
- Money/date/status integrity: covered by Tasks 1–3.
- Deferred finance complexity and unrelated modules: explicitly excluded from every task.
