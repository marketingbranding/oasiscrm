# OASIS Work Planner 2.0 Audit and Migration Plan

This document records the executable Work Planner behavior before its controlled Design System migration. `AGENTS.md`, `docs/DESIGN_SYSTEM.md`, and application code remain authoritative.

## Baseline

- Repository baseline: `dfdbfe6` after Full Maintenance Mode.
- Completed work to preserve: Design System 2.0 Phase 1, Database 2.0, Buku Saku Sales 2.0, Full Maintenance Mode, and Work Planner module-aware authorization fix.
- Focused Work Planner baseline: 38 tests, 266 assertions.
- Focused collaboration/comments baseline: 82 tests, 698 assertions.
- Route audit command confirmed 15 `content-calendar.*` routes.
- Browser verification is unavailable and is not claimed.

## Completion Status

Work Planner 2.0 is implemented as a controlled presentation migration. It consumes canonical page header, toolbar, buttons, status badges, alert, empty/loading states, field composition, cards, modal, table classes, page presence, comments links, and shared conflict/toast systems while preserving the executable contracts in this document.

Implemented changes:

- canonical page header, active view/scope/overdue summary, URL-backed view navigation, toolbar, filter modal, and active filter chips;
- permission-aware create, export/import, selection, drag, edit, delete, and bulk affordances at the coarse route-permission level while backend policies remain authoritative;
- Today hierarchy prioritizes overdue, then today's tasks, agenda, content, and tomorrow preview;
- item cards use semantic status badges, accessible selection labels, and textual overdue indicators;
- board columns and All view use canonical empty states and table source contracts;
- routed create/edit/import pages use canonical header/card/field/alert/button composition;
- detail/day overlays include dialog roles, labelled headings, accessible close buttons, loading/error feedback, and shared toast feedback for failed status updates;
- existing Sortable, date/time picker, comments, presence, notification, and conflict systems remain unchanged.

Browser verification remains unavailable and is not claimed.

## Migration Boundary

Work Planner 2.0 is a controlled UI/UX migration. It may reorganize the index, six stable views, filters, cards/boards/table, create/edit/import pages, detail overlays, status controls, bulk presentation, loading/empty/error/conflict states, and Work-Planner-specific presentation fragments.

It must not change:

- routes, route names, middleware, policies, FormRequest validation, data model, migrations, import/export mappings, or spreadsheet architecture;
- `ContentItem::visibleTo()` behavior, scoped permission behavior, primary-role permission resolution, or supplemental-role non-escalation;
- Work Planner module-aware branch write checks, `work_planner.manage_all`, `work_planner.create`, `work_planner.update`, `work_planner.assign`, and `work_planner.export`;
- item types, status values, status completion mappings, priority values, date semantics, assignment rules, branch/PIC validation, return-view behavior, comments, mentions, presence, notifications, and optimistic conflict handling;
- Buku Saku Sales Agenda business rules, Database, Dashboard, Konsumen Progress, Dana Talangan, Pengeluaran, maintenance administration, IAM, shell, comments architecture, notification architecture, or picker architecture.

## Route Map

All Work Planner routes inherit `web`, `auth`, `active`, `verified`, `password.changed`, `operational.maintenance`, and `sales.access`.

| Method | URI | Route | Action | Extra middleware |
|---|---|---|---|---|
| GET | `/content-calendar` | `content-calendar.index` | index | none |
| GET | `/content-calendar/create` | `content-calendar.create` | create | `permission:work_planner.create` |
| POST | `/content-calendar` | `content-calendar.store` | store | `permission:work_planner.create` |
| GET | `/content-calendar/export` | `content-calendar.export` | export | `permission:work_planner.export` |
| GET | `/content-calendar/export-template` | `content-calendar.export-template` | export template | `permission:work_planner.export` |
| GET | `/content-calendar/import` | `content-calendar.import` | import page | `permission:work_planner.create` |
| POST | `/content-calendar/import` | `content-calendar.import-store` | import upload | `permission:work_planner.create` |
| GET | `/content-calendar/{content_calendar}` | `content-calendar.show` | redirect to edit | none |
| GET | `/content-calendar/{content_calendar}/edit` | `content-calendar.edit` | edit | `permission:work_planner.update` |
| PUT/PATCH | `/content-calendar/{content_calendar}` | `content-calendar.update` | update | `permission:work_planner.update` |
| DELETE | `/content-calendar/{content_calendar}` | `content-calendar.destroy` | destroy | `permission:work_planner.update` |
| GET | `/content-calendar/{content_calendar}/detail` | `content-calendar.detail` | JSON detail | none |
| PATCH | `/content-calendar/{content_calendar}/status` | `content-calendar.update-status` | status update | `permission:work_planner.update` |
| POST | `/content-calendar/bulk-update` | `content-calendar.bulk-update` | bulk update | `permission:work_planner.update` |
| POST | `/content-calendar/bulk-delete` | `content-calendar.bulk-delete` | bulk delete | `permission:work_planner.update` |

