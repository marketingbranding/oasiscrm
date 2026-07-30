# OASIS Database 2.0 Audit and Migration Plan

This document records the executable Database module state before its Design System migration. `AGENTS.md`, `docs/DESIGN_SYSTEM.md`, and application code remain authoritative.

## Baseline

- Branch: `main`.
- Design System 2.0 Phase 1 baseline: `43dd830`.
- Focused Database/access/sync/conflict baseline: 149 tests, 1,101 assertions.
- No browser automation or screenshots are available.

## Completion Status

Implemented as a controlled visual migration. Route, data-source, organization-scope, Google synchronization, client search/sort, conflict, presence, and deep-link contracts remain unchanged. Database now uses canonical page, toolbar, state, status, button, control, and modal primitives while its dynamic table machinery remains local.

## Route and Permission Map

All routes inherit `auth`, `active`, `verified`, `password.changed`, and `sales.access`.

| Method | URI | Name | Exact route permission |
|---|---|---|---|
| GET | `/database` | `database.index` | `database.view` |
| GET | `/database/sheet/{branchId}/{sheetName}` | `database.sheet` | `database.view` |
| POST | `/database/sync` | `database.sync` | `database.sync` |
| GET | `/database/sync/status` | `database.sync-status` | `database.sync` |
| POST | `/database/records` | `database.records.store` | `database.edit` |
| PUT | `/database/records/{record}` | `database.records.update` | `database.edit` |
| DELETE | `/database/records/{record}` | `database.records.destroy` | `database.edit` |

There is no Database project filter, export, import, pagination, bulk endpoint, detail route, or comments integration.

## Data Source and Request Flow

- Displayed records come from `database_sheet_records`, not live Google reads.
- Google is contacted for sheet-title ordering, explicit synchronization, and immediate create/update/tombstone writes.
- The initial HTML embeds the first sheet; other sheets lazy-load through `database.sheet`.
- Search and three-state sorting run in the browser over the active loaded sheet.
- Recognized deep-link query parameters are `branch_id`, `sheet`, and `add`.
- Full sync requires complete responses, then transactionally replaces only the selected branch cache.
- Failed or incomplete full sync preserves the previous cache.
- Update keeps structured HTTP 409 conflict behavior and remote-acknowledgement-first local changes.
- Delete remains a remote metadata tombstone followed by local hiding.

## Scope and Authorization

- Explicit inaccessible or out-of-organization `branch_id` requests return 403.
- Primary Sales remains denied by the Sales allowlist.
- Supplemental roles do not grant Database permissions or bypass Sales restrictions.
- Sheet, sync/status, create/update/delete endpoints recheck organization scope and branch rights.
- UI write controls currently do not reflect all backend edit checks; Database 2.0 will render them only when existing coarse permission, manage scope, and branch edit access all permit the action.
- Backend route/controller authorization remains authoritative.

## UI Classification

- **A - retain unchanged:** routes, branch denial, cache source, sync transaction, sync status/polling, optimistic conflict, presence, canonical wide table, formula protection, metadata hiding, client search/sort cycle.
- **B - migrate to existing CRM primitives:** page header, branch/search toolbars, buttons, alerts, empty/loading states, status badges, static field/control styling.
- **C - extend existing primitive:** modal lifecycle events, active-search filter-chip removal slot, sync button visual API only if needed.
- **D - keep Database-specific:** lazy sheet tabs, dynamic columns, metadata-driven fields, frozen row-number/`id_kavling`, skeleton, active-sheet refresh and scroll restoration, template-row add requirement.
- **E - postpone:** pagination, export/import, bulk actions, comments, project filters, universal data grid, server search, date/month picker architecture changes, replacement of native delete confirmation.

## Accessibility and UX Gaps

- Branch and search controls lack fully associated labels.
- Tabs lack tab semantics and arrow-key behavior.
- Sortable headers are pointer-only and lack `aria-sort`.
- Frozen-column control is an unlabeled emoji span.
- Add/edit dialogs lack role, labelled heading, initial focus, trap, restoration, and body lock.
- Dynamic labels lack stable `for`/`id` associations.
- Ordinary edit 422/network feedback can remain invisible outside an open conflict dialog.
- Several standalone controls are below the 44px mobile target.
- Important truncated values rely mainly on `title`.

## Existing Performance Risks

- The controller currently loads every selected-branch cache record before retaining the first sheet for initial rendering.
- Lazy sheet responses return all rows for a sheet.
- No server pagination exists.
- Full sync reads wide ranges and duplicates row metadata by design.
- Live sheet-title lookup occurs on each configured page request.

Database 2.0 does not change record-fetch queries, payload fields, synchronization volume, client record duplication, or pagination. The index performs one additional existing-service manage-scope resolution, plus the existing branch edit-right check, solely to keep add/edit/delete controls consistent with backend authorization.

## Existing Backend Risks Outside This Phase

- Default branch resolution is not intersected as explicitly as requested branches in `index()`.
- Generated local stable IDs are not always persisted remotely.
- Writes rely on cached physical Google row numbers.
- Sync-ID fallback lookup is global and unindexed.
- Notification authorization does not fully mirror Database module scope.

These are documented production risks, not UI migration work. They require separate backend/data-integrity design and tests.

## Migration Plan

1. Adopt canonical page header, explicit branch/sheet scope summary, branch toolbar, permission-aware write actions, and canonical static states.
2. Keep client search semantics while adding an accessible search/reset/result-count experience and active search chip.
3. Preserve dynamic table data while adding keyboard sort buttons, `aria-sort`, canonical row/action classes, formula context, and accessible frozen-state control.
4. Preserve existing sync components and active-sheet refresh orchestration; consolidate only surrounding status/warning presentation.
5. Extend canonical modal with opened/closed lifecycle events, then migrate edit and add shells independently.
6. Keep dynamic metadata form rendering local; add stable generated control IDs, associated labels, canonical controls, and visible non-409 edit feedback.
7. Add responsive/source contracts and role/scope rendering tests.
8. Document Database as the first full operational Design System migration and add one idempotent changelog entry.

## Unchanged Business Behavior

- route names, methods, middleware, and named URLs;
- branch resolution and explicit denial;
- WorkspaceAccessService and OrganizationScopeService;
- Google cache as the displayed data source;
- complete-response transactional cache replacement;
- formula, metadata, header normalization, and tombstone rules;
- client-side search and sort semantics;
- Google row-number display;
- lazy tabs and `branch_id`/`sheet`/`add` deep links;
- sync endpoint, polling, stale metadata, inline refresh, and cached-data preservation;
- immediate remote writes and optimistic 409 conflict workflow;
- presence behavior and the absence of Database comments;
- all existing columns and business labels;
- no pagination, export, import, bulk action, or project filter.

## Manual Acceptance Matrix

- Superadmin: branch choices, branch-specific sync, Google link, add/edit/delete, tabs, search, sort, conflicts.
- Pusat: permitted branches and no invented configuration action.
- Branch user: assigned branches only and explicit unauthorized branch denial.
- Read-only role: view/search/sort without write actions.
- Sales: direct route remains denied.
- Mobile 360/390/430px: toolbars, tabs, modal completion, table-local scroll, row actions, sync feedback.
- Failure states: never synced, stale, failed sync, sheet network failure, filtered-empty, true-empty, refresh failure, edit conflict.
