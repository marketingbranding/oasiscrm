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

## Changelog — mandatory for application changes

- Every completed change that affects application behavior, UI, workflow, data, authorization, integration, configuration, bug handling, or other user-visible results **must include an Oasis Changelog entry in the same change set**.
- Changelog entries must be deployed through an idempotent data migration under `database/migrations/`. Never rely only on `php artisan tinker`, a local database insert, or a seeder that production may not run.
- Insert with `DB::table('changelogs')->updateOrInsert(...)` using a stable identity such as `version` + `title`, so local runs and deployments cannot create duplicates. Set `created_by` to `null` for system-authored release entries and include timestamps.
- Write `title` and `description` in clear Indonesian for Oasis users. Describe the visible outcome and workflow benefit; avoid commit hashes, implementation internals, test details, and developer-only terminology.
- Use the existing categories only: `added` for new capabilities, `fixed` for bug fixes, `changed` for behavior changes, and `removed` for removed capabilities. Group closely related work into one concise entry instead of creating one row per edited file.
- `version` is optional. Use the release version when it is explicitly known; do not invent or increment a version without release context.
- The migration `down()` method must remove only the exact entry introduced by that migration.
- Pure test changes, documentation changes, formatting, refactors with no runtime behavior change, and edits to agent instructions do not require an application changelog entry.
- Before finishing, run the migration, verify exactly one matching row exists, and confirm the entry renders on the Oasis Changelog page. Include the changelog migration in the same commit as the application change.

## Architecture

- **Two sync systems with independent routes and tables:** `database.sync` (DatabaseController) syncs leads/general sheets to `database_sheet_records`; `konsumen-progress.sync` (KonsumenProgressController) syncs pipeline stage sheets to `konsumen_progress_sheet_rows`. Dashboard Sync button calls `database.sync`, not the progress sync.
- **Dana Talangan has its own two-way sync:** `dana-talangan.sync` and `dana-talangan:sync` use `DanaTalanganGoogleService`. The single canonical Google tab is `Talangan`; older month tabs are reference-only for inferring missing projects. The local `dana_talangans` table remains the dashboard cache; Google wins during Sync, while web CRUD pushes immediately.
- **Dana Talangan form options are cascading:** Cabang and Proyek come from Oasis `Branch`/`LeadMaster`; Kav options come from each branch spreadsheet's `data_kav` through `DanaTalanganOptionService` (local sheet cache first, live Google fallback). Superadmin and `pusat` may request every branch; branch admins may only request their own. All records still write to the single global `Talangan` tab.
- **Google Sheets:** 5 service classes under `app/Services/` (api, read, write, sync, konsumen sync)
- **Views** extend `layouts.crm` (not `layouts.app`). Uses `@yield('content')`, no Blade components.
- **Dashboard data sources:** Lead KPI and Action Queue query `database_sheet_records` (Google Sheet cache); Dana Talangan queries local `dana_talangans` table; Konsumen Progress queries `konsumen_progress_sheet_rows`; Sync Health queries `database_sheet_sync_statuses`.
- **Work Planner reuses `content_items`:** `item_type` is `task`, `agenda`, or `content`; legacy rows are Task/Tim. `scheduled_date` is the common calendar/reminder date (task deadline, agenda start, content publication). Personal items are visible to creator/assigned users plus superadmin/pusat; Team items are visible within the branch.
- **Cache & session** both use `database` driver (SQLite table-based).

## UI conventions