The route binding resolves `ContentItem::findOrFail()`. No route is added, removed, reordered, or renamed by this migration.

## Controller Flow

`ContentCalendarController::index()` currently:

1. authorizes `ContentItem::viewAny`;
2. normalizes `view` to `today`, `calendar`, `tasks`, `agenda`, `content`, or `all`;
3. reads `month`, `year`, branch, project, type, status, priority, PIC, and search query parameters;
4. resolves an explicit branch through `WorkspaceAccessService` or defaults to the user's primary/first accessible branch;
5. forces contextual type and clears incompatible type/status/priority on `tasks`, `agenda`, and `content` views;
6. loads accessible branches, filter projects, form projects, visible items, comments count, creator, branch, and assignees;
7. applies filters and search;
8. computes type counts and view-specific collections;
9. renders the legacy `crm.content-calendar.index` view.

Create/store and edit/update preserve routed full-page forms. Store resolves an editable branch through module-aware `canManageBranch(..., 'work_planner')`, validates account PICs against active branch-compatible users, requires `work_planner.assign` for assigning another user, normalizes type-specific fields, creates the item, syncs assignees, and redirects to `return_view`.

Update authorizes the existing item, validates the submitted target branch and assignment changes, uses `OptimisticLockService`, reauthorizes the locked row, normalizes type-specific fields, syncs assignees, clears editing presence, and notifies present authorized collaborators.

Status update preserves the current board/drag endpoint, checks optimistic timestamp, validates status against the persisted item type, updates completion timestamp server-side, clears presence, and notifies present authorized collaborators.

Bulk update/delete preserve existing endpoints, per-item visible-record loading, per-item policy checks, no optimistic lock, no collaborator notification, and no transaction guarantee.

Import/export preserve the existing PhpSpreadsheet synchronous architecture and current mappings.

## Role And Scope Matrix

Permission resolution uses only `users.role_id`. Supplemental roles do not grant permissions. Superadmin has a wildcard only for registered permissions.

| Primary role | Default Work Planner view scope | Create | Update | Assign | Export | Effective write scope |
|---|---|---:|---:|---:|---:|---|
| Sales | own | yes | yes | no | no | editable branch; update only creator/assignee |
| Sales Coordinator | own, team | yes | yes | yes | no | editable branch |
| Supervisor | own, team, assigned | yes | yes | yes | yes | editable branch |
| Manager | assigned, branch | yes | yes | yes | yes | editable branch |
| Branch Manager | branch | yes | yes | yes | yes | editable branch |
| Pusat | all | yes | yes | yes | yes | `work_planner.manage_all` across active branches |
| Admin | branch | yes | yes | yes | yes | editable branch |
| Staff | own | yes | yes | no | no | editable branch |
| Superadmin | wildcard | yes | yes | yes | yes | every active branch |

Executable visibility uses scoped permissions primarily to derive branch IDs, then returns team-visible items in those branches plus personal items created by or assigned to the user. This coarse behavior is existing production behavior and is preserved.

The authorization fix that must remain intact:

