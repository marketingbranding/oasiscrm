# OASIS Buku Saku Sales 2.0 Audit and Migration Plan

This document records the executable Buku Saku Sales behavior before its Design System migration. `AGENTS.md`, `docs/DESIGN_SYSTEM.md`, and application code remain authoritative.

## Baseline

- Branch: `main`.
- Baseline commit: `1f4a170`.
- Design System 2.0 Phase 1 and Database 2.0 are complete.
- Focused Buku Saku baseline: 79 tests, 507 assertions.
- No browser automation or screenshots are available.
- There is no backend lead or agenda search parameter.

## Migration Boundary

Buku Saku 2.0 is a controlled presentation migration. It may reorganize the index, its three tabs, quick inputs, filters, lists, report presentation, reminder presentation, and directly related lead forms.

It does not change:

- routes or route names;
- permissions, policies, middleware, or organization scope;
- lead stages, timestamps, or reversal semantics;
- report metrics, periods, conversions, drilldowns, or sorting;
- lead and agenda pagination;
- Sales Agenda subtype, ownership, result, or reschedule behavior;
- daily reminder eligibility, dismissal, suppression, or destinations;
- duplicate-phone semantics;
- optimistic conflict, comments, mentions, presence, notifications, or export;
- Work Planner, Database, Dashboard, global shell, IAM, or another module.

## Route Map

All routes inherit `web`, `auth`, `active`, `verified`, `password.changed`, and `sales.access`.

| Method | URI | Route | Additional authorization |
|---|---|---|---|
| GET | `/buku-saku-sales` | `sales-pocketbook.index` | `SalesLeadPolicy::viewAny` |
| GET | `/buku-saku-sales/export` | `sales-pocketbook.export` | `permission:sales_pocketbook.export`, controller permission, policy, export scope |
| GET | `/buku-saku-sales/input` | `sales-leads.create` | `SalesLeadPolicy::create`; redirects to quick input |
| GET | `/buku-saku-sales/duplicate-phone` | `sales-leads.duplicate-phone` | `SalesLeadPolicy::viewAny`; visible records only |
| POST | `/buku-saku-sales/leads` | `sales-leads.store` | Sales lead request and create policy |
| GET | `/buku-saku-sales/leads/{sales_lead}/edit` | `sales-leads.edit` | update policy |
| PUT | `/buku-saku-sales/leads/{sales_lead}` | `sales-leads.update` | request, policy, locked reauthorization |
| PATCH | `/buku-saku-sales/leads/{sales_lead}/stage` | `sales-leads.stage.update` | update/reverse-stage policy and optimistic lock |
| POST | `/buku-saku-sales/agendas` | `sales-agendas.store` | request role gate and scoped manage permission |
| PATCH | `/buku-saku-sales/agendas/{agenda}` | `sales-agendas.update` | Sales Agenda subtype, scope, owner/branch checks, optimistic lock |
| POST | `/buku-saku-sales/agendas/{agenda}/reschedule` | `sales-agendas.reschedule` | same agenda checks and optimistic lock |
| POST | `/sales-reminders/dismiss` | `sales-reminders.dismiss` | primary Sales, server-owned reminder identity |

No route is added, removed, reordered, or renamed by this migration.

## Controller Flow

`SalesPocketbookController::index()` currently:

1. authorizes `SalesLead::viewAny`;
2. handles one-request reminder action suppression;
3. validates filters, period, report drilldowns, and report sorting;
4. normalizes `tab` to `leads`, `agenda`, or `report`;
5. resolves Sales Pocketbook branch, project, and visible-user scope;
6. denies explicit inaccessible branch, project, or monitoring Sales filters;
7. validates branch/project/Sales consistency;
8. resolves the weekly or custom report period;
9. loads the 20-row lead paginator named `page`;
10. loads the 20-row Sales Agenda paginator named `agenda_page`;
11. calculates report summary values and monitoring rows;
12. builds branch/project/Sales cascade payloads;
13. builds the primary Sales daily reminder state and action URLs;
14. renders the index with quick-input and collaboration context.

Explicitly requested unauthorized organization filters return denial rather than silently falling back. The UI migration must not pre-normalize tampered values in a way that bypasses these checks.

## Role and Scope Matrix

