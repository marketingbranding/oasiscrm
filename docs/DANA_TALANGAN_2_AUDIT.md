# Dana Talangan 2.0 Audit

This audit captures the current Dana Talangan implementation before UI migration. It is intentionally behavior-preserving: routes, authorization, scope, persistence, sync, statuses, export/import, and optimistic locking remain the source of truth unless a later task explicitly changes them.

## Product Surface

Dana Talangan records bridge-fund commitments (penalties/willingness records) per branch and project, synced with a canonical Google Sheets tab. It is not lending software, a repayment scheduler, an accounting ledger, or a collection system. The module records commitment status per consumer and free-text settlement progress only.

Canonical Google integration: tab `Talangan`, range `A:Q`, visible columns `A:N`, hidden metadata columns `O:Q` (`oasis_sync_id`, `oasis_deleted_at`, `oasis_deleted_by`). Historical tabs are reference-only for project inference. Local `dana_talangans` is the application cache and uses soft deletes. Google canonical rows win during sync; web CRUD pushes immediately.

## Route Map

All routes are inside the protected CRM group and currently pass through `web`, `auth`, `active`, `verified`, `password.changed`, `operational.maintenance`, and `sales.access` before the route-specific permission middleware.

| Method | URI | Name | Controller | Permission middleware |
|---|---|---|---|---|
| GET | `/dana-talangan` | `dana-talangan.index` | `index` | `bridge_fund.view` |
| GET | `/dana-talangan/create` | `dana-talangan.create` | `create` | `bridge_fund.manage` |
| POST | `/dana-talangan` | `dana-talangan.store` | `store` | `bridge_fund.manage` |
| GET | `/dana-talangan/{dana_talangan}` | `dana-talangan.show` | resource show | `bridge_fund.view` |
| GET | `/dana-talangan/{dana_talangan}/edit` | `dana-talangan.edit` | `edit` | `bridge_fund.manage` |
| PUT | `/dana-talangan/{dana_talangan}` | `dana-talangan.update` | `update` | `bridge_fund.manage` |
| DELETE | `/dana-talangan/{dana_talangan}` | `dana-talangan.destroy` | `destroy` | `bridge_fund.manage` |
| GET | `/dana-talangan/kavling-options` | `dana-talangan.kavling-options` | `kavlingOptions` | `bridge_fund.view` |
| POST | `/dana-talangan/sync` | `dana-talangan.sync` | `sync` | `bridge_fund.manage` |
| GET | `/dana-talangan/sync/status` | `dana-talangan.sync-status` | `syncStatus` | `bridge_fund.manage` |
| GET | `/dana-talangan/export` | `dana-talangan.export` | `export` | `bridge_fund.export` |
| GET | `/dana-talangan/export-template` | `dana-talangan.export-template` | trait export template | `bridge_fund.export` |
| GET | `/dana-talangan/import` | `dana-talangan.import` | trait import | `bridge_fund.manage` |
| POST | `/dana-talangan/import` | `dana-talangan.import-store` | `importStore` | `bridge_fund.manage` |
| POST | `/dana-talangan/bulk-delete` | `dana-talangan.bulk-destroy` | `bulkDestroy` | `bridge_fund.manage` |
| POST | `/dana-talangan/bulk-update` | `dana-talangan.bulk-update` | `bulkUpdate` | `bridge_fund.manage` |
| GET | `/dana-talangan/{dana_talangan}/detail` | `dana-talangan.detail` | `detail` | `bridge_fund.view` |

Note: `dana-talangan.show` (resource show) has no dedicated controller method; the controller uses `RedirectsShowToEdit` so `show` redirects to `edit`. `dana-talangan.detail` returns JSON consumed by the shared detail modal.

The scheduler also runs `dana-talangan:sync` every 10 minutes via `routes/console.php` with an overlap lock.

## Permission And Role Matrix