- normal create/update uses `WorkspaceAccessService::canManageBranch($user, $branch, 'work_planner')`;
- `work_planner.manage_all` permits writes across active branches;
- unrelated module `view_all` does not grant normal Work Planner management;
- narrower users require branch edit rights or the verified legacy primary-branch fallback;
- inactive/invalid branches are denied;
- primary Sales remains restricted to created or assigned items for update;
- assigning another user requires `work_planner.assign`;
- branch and assignment failures preserve existing field validation messages.

## Item Type And Status Matrix

| Type | Statuses | Completed/terminal statuses | Primary date semantics |
|---|---|---|---|
| `task` | `todo`, `in_progress`, `completed`, `lost_track` | `completed` | `deadline_date` drives `scheduled_date` |
| `agenda` | `planned`, `confirmed`, `done`, `cancelled`, `rescheduled` | `done`, `cancelled`, `rescheduled` | `start_date` drives `scheduled_date`; start/end time and location are agenda-specific |
| `content` | `idea`, `content_in_progress`, `done_editing`, `uploaded` | `uploaded` | `start_date`/scheduled date is content publication date |

Priorities are `low`, `medium`, `high`, and `urgent`. Normal create/update requires priority only for tasks and normalizes agenda/content priority to `medium`; existing bulk/import behavior is preserved even where it is looser.

## View Behavior Map

| View | Current behavior to preserve | Migration direction |
|---|---|---|
| Hari Ini | Loads `agendaToday`, `tasksToday`, `overdueTasks`, `contentToday`, and `tomorrowItems` | Main operational landing: overdue first, then today tasks, agenda, content, tomorrow preview |
| Kalender | Month/year grid, previous/next month, six-week Monday-first grid, day modal, item detail access | Preserve calculations; improve hierarchy, item density, today marker, local scrolling, and mobile fallback without hiding items |
| Tugas | Full matching task collection grouped by task status | Status workspace with counts, priority, deadline, overdue, PIC, comments, detail/edit/status actions |
| Agenda | Full matching agenda collection grouped by agenda status | Agenda workspace showing date/time, agenda type, location, PIC, project, status, comments, and actions |
| Konten | Full matching content collection grouped by content status | Content workspace showing platform, format, tujuan konten, scheduled date, status, comments, and actions |
| Semua | Mixed paginated list, 20 per page, with query-string preservation | Canonical operational table/list with type/title/branch/project/date/priority/PIC/status/comments/actions |

All six views remain URL-backed through `view`. Browser refresh and back/forward behavior must continue to work.

## Filter And Query-State Map

Current query parameters:

- `view`, `branch_id`, `project_name`, `item_type`, `status`, `priority`, `pic`, `search`, `month`, `year`, and `page`.

Current semantics:

- search covers title and task detail only;
- project filter is exact `project_name`, not a project ID;
- status is exact status and is cleared when incompatible with the contextual view type;
- priority forces task semantics and is cleared for agenda/content views;
- PIC searches serialized external PIC names and assignee names;
- explicit unauthorized branch is denied;
- tab links currently reset filters to only `view`;
- pagination and export preserve query string;
- reset preserves current view/month/year/search.

Migration direction:

- use `x-crm.toolbar` with visible search and at most two high-frequency filters;
- move remaining filters into one accessible advanced-filter modal;
- show active count and `x-crm.filter-chip` output;
- preserve backend semantics and avoid client-only hidden filters;
- preserve query state through pagination/export/month navigation;
- clear branch-incompatible project values in UI only, while server remains authoritative.

## Create/Edit Field Map

| Field group | Task | Agenda | Content |
|---|---|---|---|
| Common | title, branch, status, notes | title, branch, status, notes | title, branch, status, notes |
| Detail | task detail | task/detail text | not applicable |
| Project | project name string | project name string | cleared |
| Visibility | personal/team | personal/team | forced team |
| Dates | start date optional, deadline required | start date required, deadline required | content date required |
| Time | cleared | start time required, end time optional | cleared |
| Priority | required | forced medium | forced medium |
| Assignment | account PIC and external PIC allowed | account PIC and external PIC allowed | cleared |
| Type-specific | platform accepted | agenda type, location, platform accepted | platform, content format, tujuan konten |