- **Transient operation feedback must use the shared Oasis toast stack in `layouts.crm`.** Server redirects should flash `success`, `error`, or `warning`; AJAX flows should dispatch `window.oasisToast(message, type)` (or the equivalent `oasis:toast` event). Do not recreate page-level or modal-level success/error/warning banners for CRUD outcomes.
- Keep field validation, detailed duplicate warnings, empty states, access/assignment guidance, confirmations, and presence information inline because users need that context to remain visible. Optimistic-lock HTTP 409 responses must use the shared `oasis-conflict` dialog, not a toast. Toast messages must be plain text and must not expose secrets or unnecessary personal data.
- **Search and filter toolbars must follow the Dana Talangan pattern.** Search is a separate, always-visible toolbar control and is not counted as a domain filter. When a page has more than two domain filters, put every domain filter behind one `Filter` button that opens a single modal; do not scatter filter selects or date controls across the page.
- The shared advanced-filter behavior must stay consistent: show the number of active filters on the `Filter` button, summarize submitted filters as chips, provide `Hapus semua filter`, preserve search when filters are applied/reset, and preserve submitted filters when search is submitted. Sorting, pagination, and export links must retain both search and active filter query parameters.
- Advanced-filter modals must use the existing square Oasis style with black borders, `x-cloak`, Escape and outside-click close behavior, a mobile-safe scroll area, and clear `Terapkan Filter` / `Reset Filter` actions. A filter becomes active only after its form is submitted.
- Cascading branch/project filters must clear an incompatible selected project when the branch changes. When all branches are shown, project labels must include branch context so distinct records never appear as ambiguous duplicate names; do not globally deduplicate valid project IDs by display name.
- **Date fields must use the existing Oasis date picker**, not a visible native `<input type="date">` or a new calendar library. Use the `.date-wrapper` / `.date-display` / `.date-text` / `.date-arrow` structure backed by a visually hidden `<input type="date">`; behavior is implemented globally in `resources/js/crm-datepicker.js`. See `resources/views/crm/dana-talangan/create.blade.php` for the canonical markup.
- For date fields rendered dynamically by Alpine, keep the same markup and hidden native input so the global date-picker initializer can attach behavior. Extend `crm-datepicker.js` if dynamic initialization needs adjustment; do not duplicate calendar logic inside a Blade view.
- Preserve the shared date-picker behavior: it closes after selection, provides a `Hari Ini` action, closes on Escape/outside click, and positions itself above or below based on viewport space without expanding modal scroll areas.
- Month fields must use the Oasis month picker (`.month-wrapper` / `.month-display` / `.month-text` / `.month-arrow`) backed by a visually hidden `<input type="month">`. Behavior lives in `resources/js/crm-monthpicker.js`: year navigation, a 12-month grid, `Bulan Ini`, close-on-selection, and the same viewport-aware popup positioning as the date picker.
- Time fields must use the Oasis time picker (`x-crm.time-field` with `.time-wrapper` / `.time-display` / `.time-text` / `.time-arrow`) backed by a visually hidden `<input type="time">`; never show a native time input. Behavior lives in `resources/js/crm-timepicker.js`: 24-hour and minute wheels, exact one-minute selection, keyboard operation, `Sekarang`, explicit confirmation, dynamic initialization, and viewport-aware positioning shared with the date and month pickers.
- **CRM data tables must use the shared `.crm-table-scroll` and `.crm-data-table` classes** from `resources/css/app.css`; the Database module is the canonical reference. Do not recreate grid, sticky-header, stripe, hover, typography, or scrolling styles inline in individual Blade views.
- Wide tables must keep important columns available through horizontal scrolling instead of hiding them on mobile. Use frozen identity columns where needed, keep actions in the final column, truncate long text with a full-value `title`, and render booleans with `.crm-boolean-box`. Domain/status colors belong in badges or specific cells, not as a replacement for the base zebra/hover row styling.
- Sortable CRM table headers should sort by direct header click and show `▼`/`▲` on the active column, matching the Database module. Do not introduce a dropdown sort menu unless the product explicitly requires one.
- Table action cells must match the Database module: `Edit` is a blue (`#0000ee`) bold underlined action, `Hapus` is red (`#c0392b`) bold underlined action with confirmation, and both remain on one line in the final column. Use generated Laravel URLs/routes rather than hardcoded paths, and safely encode any record data passed to Alpine.
- New table implementations must preserve the full canonical behavior together: 2px black cell grid, sticky black uppercase headers, compact Helvetica/Times typography, zebra rows, yellow hover, horizontal scrolling, correct pagination row numbers, direct-click sorting, frozen identity columns when useful, boolean boxes, and consistent final-column actions. Do not copy only part of the visual treatment.
- Keep `.crm-data-table` on the shared separated-border model (`border-collapse: separate`, zero spacing, single-sided 2px cell borders). Do not switch it back to collapsed borders: Chrome/Edge paint collapsed borders below sticky cells, causing frozen-column grid lines to disappear while scrolling.
- Navigation tabs are view context, not filters: changing tabs must not inject `item_type`, `status`, or other domain filters into the URL. Filters become active only after the user submits the Filter form. Form data fields must never be reused as redirect filter parameters; use a dedicated, validated return parameter such as `return_view` and redirect without filter query strings after create/update.

## Routes & auth

- All CRM routes behind `auth` + `verified` + `password.changed` middleware
- Superadmin-only routes nested under `role:superadmin` middleware
- `canViewAllBranches()` = `isSuperadmin()` || `hasRole('pusat')`
- Roles: `superadmin`, `admin`, `manager`, `staff`, `sales`, `pusat`

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
| `ContentItem` | `content_items` | Work Planner task, agenda, and content items |

`KonsumenProgressSheetRow.row_data` is a JSON `array` cast — always access as array, not object.

## Test patterns

- In-memory SQLite (`RefreshDatabase`) — no external DB needed
- Factory for User exists; no factories for other models (create directly in tests)
- Routes under `password.changed` middleware: set `password_changed_at` on test users to avoid redirect
