# OASIS Design System 2.0 Audit

This audit records the executable repository state before Phase 1 implementation. `AGENTS.md` and application code remain authoritative when this document becomes stale.

## Baseline

- Branch: `main`.
- Baseline commit: `33bbf4a` after Application Shell 2.0, grouped navigation, and Dashboard 2.0.
- Frontend: Blade, Alpine.js, Tailwind/PostCSS, and Vite. No browser automation or component framework is installed.
- Shared CSS and tokens live in `resources/css/app.css`.
- Shared CRM components live in `resources/views/components/crm/`; no parallel component namespace is justified.

## Classification

- **A - canonical:** preserve and reuse.
- **B - reusable with correction:** retain the abstraction, then correct its API or accessibility.
- **C - duplicated:** consolidate behind a narrow shared primitive.
- **D - module-specific:** keep local.
- **E - legacy:** preserve until a dedicated migration.

## Pattern Inventory

| Pattern | Class | Current reference and decision |
|---|---|---|
| CRM shell and named page slots | A | `layouts/crm.blade.php`, `crm-shell.js` |
| Navigation, topbar, account menu | A | Existing `x-crm.*` components |
| Toast, conflict, sync, presence, comments | A | Authoritative domain systems; do not duplicate |
| Canonical data table | A | `.crm-table-scroll`, `.crm-data-table`; preserve separated borders and sticky headers |
| Direct-click sorting | A | `x-crm.click-sort-th`; dropdown sorting is E |
| Dashboard KPI and timeline | D | Dashboard reference only, not generic CRM primitives |
| `x-crm.page-header` | B/E | Widely used but limited; extend compatibly without restyling all consumers |
| Buttons and icon buttons | C | Repeated across every operational module |
| Bounded cards and page sections | C | Repeated across administration, planner, finance, sync, and collaboration views |
| Status labels | C | Repeated in users, health, sync, imports, and comments |
| Inline alerts | C | Repeated across imports, forms, sync, comments, and feedback |
| Empty and loading states | C | More than sixty ad hoc state messages |
| Field label/help/error composition | C | Repeated in every major form family |
| List toolbar and filter chips | C | Dana Talangan, Pengeluaran, Work Planner, and users |
| Modal shells | C | Fourteen-plus overlays; accessibility is inconsistent |
| Date/time picker behavior | B | Keep existing components and JavaScript; date/month keyboard gaps remain |
| Month-field component | B, deferred | Shared JavaScript exists, but safe adoption is not proven in Phase 1 |
| Pagination, bulk bar, export menu | B | Existing APIs need focused correction before broad adoption |
| Tabs | C, deferred | URL-backed and local-state tabs have different contracts |
| Skeleton | D | Only Database currently has a genuine skeleton |
| Module tables, forms, cards, boards | D | Preserve domain workflows for later migration |

## Phase 1 Components

The following are justified by current repetition and imminent showcase/Dashboard use:

- `x-crm.button` and `x-crm.icon-button`;
- `x-crm.card` and `x-crm.section`;
- `x-crm.status-badge`;
- `x-crm.alert`;
- `x-crm.empty-state` and `x-crm.loading-state`;
- `x-crm.toolbar` and `x-crm.filter-chip`;
- `x-crm.field` and `x-crm.input-error`;
- `x-crm.modal`;
- a backward-compatible extension of `x-crm.page-header`.

All authorization remains at the caller. Components contain no module routes, permission checks, business-status inference, or data queries.

## Rejected or Premature Components

- no `x-oasis`, `x-ui`, or other shared namespace;
- no universal KPI, activity feed, timeline, mega-table, data grid, or mobile card system;
- no generic skeleton until a second real consumer exists;
- no generic tabs until navigation and local-state contracts are separated;
- no universal dropdown for notifications, account, export, and quick actions;
- no replacement for conflict, toast, sync, comments, notifications, or pickers;
- no new frontend dependency or icon library.

## Token Plan

Preserve existing `--oasis-*` names and add:

- complete page/flat/muted/raised/selected/disabled surfaces;
- disabled and neutral text/status colors;
- a documented 4px spacing scale from 4px through 64px;
- typography role variables and utility classes;
- subtle, standard, strong, and rare emphasis borders;
- none/small/medium radii;
- none/subtle/elevated shadows;
- fast/standard/slow motion and shared easing;
- minimum control height and one visible focus-ring treatment.

Only new primitives, the showcase, Dashboard, and shared shell are Phase 1 consumers. Legacy literals remain migration debt.

## Showcase Authorization

The internal showcase will use a named `viewDesignSystem` Gate backed by primary-role `User::isSuperadmin()`.

- Route: `GET /admin/design-system` inside the existing CRM lifecycle and Sales-protected group.
- Additional middleware: `can:viewDesignSystem`.
- Navigation: one Administration child from `NavigationService` using the same Gate.
- Denied: primary Sales, Pusat, normal roles, and users with only a supplemental superadmin role.
- No controller or operational query is needed; examples are static synthetic data.

## Risks

- Restyling the default `x-crm.page-header` would affect 28 module pages.
- Generic modal adoption must not alter conflict handling, draft preservation, or module form state.
- Existing global mobile selectors can inflate compact overlays and tables.
- Several tests assert source locations and need careful updates after extraction.
- Date/month pickers remain incompletely keyboard accessible.
- Repository-wide Pint has unrelated legacy failures; changed-file Pint is the acceptance check.

## Migration Boundaries

### Phase 1

- tokens;
- canonical CRM primitives;
- static superadmin showcase;
- Dashboard/shared-shell adoption;
- documentation, source contracts, authorization tests, and changelog.

### Later phases

- Dana Talangan filters, inline forms, and routed form convergence;
- Pengeluaran filter/form migration;
- Work Planner calendar, board, detail, and bulk interactions;
- Konsumen Progress stage presentation;
- picker accessibility and month-field extraction;
- pagination, bulk-action, and legacy table convergence.

### Database 2.0 completion

Database is the first completed operational migration. It consumes the Phase 1 page, toolbar, state, button, status, control, and modal primitives while retaining its dynamic tabs, metadata fields, wide table, skeleton, and sync-refresh behavior as Database-specific contracts. See `docs/DATABASE_2_AUDIT.md` and `docs/DESIGN_SYSTEM.md`.

No Phase 1 primitive changes route names, query parameters, authorization, data scope, table columns, exports, sync behavior, or operational business rules.

### Buku Saku Sales 2.0 completion

Buku Saku Sales is the second completed operational migration. It consumes canonical page, toolbar, field, action, status, state, modal, pagination, presence, and table primitives while retaining its role-aware Leads/Agenda/Laporan navigation, Sales cascade, daily reminder, lead stage controls, Sales Agenda actions, report metrics, and period picker as local domain contracts. It adds no search, chart, stage, metric, route, permission, or frontend dependency. See `docs/BUKU_SAKU_2_AUDIT.md` and `docs/DESIGN_SYSTEM.md`.