Permission resolution uses only the primary role. Supplemental roles do not grant permissions. Primary Sales restrictions are enforced separately by `sales.access`.

| Primary role | Default view scope | Default manage scope | Default export scope | Experience |
|---|---|---|---|---|
| Sales | own | own | own plus coarse export | personal |
| Sales Coordinator | own, team | own, team | none | monitoring |
| Supervisor | own, team, assigned | own, team, assigned | own, team, assigned plus coarse export | monitoring |
| Manager | assigned, branch | assigned, branch | assigned, branch plus coarse export | monitoring |
| Branch Manager | branch | branch | branch plus coarse export | monitoring |
| Pusat | all | all | all plus coarse export | monitoring |
| Admin | branch | branch | branch plus coarse export | monitoring |
| Staff | none | none | none | denied |
| Superadmin | registered-permission wildcard | registered-permission wildcard | registered-permission wildcard | monitoring |

The UI may hide actions that the current backend will always deny, but it must not infer broader access than these policies and scopes provide.

## Leads Contract

- Source query: `SalesLead::visibleTo($user)`.
- Organization filters: branch, project, and monitoring Sales owner.
- Domain filters: active lead source and latest completed stage.
- Stage filter means the selected stage is present and every later stage is absent.
- Period filtering is applied only for an explicit period or report metric drilldown.
- Report metric drilldowns use that metric's existing timestamp column.
- Ordering is `lead_date DESC`, then `id DESC`.
- Pagination remains 20 records through the `page` parameter.
- Display relations and comments count remain eagerly loaded.
- Quick create, add-another context, inline edit, standalone edit, duplicate warning, stage set/reverse, and optimistic conflict remain unchanged.

There is no backend search. Buku Saku 2.0 must not expose a client-only search that appears to cover all paginated records.

## Agenda Contract

- Only `item_type=agenda` and `agenda_type=buku_saku_sales` are listed.
- Organization scope uses Sales project, owner, and branch constraints with the existing legacy project-name fallback.
- Primary Sales is restricted to owned agendas.
- Normal Agenda view is limited to the selected/default weekly period by `scheduled_date`.
- Completed drilldown uses `status=done` and the period on `completed_at`.
- Missing-result drilldown uses all-time `done` agendas with a trimmed blank result.
- Ordering is `scheduled_date ASC`, then `start_time ASC`.
- Pagination remains 20 records through the independent `agenda_page` parameter.
- Completion requires a result and preserves an existing `completed_at` when repairing a legacy blank result.
- Reschedule atomically marks the original `rescheduled`, creates a planned linked replacement, and preserves the current owner/project contract.
- Comments count and optimistic tokens remain unchanged.

The replacement link exists through `rescheduled_from_id`; displaying that existing relationship is presentation-only.

## Report Contract

Periods are calculated in the application timezone:

- week: Monday through Sunday;
- custom: inclusive start and end dates.

Metrics remain event counts based on each stage's own timestamp in the selected period:

- lead baru;
- sudah dihubungi;
- sudah bertemu;
- sudah survei;
- UTJ;
- berkas lengkap;
- akad;
- agenda selesai.

Conversion values divide one period-event count by the preceding period-event count. They are not cohort conversions and can exceed 100 percent. A zero denominator remains `null` and is displayed as a dash. No UI label may imply trend, probability, or cohort retention.

`last_input` remains the all-time maximum lead/agenda creation timestamp within scope. Monitoring rows remain one row per visible Sales/project assignment generated by the existing service. Sorting remains server-side PHP collection sorting for the exact allowed keys and directions. Existing drilldown query parameters and XLSX data remain unchanged.

## Daily Reminder Contract

The reminder is authoritative and is shown only to primary Sales when at least one condition is true:

- no own visible lead has `lead_date=today`;
- no owned Sales Agenda is scheduled today unless cancelled or rescheduled;
- one or more all-time completed Sales Agendas have a trimmed blank result.

The reminder also reports whether the Sales user has a currently accessible assigned project. Dismissal remains keyed by user, fixed reminder key, and application-timezone date. The server owns that identity. `reminder_action` suppresses only the destination request; conflict data also suppresses the reminder so the conflict dialog remains primary.

Existing actions remain:

