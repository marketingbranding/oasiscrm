# Konsumen Progress 2.0 Audit

This audit captures the current Konsumen Progress implementation before UI migration. It is intentionally behavior-preserving: routes, authorization, scope, stage model, sync, and cache semantics remain the source of truth unless a later task explicitly changes them.

## Product Surface

Konsumen Progress is a branch-scoped operational monitoring workspace backed by a Google Sheets cache. It tracks consumers across seven verified progress stages. It is NOT a generic CRM pipeline, KPR underwriting, construction project system, document platform, collection, or after-sales ticket system. It has no consumer onboarding workflow beyond the verified stage pipeline, no per-consumer CRUD, no documents, no comments, no export/import, and no detail page.

Google integration: per-branch spreadsheets with eight required tabs (`data_konsumen`, `bi_checking`, `PSJB`, `pemberkasan`, `proses_bank`, `ppjb_dev`, `akad`, `bast` — case-sensitive, `PSJB` casing significant). `konsumen_progress_sheet_rows` is a replaceable per-branch cache; its IDs and `row_hash` are not stable collaboration identities, so universal comments are intentionally unsupported for this module (`CommentableAccessService`).

## Route Map

All routes are inside the protected CRM group and currently pass through `web`, `auth`, `active`, `verified`, `password.changed`, `operational.maintenance`, and `sales.access` before the route-specific permission middleware.

| Method | URI | Name | Controller | Permission middleware |
|---|---|---|---|---|
| GET | `/konsumen-progress` | `konsumen-progress.index` | `index` | `consumer_progress.view` |
| GET | `/konsumen-progress/stage` | `konsumen-progress.stage` | `stage` (JSON) | `consumer_progress.view` |
| POST | `/konsumen-progress/sync` | `konsumen-progress.sync` | `sync` | `consumer_progress.sync` |
| GET | `/konsumen-progress/sync/status` | `konsumen-progress.sync-status` | `syncStatus` (JSON) | `consumer_progress.sync` |

The scheduler runs `konsumen-progress:sync` every 10 minutes per active branch with a 30-minute overlap lock (`routes/console.php`).

## Permission And Role Matrix

Registered permissions: `consumer_progress.view`, `consumer_progress.sync`, plus unused generated scoped variants (`view_assigned`, `view_branch`, `view_all`, `sync_assigned`, `sync_branch`, `sync_all`, `manage_*`, `export_*`) that are defined in role mappings but have no module routes behind them.

Actual `role_permission` pivots in the deployed database:

| Role | Exact `consumer_progress.view` | Exact `consumer_progress.sync` | Effective access |
|---|---|---|---|
| superadmin | YES (wildcard) | YES (wildcard) | index + stage + sync |
| pusat | YES | YES | index + stage + sync (all scopes) |
| supervisor | YES | NO (only `sync_assigned`) | index + stage, no sync |
| manager | YES | NO | index + stage, no sync |
| branch_manager | YES | YES | index + stage + sync (branch) |
| admin | YES | YES | index + stage + sync (branch) |
| staff | NO (only `view_assigned`/`manage_assigned`) | NO | DENIED on index/stage/sync |
| sales | NO | NO | DENIED (`sales.access` allowlist excludes it) |
| sales_coordinator | NO | NO | DENIED |

### Catalog Versus Deployed DB Drift

`PermissionCatalog::rolePermissions()` currently grants `staff` the exact `consumer_progress.view` and `supervisor` the exact `consumer_progress.sync`. Fresh databases seeded by migration `2026_07_28_000012` reflect that (staff can view; supervisor can sync). The deployed development database predates the catalog update and has only scoped variants for those roles, which blocks them. Executable catalog is authoritative for new environments; the live DB must be re-seeded to match. This migration does not change either state.

Supplemental roles never grant permissions; `hasPermission()` resolves from the primary role's `role_permission` pivots only. Navigation shows Konsumen Progress for non-Sales users with `consumer_progress.view` plus a scoped permission; it is visibility only, never authorization.

## Scope Behavior

- Index: `OrganizationScopeService->branchIds($user, 'consumer_progress')`; explicit unauthorized `branch_id` denies 403; no branch resolves to the first accessible branch (or none).
- Stage JSON: view scope; explicit unauthorized branch denies 403; missing `sheet_id` returns 422.
- Sync/sync-status: `branchIds($user, 'consumer_progress', 'sync')` + `WorkspaceAccessService->canSyncBranch`; both must pass or 403.
- No project/customer/Sales/bank scope exists; the module is branch-scoped only.

## Storage And Cache Model

`konsumen_progress_sheet_rows` columns: `branch_id`, `sheet_id`, `sheet_name`, `row_hash`, `row_data` (JSON array cast), `synced_at`. Rows are wiped and reinserted per branch on every successful sync inside a transaction. `konsumen_progress_sync_statuses` records per-branch sync status, summary counts per sheet, timestamps, and initiator.

## Stage Model

`KonsumenPipelineService::STAGES` (fixed order, first to last):

1. `bi_checking` — BI Checking
2. `PSJB` — PSJB
3. `pemberkasan` — Pemberkasan
4. `proses_bank` — Proses Bank
5. `ppjb_dev` — PPJB Dev
6. `akad` — Akad
7. `bast` — BAST

Pipeline semantics:

- A consumer (`id_kavling` key from `data_konsumen`) is assigned to the furthest stage in which a row exists (stages iterated in reverse; first match wins).
- Stage date fields are optional (`tanggal_*`/`tgl_*` per stage); index does not currently filter by date.
- No percentage, no universal progress fraction, no transition rules, no rollback/reopen behavior, no per-stage timestamps exposed beyond row data. Do not invent them.
- `stage` aliases normalize text for the AI Chat search tool; `stage` JSON endpoint requires a canonical stage key or 404.