Registered `bridge_fund.*` permissions in `PermissionCatalog` and the permissions table (15 total): `bridge_fund.view`, `bridge_fund.manage`, `bridge_fund.export`, `bridge_fund.configure`, `bridge_fund.delete_permanently`, plus scoped `view_assigned`, `view_branch`, `view_all`, `manage_assigned`, `manage_branch`, `manage_all`, `export_assigned`, `export_branch`, `export_all`, `sync_assigned`, `sync_branch`, `sync_all`.

Actual `role_permission` pivots:

| Role | bridge_fund permissions in DB | `bridge_fund.view` | `bridge_fund.manage` | `bridge_fund.export` |
|---|---|---|---|---|
| superadmin | wildcard (all) | YES | YES | YES |
| pusat | view, manage, export, view_all, manage_all, export_all, sync_all | YES | YES | YES |
| supervisor | view, view_assigned, manage_assigned, export_assigned, sync_assigned | YES | NO | NO |
| manager | view, view_assigned, view_branch, export_assigned, export_branch | YES | NO | NO |
| branch_manager | view, manage, export, view_branch, manage_branch, export_branch, sync_branch | YES | YES | YES |
| admin | view, manage, export, view_branch, manage_branch, export_branch, sync_branch | YES | YES | YES |
| staff | catalog: view, manage, view_assigned, manage_assigned | YES (catalog) | YES (catalog) | NO |
| sales | none | NO | NO | NO |
| sales_coordinator | none | NO | NO | NO |

Effective access (route middleware checks the exact slug):

| Role | Effective access |
|---|---|
| superadmin | FULL (index, CRUD, export, import, sync, bulk) |
| pusat | FULL (index, CRUD, export, import, sync, bulk) |
| supervisor | view-only (index, detail, kavling-options); no manage/export |
| manager | view-only (index, detail, kavling-options) |
| branch_manager | full CRUD + export/import + sync in branch scope |
| admin | full CRUD + export/import + sync in branch scope |
| staff | per catalog: CRUD in assigned scope, no export/sync |
| sales | DENIED |
| sales_coordinator | DENIED |

Supplemental roles never grant permissions; `hasPermission()` resolves from the primary role's `role_permission` pivots only.

### Catalog Versus Deployed DB Drift For Staff

`PermissionCatalog::rolePermissions()` currently grants `staff` the exact slugs `bridge_fund.view` and `bridge_fund.manage` (plus scoped `view_assigned`/`manage_assigned`). Fresh databases seeded by migration `2026_07_28_000012` therefore allow staff index + assigned-scope CRUD. The deployed development database predates this catalog update and still has only `bridge_fund.view_assigned`/`manage_assigned` for staff, which blocks index access (no exact `bridge_fund.view`). Executable catalog is the source of truth for new environments; the live DB must be re-seeded to match. This migration does not change either state.

### Drift Versus Intended Access

The task brief assumed Superadmin + primary Pusat as the primary audience. Executable code grants branch-scoped manage/export to `admin` and `branch_manager`, and view to `supervisor`/`manager`. This migration preserves executable behavior; it does not broaden or narrow it. The focused test suite (`DanaTalanganGoogleSyncTest`) exercises an admin-with-branch user as the main actor, so the admin path is the verified contract. Any access policy change requires a separate task with a deliberate matrix update.

## Scope Behavior

- Index: `OrganizationScopeService->branchIds($user, 'bridge_fund')` then explicit branch filter denied with 403 when not in allowed set.
- Create/store: `branchIds($user, 'bridge_fund', 'manage')` + `WorkspaceAccessService->canEditBranch`. Branch is derived from the selected project via `DanaTalanganGoogleService->branchIdForProject`; mismatched project/branch rejected.
- Edit/update/destroy: same manage branch scope + `canEditBranch`.
- Detail JSON: view branch scope + `canViewBranch`.
- Kavling options: view branch scope + `canViewBranch`; project must map to the requested branch.
- Bulk destroy/update: every selected record verified against manage branch scope + `canEditBranch`; missing/inaccessible IDs abort 403.
- Import: `Importable` trait computes editable branch set via `WorkspaceAccessService`; requested branch must be editable.
- Export: `branchIds($user, 'bridge_fund', 'export')`, explicit unauthorized branch denied 403.