- Input Lead to the lead quick-input anchor;
- Isi Agenda to the Sales Agenda quick-input anchor when a project is assigned;
- the existing generic Work Planner fallback when no project is assigned;
- Lengkapi Hasil to the all-time missing-result Agenda drilldown;
- Tutup, optionally persisted for today.

No second reminder state, banner persistence, or notification channel will be added.

## Query-State Map

| Parameter | Owner | Contract |
|---|---|---|
| `tab` | page | `leads`, `agenda`, `report`; invalid values normalize to Leads |
| `branch_id` | scope | explicit inaccessible value denied |
| `project_id` | scope | explicit inaccessible value denied; must match branch |
| `sales_user_id` | monitoring scope | explicit invisible value denied; must match branch/project |
| `lead_source_id` | Leads/export | active source only |
| `stage` | Leads/export | exact existing stage key and latest-stage interpretation |
| `period_type` | Agenda/report/export | `week` or `custom` |
| `week` | period | required only for weekly mode |
| `date_from`, `date_to` | period | required and ordered only for custom mode |
| `report_metric` | lead drilldown/export | exact metric allowlist |
| `report_agenda_completed` | agenda drilldown/export | completed by `completed_at` in period |
| `report_agenda_missing_result` | agenda drilldown/export | all-time blank result |
| `sort`, `direction` | monitoring report | exact sort allowlist and `asc`/`desc` |
| `page` | Leads | independent 20-row paginator |
| `agenda_page` | Agenda | independent 20-row paginator |
| `input`, `lead_date` | quick lead | presentation/default context |
| `reminder_action` | reminder | one-request suppression redirect |

Tab links may preserve compatible organization and period context. They must remove unrelated domain drilldowns, sorting, and paginator state rather than creating hidden filters. Sorting, pagination, reset, and export must preserve their relevant current state.

## UI Pattern Inventory

Classification:

- **A - retain unchanged:** behavior or an existing authoritative system.
- **B - migrate to existing `x-crm` component:** direct canonical replacement.
- **C - extend existing `x-crm` component:** backward-compatible shared correction required.
- **D - Buku-Saku-specific:** domain presentation remains local.
- **E - postpone:** new product/backend behavior or cross-module work.

| Pattern | Class | Decision |
|---|---|---|
| Sales access, policies, organization scope | A | Preserve executable authorization |
| URL-backed Leads/Agenda/Laporan views | A/D | Preserve server navigation; add local accessible treatment |
| Page title and actions | B | Canonical `x-crm.page-header` and buttons |
| Scope/filter regions | B/D | Canonical toolbar/fields/chips; retain Sales cascade semantics |
| Period picker | D | Retain weekly/custom query behavior locally |
| Quick lead and agenda forms | B/D | Canonical fields/errors/buttons; retain fields and writes |
| Lead operational list | D | One responsive record DOM; no generic pipeline/grid abstraction |
| Lead stage controls | D | Preserve exact stages and optimistic workflow |
| Agenda actions | B/D | Semantic badges/fields/buttons around existing forms |
| Report metric blocks | D | Local repeated domain block; values remain server-owned |
| Monitoring table | B/D | Canonical table classes and direct links; local report columns |
| Reminder | A/B/D | Preserve state machine; migrate presentation only |
| Alerts and content states | B | Canonical alert/empty/loading primitives |
| Lead/stage modal lifecycle | B/C/D | Adopt canonical lifecycle only with workflow tests |
| Pagination | C | Extend only if fixed size and named paginator are supported |
| Comments, presence, conflict, toast | A | Reuse existing systems without duplication |
| Backend search | E | No current query contract; do not add misleading UI |
| Charts, Kanban, lead scoring, probabilities | E | Outside product and phase scope |

## Current UX and Accessibility Gaps

- Legacy page header and detached export action.
- Tabs lack explicit current-page semantics and horizontal overflow treatment.
- Most form and filter labels are not associated with stable input IDs.
- Index validation reports only the first error at page level.
- Lead and stage dialogs duplicate modal lifecycle and do not lock body scroll.
- Duplicate-phone feedback has no pending/live state or stale-request protection.
- Stage and mobile action targets are smaller than 44px.
- Agenda statuses use one accent rather than semantic state variants.
- True-empty and filtered-empty states use the same generic message.
- Lead rows and complete edit payloads are duplicated for desktop and mobile.
- Tables need explicit column scope/caption treatment.
- Raw paginator links lack local range context.
- Source-contract tests cannot replace browser verification.