## Query / Filter / Sort Map

- No pagination, no server-side sort, no query-string filters besides `branch_id`.
- Index loads the full per-branch pipeline (all seven stages) via `buildPipeline()`.
- Client-side search filters the fully-loaded pipeline by consumer name and kavling (`window.__kpItems`). This is existing behavior over the complete branch dataset, not a single page; it is documented here as client-side aggregation, not server-side search. Server-side search is future work.
- Stage tabs switch the visible stage client-side (Alpine `stage` state); tabs are not URL-backed.
- `stage` JSON endpoint accepts `?stage=<canonical>&branch_id=<id>` and returns `{ ok, items, count, error, warnings, stale }`.

## Index Controller Flow

1. Resolve authorized branches; deny unauthorized explicit branch 403.
2. Fall back to first accessible branch.
3. Load sync status; compute `is_stale`.
4. Build full pipeline for the branch; if empty, surface "Data lokal belum tersedia" error.
5. `canSync = hasPermission('consumer_progress.sync') && canSyncBranch`.
6. Render the single index view.

## CRUD / Documents / Comments / Export / Import

None exist. There is no create/edit/delete route, no document upload/download, no detail route, no comments (intentionally excluded), and no export/import. The task's document, create/edit, comments, destructive, and export/import sections do not apply; they must not be invented.

## Activity And Audit

No activity-log system exists for this module. Sync status (status/message/summary/initiator/timestamps) is the audit record. Changes arrive via Google sync, not user edits, so no per-consumer activity log is required. Gap documented honestly: no per-row change history is retained (cache rows are replaced wholesale).

## Current UI Classification

| Pattern | Current state | Class |
|---|---|---|
| Page header | legacy `bg-[#5d8e8e]` bar | B → migrate to canonical `x-crm.page-header` |
| Presence | `x-crm.page-presence` | A retain |
| Branch selector bar | hand-built bordered bar with GET form + sync | B → migrate to `x-crm.toolbar`, preserve separate GET/POST forms |
| Sync control/panel | `x-crm.sync-control` + `x-crm.sync-status-panel` | A retain |
| Load errors | hand-built red box | B → migrate to `x-crm.alert` |
| Stage tabs with counts | hand-built buttons, Alpine stage state | D retain (module-specific), add `role="group"` + `aria-pressed` |
| Consumer cards | hand-built bordered cards | B → migrate to `x-crm.card padding="sm"` |
| Per-stage empty | plain `—` box | B → migrate to `x-crm.empty-state` |
| Client-side search | hand-built input + result message | D retain, add `aria-label` |
| No-branch state | plain box | B → migrate to `x-crm.empty-state` |
| `window.__kpItems` | inline JSON for client search | D retain |

## Performance Risks

- Index serializes the full per-branch pipeline into `window.__kpItems` for client-side search — unbounded for large branches. Existing behavior, documented as the primary risk.
- `buildPipeline` runs several queries per branch (rows by sheet, customer index) and is recomputed on each index/stage/sync-status call — no caching. Existing behavior; acceptable at current scale.
- No pagination; all stage cards render when the stage tab is active. Existing behavior.
- Sync wipes and reinserts all rows in a transaction; bounded by sheet size.

## Accessibility Gaps (Honest)

- Branch select uses `onchange="this.form.submit()"` — keyboard-usable but poor on mobile (submits on selection). Preserved as existing behavior; documented.
- Search input lacks `aria-label` (will add).
- Stage tab buttons lack pressed-state semantics (will add `aria-pressed`).
- Client-side result message uses inline HTML in `x-text` (plain string, safe; will keep text-only).
- Shared date/month picker caveats do not apply (no date fields on this page).

## Migration Plan

1. `docs/KONSUMEN_PROGRESS_2_AUDIT.md` (this file).
2. Index view only:
   - canonical `x-crm.page-header` (title, eyebrow, description, active scope);
   - `x-crm.toolbar` with the branch-selector GET form and the `x-crm.sync-control` in the actions slot (forms remain siblings, never nested — preserves the `DatabaseBranchSelectorTest` contract);
   - keep `x-crm.sync-status-panel`;
   - `x-crm.alert` for load errors;
   - keep the seven stage tabs (module-specific) with `role="group"` + `aria-pressed`; keep stage colors and counts;
   - `x-crm.card` for consumer cards; `x-crm.empty-state` for per-stage and no-branch states;
   - keep the client-side search with `aria-label` and text-only result message;
   - keep `window.__kpItems`.
3. No controller, route, permission, or service changes.
4. Add one idempotent changelog migration (category `changed`), no operational schema change.
5. Validate: `optimize:clear`, `view:cache`, route list, focused tests (SyncProgress, ModulePermissionAccess, DatabaseBranchSelector, SalesAccessRestriction, WorkspaceAccess), full suite, `composer test`, Pint, `npm run build`, `git diff --check`.

## Behavior Preserved (Explicit)

- All four route names, URIs, methods, and middleware stacks.
- `consumer_progress.view`/`sync` slugs and role pivots; no role's effective access changes.
- Branch scope via `OrganizationScopeService` + `WorkspaceAccessService`; unauthorized explicit branch denies 403/422.
- Seven stages, exact names/order; no new stages, transitions, percentages, or dates.
- Full-pipeline client-side search semantics (nama + kavling over loaded branch data).
- Sync flow, status table, scheduler, `--dry-run`-free behavior; `stage` JSON contract.
- No CRUD/documents/comments/export/import introduced.
- Indonesian terminology throughout.