No customer/unit scope exists. Records reference a consumer by name string and a unit by `kav` string; there is no `customer_id` or `unit_id` relation.

## Storage And Schema

`dana_talangans` columns (migrations `2026_06_19_061724`, `2026_07_09_100000`, `2026_07_09_100001`, `2026_07_15_010000`, `2026_07_21_000005`):

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `oasis_sync_id` | uuid nullable unique | Google sync identity |
| `sheet_name`, `sheet_row_number` | string / unsigned int | Google location |
| `sync_status` | string default `pending` | sync bookkeeping |
| `last_sync_error` | text nullable | |
| `source_hash`, `last_synced_at` | string / timestamp | sync bookkeeping |
| `tanggal` | date | submission/disbursement date |
| `nama_konsumen` | string(255) | consumer name string |
| `kav` | string(100) nullable | unit code string |
| `project_name` | string(255) nullable | project name string |
| `pinjam_nama` | boolean default false | |
| `pekerjaan` | string(255) nullable | |
| `status_perkawinan` | string(100) nullable | |
| `umur` | integer nullable | |
| `nama_marketing` | string(255) nullable | |
| `tgl_komitmen` | date nullable | |
| `penyelesaian` | text nullable | free-text settlement progress |
| `konfirmasi_keuangan` | boolean default false | |
| `branch_id` | FK branches | |
| `status` | string(50) default `sanggup` | see below |
| `created_by`, `updated_by` | FK users | |
| `deleted_at` | timestamp nullable | soft delete |

Model casts (`DanaTalangan`): `tanggal`/`tgl_komitmen` date, `pinjam_nama`/`konfirmasi_keuangan` boolean, `umur` integer, `last_synced_at` datetime.

### Amount Precision

There is NO monetary amount column in this module. `dana_talangans` does not store nominal, outstanding, or settled amounts. `penyelesaian` is free text. Therefore no Rupiah formatting, no amount summary, no monetary validation, and no float business calculation apply. The summary area must not invent a nominal total. This audit explicitly confirms: any displayed summary is limited to record counts by status.

### Status And Settlement Contract

- Status values (allowlisted in requests and import): `sanggup`, `tidak_sanggup`, `lunas`. Legacy value `aktif` was migrated to `sanggup` in `2026_07_09_100001`.
- `status` is the only settlement signal. `lunas` means settled; `sanggup`/`tidak_sanggup` are commitment states.
- `penyelesaian` is a free-text progress note, not a computed settlement amount.
- Bulk update can set status to any of the three values.
- No approval, partial-payment, overdue, penalty, due-date, disbursement, or repayment-calculation states exist. Do not invent them.

## Query / Filter / Sort Map

Index query (`DanaTalanganController@index`):

- Scope: `branch_id IN allowed`.
- Optional branch filter (`branch_id`), project filter (`project_name` exact string), status filter (`status`), date filters (`date_from`/`date_to` or `month_from`/`month_to` via `filter_mode`), search (`nama_konsumen` case-insensitive LIKE).
- `filter_mode` ∈ `date` | `month`; invalid falls back to `date`. Swapped when start > end.
- Sort allowlist: `tanggal`, `nama_konsumen`, `kav`, `project_name`, `pekerjaan`, `status_perkawinan`, `umur`, `nama_marketing`, `tgl_komitmen`, `status`. Default `tanggal desc`. Direction `asc`/`desc`.
- Pagination: `per_page` default 15, `all` supported.
- Eager loads: `branch`, `creator`, `comments` count.
- Search also produces a tracking summary (case-insensitive grouped consumer count, with `within_range` count).
- Export mirrors the same filters (branch, project, status, search, date range).

Project filter options = active OASIS projects (branch-scoped) merged with distinct synced `project_name` values.

## CRUD Flow