Dynamic behavior must show only applicable fields, disable/clear stale incompatible controls where presentation can safely do so, preserve old input after validation errors, update status options by type, and leave server validation authoritative.

## Authorization Flow

1. Route middleware enforces authentication, active/verified/password-changed lifecycle, Full Maintenance Mode, primary Sales allowlist, and coarse create/update/export permission where applicable.
2. `ContentItemPolicy::viewAny` requires a Work Planner scoped view permission.
3. `ContentItem::visibleTo()` applies current executable branch/team/personal visibility behavior.
4. Direct item view checks branch visibility and personal/team/creator/assignee rules.
5. Update/delete require `work_planner.update`, view permission, and module-aware branch management.
6. Primary Sales update additionally requires creator or account-assignee relationship.
7. Assignment to another user requires `work_planner.assign`; PIC users must be active and branch-compatible.
8. Import/export/bulk retain their current direct endpoint protections and known gaps.

## Collaboration Flow

- Index emits `x-crm.page-presence` with `page-key="work-planner"`.
- Edit emits `x-crm.page-presence` with `record-type="content_item"`, record ID, and editing mode.
- Edit form keeps `data-conflict-form` for shared conflict handling and presence session injection.
- Normal update and status update use `OptimisticLockService` and shared `x-conflict-dialog` on stale writes.
- Successful normal/status updates clear editing presence and send `record_updated` notifications to present users after current policy recheck.
- Cards and detail actions use universal comments alias `planner-item`; comments and mentions remain shared systems.
- No second polling, comments, notification, toast, conflict, or presence system will be introduced.

## UI Pattern Inventory

Classification:

- **A - retain unchanged:** behavior or authoritative shared system.
- **B - migrate to existing `x-crm` component:** direct canonical replacement.
- **C - extend existing `x-crm` component:** backward-compatible shared correction if needed.
- **D - Work-Planner-specific:** domain presentation remains local.
- **E - postpone:** backend/product behavior outside this phase.

| Pattern | Class | Decision |
|---|---|---|
| Routes, middleware, policies, scopes, FormRequests | A | Preserve executable contracts |
| URL-backed six-view navigation | A/D | Preserve `view`; add accessible tab treatment locally |
| Page header | B | Use canonical `x-crm.page-header` |
| Presence, comments, conflict, toast, notifications | A | Reuse existing systems |
| Search/filter toolbar | B/D | Use `x-crm.toolbar`, fields, filter chips, and local query semantics |
| Advanced filter overlay | B | Use `x-crm.modal` where safe |
| Today sections | B/D | Use sections/cards/empty states with local planner item summaries |
| Calendar grid/day presentation | D | Keep local; no new dependency or JS calculation rewrite |
| Task/Agenda/Content status boards | D | Keep local status groups; no universal Kanban component |
| All view | B/D | Use canonical table classes and local Work Planner columns |
| Item card/summary | D | Extract Work-Planner-specific repeated summary if reuse is proven |
| Create/edit fields | B/D | Use `x-crm.field`, controls, alerts, buttons; keep local dynamic behavior |
| Date/time pickers | A/B | Reuse existing date wrapper/time component; no new picker |
| Detail overlay | B/D | Prefer canonical modal lifecycle; retain detail JSON endpoint |
| Bulk bar | B/D | Use `x-crm.bulk-bar` layout while preserving local selected IDs/endpoints |
| Import page | B | Canonical header/card/field/alert/button presentation only |
| Drag/drop | A/D/E | Preserve existing Sortable status behavior; do not add new drag behavior |
| Import/export backend hardening | E | Known backend work outside UI migration |
| Sales Agenda subtype isolation | E | Existing documented backend gap; do not alter in UI migration |

## Accessibility Gaps

- legacy page header lacks canonical description/action hierarchy;
- tab links have no `aria-current` and minimal focus/active semantics;
- filter, day, and detail overlays are custom dialogs without full labelled dialog, focus trap, focus restoration, or body-lock contract;
- close buttons use `×` without accessible labels;
- board drag/drop lacks keyboard equivalent;
- checkbox controls have no explicit accessible labels;
- inline status controls are drag-only in board views;
- native `confirm()` remains on delete and bulk delete;
- board AJAX failures use native `alert()`;
- calendar uses a fixed wide grid and small controls on mobile;
- empty states are repeated text paragraphs rather than distinct state components;
- detail fetch lacks loading/error/denied/network states;
- date/month picker keyboard gaps are inherited shared limitations and will be documented honestly.

