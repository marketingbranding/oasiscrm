# Oasis CRM — AGENTS.md

Laravel 13 CRM with SQLite (dev), Google Sheets sync, Alpine.js + Tailwind UI.

## Commands

- `composer test` — runs `php artisan config:clear` then `php artisan test`
- `php artisan test tests/Feature/DashboardTest.php` — single test file
- `php artisan optimize:clear` — after any controller/view change
- `php artisan view:cache` — re-compile Blade templates
- `php artisan sheet:cleanup-meta` — remove stale Google Sheet metadata columns (`--dry-run`, `--branch=`)
- `composer dev` — concurrent server + queue + logs + Vite
- `npm run build` — Vite production build

## Controller changes — mandatory steps

After editing any controller:

1. `php artisan optimize:clear` (clears config, cache, compiled, events, routes, views)
2. `php artisan view:cache`
3. Run affected tests
4. Verify in browser (superadmin + branch-admin views, with and without branch/project filter)

## Architecture

- **Two sync systems with independent routes and tables:** `database.sync` (DatabaseController) syncs leads/general sheets to `database_sheet_records`; `konsumen-progress.sync` (KonsumenProgressController) syncs pipeline stage sheets to `konsumen_progress_sheet_rows`. Dashboard Sync button calls `database.sync`, not the progress sync.
- **Dana Talangan has its own two-way sync:** `dana-talangan.sync` and `dana-talangan:sync` use `DanaTalanganGoogleService`. The single canonical Google tab is `Talangan`; older month tabs are reference-only for inferring missing projects. The local `dana_talangans` table remains the dashboard cache; Google wins during Sync, while web CRUD pushes immediately.
- **Google Sheets:** 5 service classes under `app/Services/` (api, read, write, sync, konsumen sync)
- **Views** extend `layouts.crm` (not `layouts.app`). Uses `@yield('content')`, no Blade components.
- **Dashboard data sources:** Lead KPI and Action Queue query `database_sheet_records` (Google Sheet cache); Dana Talangan queries local `dana_talangans` table; Konsumen Progress queries `konsumen_progress_sheet_rows`; Sync Health queries `database_sheet_sync_statuses`.
- **Cache & session** both use `database` driver (SQLite table-based).

## UI conventions

- **Date fields must use the existing Oasis date picker**, not a visible native `<input type="date">` or a new calendar library. Use the `.date-wrapper` / `.date-display` / `.date-text` / `.date-arrow` structure backed by a visually hidden `<input type="date">`; behavior is implemented globally in `resources/js/crm-datepicker.js`. See `resources/views/crm/dana-talangan/create.blade.php` for the canonical markup.
- For date fields rendered dynamically by Alpine, keep the same markup and hidden native input so the global date-picker initializer can attach behavior. Extend `crm-datepicker.js` if dynamic initialization needs adjustment; do not duplicate calendar logic inside a Blade view.
- Preserve the shared date-picker behavior: it closes after selection, provides a `Hari Ini` action, closes on Escape/outside click, and positions itself above or below based on viewport space without expanding modal scroll areas.
- Month fields must use the Oasis month picker (`.month-wrapper` / `.month-display` / `.month-text` / `.month-arrow`) backed by a visually hidden `<input type="month">`. Behavior lives in `resources/js/crm-monthpicker.js`: year navigation, a 12-month grid, `Bulan Ini`, close-on-selection, and the same viewport-aware popup positioning as the date picker.
- **CRM data tables must use the shared `.crm-table-scroll` and `.crm-data-table` classes** from `resources/css/app.css`; the Database module is the canonical reference. Do not recreate grid, sticky-header, stripe, hover, typography, or scrolling styles inline in individual Blade views.
- Wide tables must keep important columns available through horizontal scrolling instead of hiding them on mobile. Use frozen identity columns where needed, keep actions in the final column, truncate long text with a full-value `title`, and render booleans with `.crm-boolean-box`. Domain/status colors belong in badges or specific cells, not as a replacement for the base zebra/hover row styling.
- Sortable CRM table headers should sort by direct header click and show `▼`/`▲` on the active column, matching the Database module. Do not introduce a dropdown sort menu unless the product explicitly requires one.
- Table action cells must match the Database module: `Edit` is a blue (`#0000ee`) bold underlined action, `Hapus` is red (`#c0392b`) bold underlined action with confirmation, and both remain on one line in the final column. Use generated Laravel URLs/routes rather than hardcoded paths, and safely encode any record data passed to Alpine.
- New table implementations must preserve the full canonical behavior together: 2px black cell grid, sticky black uppercase headers, compact Helvetica/Times typography, zebra rows, yellow hover, horizontal scrolling, correct pagination row numbers, direct-click sorting, frozen identity columns when useful, boolean boxes, and consistent final-column actions. Do not copy only part of the visual treatment.
- Keep `.crm-data-table` on the shared separated-border model (`border-collapse: separate`, zero spacing, single-sided 2px cell borders). Do not switch it back to collapsed borders: Chrome/Edge paint collapsed borders below sticky cells, causing frozen-column grid lines to disappear while scrolling.

## Routes & auth

- All CRM routes behind `auth` + `verified` + `password.changed` middleware
- Superadmin-only routes nested under `role:superadmin` middleware
- `canViewAllBranches()` = `isSuperadmin()` || `hasRole('pusat')`
- Roles: `superadmin`, `admin`, `manager`, `staff`, `pusat`

## Dashboard gotchas

- **Branch-admin path must pass `$selectedBranchId`.** The Blade view at line 190 uses `$selectedBranchId` in the sync form. If omitted, an undefined-variable 500 is raised. Set `$selectedBranchId = $branch->id` in the branch-admin path and include in `compact()`.
- **When no branch is selected (superadmin):** `$selectedBranchId` is null → `getKonsumenProgress()` aggregates across all active branches; `getSyncHealth()` returns null (section hidden via `@if(isset($syncHealth))`). Data queries that accept nullable `$branchId` show all branches.
- **Sync buttons on dashboard** post to `database.sync`. The separate `konsumen-progress.sync` route is not connected to the dashboard.

## Models

Key models and their backing tables:

| Model | Table | Purpose |
|-------|-------|---------|
| `DatabaseSheetRecord` | `database_sheet_records` | Cached Google Sheet rows (leads, etc.) |
| `DatabaseSheetSyncStatus` | `database_sheet_sync_statuses` | Last sync status per branch |
| `KonsumenProgressSheetRow` | `konsumen_progress_sheet_rows` | Cached pipeline stage rows |
| `KonsumenProgressSyncStatus` | `konsumen_progress_sync_statuses` | Pipeline sync status per branch |
| `DanaTalangan` | `dana_talangans` | Dana talangan records |
| `DanaTalanganSyncStatus` | `dana_talangan_sync_statuses` | Global Talangan sheet sync status |
| `ContentItem` | `content_items` | Task tracker items |

`KonsumenProgressSheetRow.row_data` is a JSON `array` cast — always access as array, not object.

## Test patterns

- In-memory SQLite (`RefreshDatabase`) — no external DB needed
- Factory for User exists; no factories for other models (create directly in tests)
- Routes under `password.changed` middleware: set `password_changed_at` on test users to avoid redirect