- Create: routed form `crm.dana-talangan.create` OR inline add modal on index. `StoreDanaTalanganRequest` (authorize returns true; real checks in controller), validates date/name/project/status/booleans and optional demographic fields. Store derives branch from project, validates kav, creates local record, pushes to Google (`push`); failure keeps local row and flashes error.
- Edit: routed form `crm.dana-talangan.edit` OR inline edit modal on index. `UpdateDanaTalanganRequest` adds `expected_updated_at`; controller runs `OptimisticLockService` conflict check then `execute`, pushes to Google, clears presence.
- Destroy: controller verifies manage scope + `canEditBranch`, calls `DanaTalanganGoogleService->delete`, soft-deletes local row, redirects back preserving filters.
- Bulk: `bulkDestroy` and `bulkUpdate` operate on selected IDs verified against manage scope; status bulk values are the three statuses.

## Evidence / Documents

None. No upload, attachment, file storage, preview, or download behavior exists in this module. The task's evidence sections do not apply and must not be invented.

## Export / Import

- Export: `DanaTalanganExport`, single sheet `Dana Talangan`, 14 columns (`No`, `Tanggal`, `Nama Konsumen`, `Kav`, `Proyek`, `Pinjam Nama`, `Pekerjaan`, `Status Kawin`, `Umur`, `Marketing`, `TGL Komitmen`, `Penyelesaian`, `Konfirmasi`, `Status Cicilan`), auto-filter, styled via `ExcelStyle`. Route guarded by `bridge_fund.export`.
- Template: `DanaTalanganExport::generateTemplate` adds a `Cabang` first column, hidden `KavLists` helper sheet, cascading branch/project/kav dropdowns, and dropdowns for booleans and status.
- Import: `DanaTalanganImport` (strict-ish legacy parser, not the hardened bulk-onboarding reference), XLSX only, skips header, requires name + date, defaults unknown status to `sanggup`, resolves branch from file or fallback, creates local records only. `importStore` triggers a global Google sync after import when the actor has `bridge_fund.manage_all`.

## Activity And Audit

`DanaTalangan` uses `LogsActivity`. Label = `nama_konsumen (Dana Talangan)`. Creation/update/cancellation are logged with actor; the controller also uses `CollaborationNotificationService->recordUpdated` on update. Google sync keeps its own status table (`dana_talangan_sync_statuses`). No separate audit system is needed. Gap: activity is not surfaced in the current detail modal.

## Optimistic Locking

Update requires `expected_updated_at` and passes through `OptimisticLockService->execute`; stale writes return the shared conflict response and do not overwrite. The inline edit modal posts the stored `updated_at` token via the `oasis-submit-conflict` dispatch flow. Destroy does not use an optimistic token.

## Current UI Classification

| Pattern | Current state | Class |
|---|---|---|
| Page header | `x-crm.page-header color="#f1c40f"` legacy variant | B → migrate to canonical |
| Presence | `x-crm.page-presence` | A retain |
| Sync panel | `x-crm.sync-status-panel` + `x-crm.sync-control` | A retain |
| Search/filter toolbar | hand-built bordered div | B → migrate to `x-crm.toolbar` |
| Filter modal | hand-built overlay, Escape + outside click, no focus trap | B → improve minimally, document gaps |
| Active filter chips | hand-built spans | B → migrate to `x-crm.filter-chip` |
| Tracking summary | hand-built bordered panel (search result) | D retain (module-specific) |
| Data table | `.crm-table-scroll` + `.crm-data-table` + frozen name/select/row columns | A/D retain |
| Direct-click sorting | `x-crm.click-sort-th` | A retain |
| Status badge | hand-built span with colors | B → migrate to `x-crm.status-badge` |
| Boolean boxes | `.crm-boolean-box` | A retain |
| Empty row | plain `<td>` text | B → migrate to `x-crm.empty-state` |
| Pagination | `x-crm.pagination` | A retain |
| Add/edit modals | inline overlay forms, `@click.away`, no focus trap | B → preserve flow, document gaps |
| Detail modal | `x-crm.detail-modal` + layout `crmDetailModal` | A/D retain |
| Bulk bar | `x-crm.bulk-bar` + `crm-bulk.js` | A retain |
| Export/import menu | `x-crm.export-import` | A retain |
| Create/edit routed forms | legacy bordered forms, shared `.date-wrapper`/`.select-wrapper` JS | B → canonical header + submit guard, keep field internals |
| Import page | legacy instructions + form | B → canonical header |
| Summary metrics | none | E → add verified status-count summary (new) |