## Performance Risks

Existing risks to preserve and report, not silently fix in this phase:

- Today loads several independent collections with repeated eager loads;
- Calendar, Tasks, Agenda, and Content views materialize full matching collections;
- only All view paginates;
- comments count is loaded for list/detail display;
- assignees, creator, branch, filter projects, and form users can create large payloads for Pusat/Superadmin;
- PIC/search filters use leading-wildcard text matching and JSON-text search;
- board and bulk policy checks can issue per-item authorization queries;
- export loads all matching records into memory;
- import loads the active worksheet synchronously.

UI migration must not add decorative queries, duplicate complete desktop/mobile item collections, or client-side fake search over only loaded records.

## Existing Backend Gaps Outside This Phase

These are executable production risks but are not changed by Work Planner 2.0 unless explicitly scoped later:

- Work Planner scoped `own/team/assigned/branch` visibility collapses mostly to branch plus team/personal rules;
- generic Work Planner routes can see/mutate Sales Agenda subtype records;
- import does not use normal FormRequests/policies and has looser validation;
- export and import column orders are not round-trip compatible;
- project identity is stored as a plain string, not an authorized project FK;
- `asset_url` is accepted by requests but cleared by generic normalization;
- bulk update/delete do not use optimistic locks, update notifications, or transactions;
- normal delete is hard delete and not optimistic-lock protected.

## Migration Plan

1. Commit this audit and unchanged behavior contract.
2. Migrate page shell: canonical header, role/scope summary, permission-aware actions, URL-backed view navigation, and toolbar/chips.
3. Migrate Today view hierarchy with clear overdue, today, agenda, content, and tomorrow states.
4. Migrate Calendar view presentation while preserving server grid/month calculations.
5. Migrate Task, Agenda, Content status workspaces and All paginated table/list.
6. Migrate create/edit/import forms to canonical fields, alerts, buttons, and safer dynamic state presentation.
7. Migrate detail, status actions, bulk presentation, and failure/conflict feedback without changing endpoints.
8. Correct responsive source contracts, labels, focus, accessible names, and modal behavior where in scope.
9. Add focused rendered markup, query-state, authorization-regression, collaboration-source, and UI contract tests.
10. Update Design System documentation, add one Changelog migration, build assets, and run full validation.

## Explicit Business Behavior Preserved

- six URL-backed views and all current query parameter names;
- all item types, statuses, status labels, priority values, and completion timestamp behavior;
- scheduled-date, deadline, agenda time, and content date semantics;
- branch/project resolution behavior, including plain `project_name` filtering;
- primary-role permissions, Work Planner scoped permissions, module-aware writes, Sales update restriction, and supplemental-role non-escalation;
- assignment authorization and branch-compatible active-user validation;
- current import/export/template routes and mappings;
- bulk update/delete endpoints and server validation;
- detail JSON endpoint;
- comments alias `planner-item`, mentions, presence, notifications, and optimistic conflict behavior;
- current Indonesian terminology.

## Validation Plan

- focused Work Planner and authorization suites;
- collaboration, presence, optimistic lock, comments, mentions, and notification suites;
- route middleware inspection before and after;
- rendered source-contract tests for canonical page header, toolbar, tabs, filters, Today sections, boards, All table, forms, bulk bar, comments, presence, and conflict wiring;
- regression tests for Work Planner authorization fix, supplemental-role non-escalation, Sales restrictions, inaccessible/inactive branch denial, and Full Maintenance Mode route presence;
- `php artisan optimize:clear`, `php artisan route:list`, focused tests, full `php artisan test`, `composer test`, `npm run build`, `vendor/bin/pint --dirty --test`, `php artisan view:cache`, and `git diff --check`.

Browser verification will be reported only if actually performed at approximately 360, 390, 430, 768, 1024, and 1366px.