## Existing Performance Risks

These risks are documented but are not service-architecture work for this visual migration:

- Monitoring rows execute approximately ten aggregate queries per Sales/project row.
- Monitoring rows are calculated for non-Sales users regardless of active tab.
- Both lead and agenda paginators execute on every index request.
- Report rows are unpaginated and sorted in memory.
- XLSX leads and agendas are fully materialized in memory.
- Cascade project/Sales relationship payloads can grow for large organizations.
- The current desktop/mobile lead markup doubles record and edit-payload HTML.
- Timestamp drilldowns use `whereDate()`, which can weaken index use on MySQL.
- Legacy agenda project-name fallback adds an unindexed OR path and can be ambiguous.

The migration must add no decorative query, must preserve pagination, and must reduce rather than increase duplicate HTML payload.

## Existing Backend Gaps Outside This Phase

- General Work Planner policy and write paths do not consistently isolate the Sales Agenda subtype.
- Some Sales assignment checks use raw relationships rather than current assignment windows.
- Non-Sales lead visibility and agenda owner visibility have different user-intersection behavior.
- Sales Agenda creation combines a hard-coded role allowlist with scoped permissions.
- Generic cross-module `canViewAllBranches()` remains involved in some agenda write checks.
- The no-project reminder fallback does not create a Sales Agenda subtype and therefore does not satisfy the daily count.
- Legacy agenda project-name fallback can diverge between summaries and project drilldowns.
- Report sorting has no deterministic secondary key.

These are production risks requiring separate authorization/data-integrity decisions. They must not be silently changed as part of Buku Saku 2.0.

## Migration Plan

1. Record this audit and unchanged behavior contract.
2. Adopt the canonical page header, role/scope context, permission-aware actions, and accessible URL-backed tabs.
3. Recompose the authoritative daily reminder and role-aware quick inputs with canonical fields, errors, buttons, and mobile hierarchy.
4. Replace duplicate lead desktop/mobile rendering with one responsive operational list while preserving stage, edit, comments, and pagination behavior.
5. Migrate Agenda presentation, semantic status, existing result/reschedule actions, linkage context, and distinct empty states.
6. Migrate report period/scope toolbar, existing metrics, monitoring table, sorting, and drilldowns without changing service values.
7. Correct responsive spacing, touch targets, labels, focus, modal lifecycle, validation announcements, and source contracts.
8. Add focused role/scope, tampered-filter, pagination, rendering, query-state, reminder, and regression tests.
9. Update Design System documentation, add one idempotent Changelog entry, build assets, and run full validation.

## Responsive Decisions

- Use one lead record DOM across desktop and mobile; rearrange it with CSS rather than rendering complete duplicate collections.
- Keep forms one column at narrow widths and group related fields progressively above tablet sizes.
- Keep tabs horizontally scrollable without turning them into client-only tabs.
- Keep report tables complete inside table-local horizontal scrolling.
- Keep primary Sales actions reachable without horizontal scrolling.
- Preserve current picker behavior and viewport-safe modal scrolling.
- Target at least 44px for standalone touch controls.

## Validation Plan

- exact route/middleware map before and after;
- primary Sales, team, assigned, branch, all, denied, and supplemental-role scope cases;
- explicit inaccessible branch, project, and Sales filters;
- both independent paginators and query preservation;
- lead-source, stage, duplicate, quick-input, comments-count, and empty-state behavior;
- Sales Agenda subtype, owner, project, result, drilldown, pagination, and reschedule linkage;
- weekly/custom metrics, monitoring rows, sorting, drilldowns, and export parity;
- all reminder states, dismissal, one-request suppression, conflict precedence, and no-project state;
- canonical header, accessible tabs, labels, role-aware controls, mobile source contracts, and permission-aware actions;
- Database, Work Planner authorization, Dashboard, and route regression coverage;
- focused tests, full Artisan suite, `composer test`, Blade cache, Vite build, changed-file Pint, and whitespace validation.

Browser verification will be reported only if it is actually performed at approximately 360, 390, 430, 768, 1024, and 1366px.