## Performance Risks

- Index eager-loads branch, creator, comments; single page of 15 rows — fine.
- Search tracking summary loads all matching records (`nama_konsumen`, `tanggal`) and groups in PHP — unbounded when a broad search matches many rows. Existing behavior, preserved; documented as a risk.
- `per_page=all` loads the full filtered set — existing escape hatch, preserved.
- Project filter options merge distinct synced project names — bounded by data.
- Kavling options use `data_kav` cache with a 10-minute live fallback — bounded.
- Proposed summary addition adds one indexed `GROUP BY status` count over the filtered scope.

## Accessibility Gaps (Honest)

- Index add/edit/filter modals rely on Escape and outside click; no focus trap or focus restoration (layout `crmDetailModal` also lacks it). Documented, not fixed in this pass to avoid breaking verified inline forms.
- Detail modal uses `alert()` on fetch failure (layout code).
- Date/month pickers share the known repository-wide keyboard/ARIA caveat (`AGENTS.md` section 13).
- Bulk bar destroy uses native `confirm()` via `CrmBulk` (existing legacy pattern).
- Status is conveyed with text plus color; the badge migration keeps text.

## Migration Plan

1. `docs/DANA_TALANGAN_2_AUDIT.md` (this file).
2. Controller: add verified status-count summary over the exact filtered scope (one GROUP BY query), pass to index view. No other controller logic changes.
3. Index view: canonical `x-crm.page-header` (description + primary action), `x-crm.toolbar`, `x-crm.filter-chip`, `x-crm.status-badge`, `x-crm.empty-state`, compact summary cards. Preserve all tested contracts: search `aria-label="Cari Nama Konsumen"`, `Filter Dana Talangan`, `Terapkan Filter`, `Rentang Tanggal`, `month-wrapper`/`month-display`, `kavlingOptionsUrl`, `changeAddBranch()`/`changeAddProject()`, `Sync Sekarang`, `Tambah Dana Talangan`, `crm-table-scroll`/`crm-data-table`/`crm-boolean-box`, `Tanggal ▲`, no `aria-label="Sort Tanggal"`, inline `color:#0000ee`/`color:#c0392b` action styles, tracking summary strings (`2 kali`, `1 dalam rentang aktif`).
4. Create/edit/import pages: canonical page headers; keep form internals and dependent picker behavior; add duplicate-submit guard to routed create/edit forms.
5. Add one idempotent changelog migration (category `changed`), no operational schema change.
6. Validate: `optimize:clear`, `view:cache`, route list, focused Dana Talangan tests, full suite, `composer test`, Pint, `npm run build`, `git diff --check`.

## Behavior Preserved (Explicit)

- All 17 route names, URIs, methods, and middleware stacks.
- Permission slugs and role_permission pivots; no role's effective access changes.
- Branch/project scope via `OrganizationScopeService` + `WorkspaceAccessService`; explicit unauthorized filters deny 403.
- `status` values `sanggup`/`tidak_sanggup`/`lunas`; free-text `penyelesaian`; no settlement arithmetic.
- No amount column, no monetary handling, no new financial calculations.
- Google push/sync/delete behavior; `--dry-run` non-mutation; metadata columns.
- Export columns/format/template; import parser semantics; bulk destroy/update.
- Optimistic lock (`expected_updated_at`) and conflict dialog flow.
- Soft-delete destructive semantics; no restore invented.
- Search semantics (case-insensitive `nama_konsumen` only) and tracking summary.
- Pagination (`per_page` 15/`all`), sort allowlist, filter preservation through pagination/export.
- Indonesian terminology throughout.
