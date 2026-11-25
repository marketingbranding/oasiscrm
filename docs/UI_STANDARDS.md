# OASIS UI Standards

Dipindahkan utuh dari `AGENTS.md` lama (bagian 10–14) agar file aturan agent tetap ringkas. `AGENTS.md` merujuk ke dokumen ini untuk semua pekerjaan UI.

## 10. OASIS UI Direction

**Target direction:** Modern operational CRM with a restrained retro workstation identity.

OASIS is not being redesigned into a generic rounded SaaS product. The retro identity is an accent system, not a requirement that every element look old.

Preserve:

- OASIS yellow;
- black as a strong structural accent;
- square or minimally rounded geometry;
- compact enterprise density;
- intentionally established Helvetica and Times typography;
- clear grids and workstation references;
- recognizable module accents.

Modernize:

- information hierarchy and progressive disclosure;
- spacing and grouping;
- navigation clarity;
- readable content density;
- responsive behavior;
- action hierarchy;
- accessibility;
- loading, empty, error, unavailable, and conflict states.

Avoid:

- glassmorphism;
- large rounded SaaS cards;
- excessive gradients or shadows;
- neon cyberpunk styling;
- decorative pixel art on every page;
- heavy black borders around every minor element simultaneously;
- displaying every module color together;
- literal copies of Lark, Notion, Linear, Salesforce, or another product.

### Current visual implementation

Current CRM pages extend `resources/views/layouts/crm.blade.php`. The layout contains the topbar, sidebar, notification dropdown, toast stack, conflict dialog, feedback bubble, AI widget where allowed, and footer.

Current module accents include:

| Module | Current accent |
|---|---|
| Dashboard page | `#8c9ae0` (sidebar currently uses `#9ab6c8`) |
| Database | `#d77a7a` |
| Work Planner | `#b3bd95` |
| Work Planner task / agenda / content | `#9ab6c8` / `#e6915d` / `#8c9ae0` |
| Buku Saku Sales | `#fcc20f` |
| Dana Talangan | `#f1c40f` |
| Pengeluaran | `#b3bd95` |
| User Administration | `#8c9ae0` |
| Danger | `#c0392b` |

The foundational token layer now exists in `resources/css/app.css`. It defines page and surface hierarchy, primary/muted/disabled text, border levels, OASIS yellow, semantic states, focus, a 4px spacing scale, typography roles, restrained radius/shadow/motion, shell dimensions, and finite module accents.

Database 2.0 is the first completed operational Design System migration. It uses canonical page, toolbar, state, status, button, control, sync, presence, and modal primitives while retaining its lazy sheet tabs, dynamic metadata fields, wide table, frozen identity columns, skeleton, and sync-refresh orchestration as Database-specific behavior. It still has no project filter, domain filter, pagination, export/import, bulk action, detail route, or comments integration.

Buku Saku Sales 2.0 is the second completed operational migration. It uses canonical page, toolbar, field, action, status, state, modal, pagination, presence, and table primitives while retaining URL-backed Leads/Agenda/Laporan views, primary-Sales versus monitoring hierarchy, daily reminder behavior, lead stages, Sales Agenda subtype/actions, weekly/custom metrics, drilldowns, and exports. Leads use one responsive record DOM; there is no backend search, chart, Kanban, new stage, or client-side report calculation.

Buku Saku Sales 2.1 adds branch-workbook lead writes, canonical lifecycle status/history, site-visit/consumer-or-NUP/SLIK/freelance operations, pull-only Akad reconciliation, sync controls, lifecycle modals/details, and lifecycle export fields. It preserves the six legacy stage fields and does not add a rendered reconciliation-management screen; that destination is list-only JSON.

Work Planner 2.0 is the third completed operational migration. It uses canonical page, toolbar, field, action, status, state, presence, and table primitives while retaining URL-backed Hari Ini/Kalender/Tugas/Agenda/Konten/Semua views, Today grouping, server-built calendar grid, task/agenda/content status workspaces, routed create/edit/import pages, detail JSON, Sortable status updates, comments, mentions, presence, optimistic conflicts, bulk endpoints, and XLSX import/export mappings. It adds no new item type, status, assignment rule, project relationship, calendar dependency, route, permission, or import/export architecture.

Work Planner 2.1 adds only focused local improvements: compact Calendar density, a read-only URL-backed Gantt tracking view, and reactive Sortable empty-state/count synchronization. It does not add routes, permissions, statuses, date mutation, Gantt editing, dependencies, or new business calculations.

Adoption remains incremental elsewhere. Many operational Blade views still repeat arbitrary colors and legacy utility combinations; do not claim those modules are already migrated. Module accents identify modules and never override semantic error/warning/success meaning. Use `docs/DESIGN_SYSTEM.md` for implemented APIs and `docs/DESIGN_SYSTEM_AUDIT.md` for the migration map.

## 11. Navigation Architecture

### Current

`NavigationService` builds server-rendered, permission-aware groups for Dashboard, Aktivitas, Sales, Operasional, Keuangan, Laporan, and Administrasi. Empty groups are omitted. `layouts.crm` renders this definition through shared navigation icons and shell components.

Desktop supports explicit expanded and collapsed states; navigation never depends on hover expansion. Mobile uses a labelled modal drawer. Shell preferences use `oasis.sidebar.collapsed` for the desktop width state and `oasis.sidebar.groups` for expanded group state. These keys are local-only UI preferences and must remain backward-compatible when shell behavior changes.

Do not describe the following grouping as already implemented.

### Target direction

Prefer 5-7 top-level groups:

| Group | Intended contents |
|---|---|
| Dashboard | Personal or organization overview |
| Aktivitas | Work Planner, agenda, notifications, activity feed where implemented |
| Sales | Buku Saku Sales, Database, Konsumen Progress, related sales workflows |
| Operasional | Dana Talangan and future project/consumer operations |
| Keuangan | Pengeluaran, contextual category management, related finance workflows |
| Laporan | Monitoring, review reports, exports, cross-module reports |
| Administrasi | Users, branches, projects, roles/permissions, system health, Changelog |

Rules:

- Never expose every route as a top-level link.
- Do not show an empty group.
- Configuration pages are contextual children, not peers of daily operational modules.
- Active group and active child must be clear.
- Do not add a top-level link without documenting why it is frequent, independent, and relevant to a meaningful user group.
- Build future navigation from centralized definitions plus permission/scope checks; do not duplicate an entire sidebar per role.
- Profile/logout belongs at the bottom or account menu. Changelog/System Health belongs under administration/system utilities.
- Persisted navigation state must not cause a layout flash or hide content before Alpine initializes.
- Do not duplicate the same destination in topbar and sidebar without a documented usability reason.

### Role principles

- Sales: Buku Saku, Work Planner, notifications, profile, comments, and explicitly allowed technical routes.
- Coordinator/Supervisor: personal work, team sales/planner, authorized monitoring.
- Manager/Branch Manager: assigned branch/project operations, reports, and team functions according to permissions.
- Pusat: explicit cross-branch operational permissions, not automatic system administration.
- Superadmin: all registered operational and system permissions.

### Information architecture decisions

A feature belongs in global navigation only when it is frequent, represents a major business domain, has an independent workflow, and is relevant to a meaningful authorized user group.

Use contextual/submenu navigation when the feature configures a parent module, is a specialized view of the same records, or is used infrequently. Use a page action when it creates or changes data in the current module but is not a separate destination. Put user, organization, permission, category, system, and infrastructure configuration under Administrasi.

### Sidebar standards

Desktop:

- support explicit collapsed/expanded control;
- icons in collapsed mode, labels in expanded mode;
- preserve accessible `title`/`aria-label` for collapsed items;
- controlled nested group expansion;
- active group may remain expanded;
- sidebar scrolls independently;
- important navigation cannot depend only on hover;
- pin/collapse is keyboard-operable.

Mobile:

- use a full or near-full-width labeled off-canvas drawer;
- default closed;
- backdrop and Escape close;
- destination selection closes the drawer;
- control body scroll while open;
- minimum 44px touch targets;
- never reuse desktop icon-only collapsed mode as the mobile experience.

### Topbar

The topbar owns global UI only:

- mobile navigation trigger;
- OASIS identity;
- future global search/command trigger;
- future controlled quick-create;
- existing notifications;
- account/profile access.

Module actions belong in the page header or module toolbar. Reuse the existing notification system; do not create module-specific notification centers.

## 12. Page and Component Standards

### Page shell

Major pages should use:

1. optional breadcrumb;
2. one page title;
3. concise description where useful;
4. one dominant contextual action;
5. tabs for stable views of the same module;
6. search/filter/action toolbar;
7. main content;
8. inline loading/empty/error state.

Do not repeat the same action in header and toolbar. Filters are not page-header actions. Tabs represent stable views, not submitted filters.

### Button hierarchy

- Primary: one dominant action per context, using the module accent or OASIS yellow when semantically appropriate.
- Secondary: neutral surface and strong border for supporting actions.
- Tertiary: low-weight text/link action.
- Danger: red destructive action with explicit label and confirmation.

Do not give every button a distinct color, use module accents for destructive actions, or style a primary action as an underlined table link. Icon-only controls need accessible labels and tooltips.

### Cards, sections, and KPIs

Cards group related information; they are not decoration around every value. Avoid nested card-on-card layouts and black title bars on every minor section.

A KPI block should contain a concise label, primary value, optional comparison/context, and an optional textual status indicator. Do not rely on color alone or use oversized KPI typography in compact operational views.

### Dashboard

A dashboard should answer:

1. what happened;
2. what needs action;
3. how performance is progressing.

Prefer primary KPIs, action-required queue, operational progress, recent activity, and relevant sync/system health. Do not display every available metric or give every metric equal weight. Use warning/danger colors only for genuine state and urgency.

Dashboard invariants:

- `$selectedBranchId` is initialized and passed to the view; this is covered behavior, not an unresolved bug.
- Superadmin with no selected branch aggregates appropriate data but does not receive a branch Database sync action.
- Normal users resolve a primary/accessible branch rather than silently receiving global scope.
- Dashboard sync posts to `database.sync`.

### Actual reusable Blade components

CRM anonymous components under `resources/views/components/crm/`:

- `add-button`
- `alert`
- `bulk-bar`
- `button`
- `card`
- `click-sort-th`
- `date-field`
- `detail-modal`
- `empty-state`
- `export-import`
- `field`
- `feedback-bubble`
- `filter-chip`
- `icon-button`
- `input-error`
- `loading-state`
- `modal`
- `notif-section`
- `page-header`
- `page-presence`
- `pagination`
- `account-menu`
- `nav-icon`
- `notification-menu`
- `sortable-th`
- `section`
- `status-badge`
- `sync-control`
- `sync-status-panel`
- `time-field`
- `toolbar`
- `topbar`

Other shared components:

- `resources/views/components/comments/panel.blade.php`
- `resources/views/components/conflict-dialog.blade.php`
- Breeze/generic components under `resources/views/components/` for auth/non-CRM layouts.
- Dashboard-specific KPI, section, quick-action, and timeline components under `resources/views/components/dashboard/`; these are not generic CRM primitives.

Current JavaScript modules registered in `resources/js/app.js` include pickers, custom select, bulk actions, presence, conflict, notifications, sync, toast, sales reminder, and comments.

Rules:

- Extend an existing component when its behavior matches.
- Create a reusable component after the same pattern appears in roughly three places.
- Do not create premature abstraction for one isolated view.
- Do not copy large markup blocks across modules.
- Do not assume a component is accessible merely because it exists; audit its current behavior.

### Target reusable primitives

The repository does not yet have canonical components for every pattern. Remaining future standard candidates include:

- navigation group/item;
- advanced-filter modal;
- month field;
- KPI block;
- canonical action cell.

Label these components as target direction until implemented. The behavior standards elsewhere in this document still apply to new pages even when no shared component exists; use an existing strong module pattern and extract a component only when reuse justifies it.

## 13. Forms, Tables, Search, and Feedback

### Forms and pickers

- Use server-side FormRequest validation and inline field errors.
- Labels remain visible; placeholders do not replace labels.
- Mark required fields clearly.
- Group long forms into logical sections.
- Destructive changes require confirmation.
- Primary/save and cancel actions must be visually clear.

Date fields:

- Use `.date-wrapper`, `.date-display`, `.date-text`, `.date-arrow`, and a visually hidden native date input.
- Behavior lives in `resources/js/crm-datepicker.js`.
- Prefer `x-crm.date-field` when it fits.
- Do not add another calendar library or duplicate picker logic in a view.

Month fields:

- Use `.month-wrapper`, `.month-display`, `.month-text`, `.month-arrow`, and a hidden month input.
- Behavior lives in `resources/js/crm-monthpicker.js`.
- No shared month Blade component exists yet.

Time fields:

- Use `x-crm.time-field` and `resources/js/crm-timepicker.js`.
- Preserve exact minute selection, keyboard operation, `Sekarang`, explicit confirmation, dynamic initialization, and viewport positioning.

Pickers share `resources/js/crm-picker-position.js`. Extend existing modules for dynamic behavior; do not create page-local calendars.

Current accessibility caveat: date/month pickers are not yet fully keyboard/ARIA complete. Treat better keyboard semantics and focus management as target direction, not existing capability.

### Tables

Use `.crm-table-scroll` and `.crm-data-table` from `resources/css/app.css`. Database is the canonical current reference.

Required canonical behavior:

- separated-border model, zero spacing, single-sided 2px cell borders;
- sticky black uppercase headers;
- compact Helvetica headers and Times data;
- zebra rows and yellow hover;
- horizontal scrolling;
- correct paginated row numbers;
- direct-click sorting with active indicator;
- frozen identity columns where useful;
- `.crm-boolean-box` for booleans;
- truncation with full-value `title`;
- predictable final action column.

Do not switch canonical tables to collapsed borders; sticky/frozen borders disappear in Chromium. Do not hide important desktop columns merely to avoid mobile scrolling. For critical mobile workflows, add a deliberate compact summary/detail alternative rather than weakening the desktop table.

Actions:

- Edit: blue `#0000ee`, bold, underlined.
- Hapus/destructive: red `#c0392b`, bold, underlined, confirmation required.
- Generate URLs with Laravel routes; never hardcode application paths.
- Bulk actions show selected count and remain permission-aware.
- Loading/empty/failure states appear inside the table region.

`x-crm.click-sort-th` follows direct-click sorting. `x-crm.sortable-th` still uses a dropdown and is legacy; do not copy it into new tables.

`title` may supplement truncated text for pointer users, but it is not the only accessible disclosure. Important full values must also be available through visible detail, an accessible label, or a keyboard/touch-accessible control.

### Search, filters, tabs, actions

Search:

- Keep meaningful search always visible.
- Search is separate from domain filters.

Filters:

- At most two simple inline filters.
- More than two domain filters use one advanced-filter modal.
- Show active filter count and user-readable chips.
- Provide `Hapus semua filter`.
- Preserve search when applying/resetting filters.
- Preserve active search/filter state through sorting, pagination, and export.
- Branch change clears an incompatible project.
- All-branch project labels include branch context; do not deduplicate distinct project IDs by name.

Dana Talangan and Pengeluaran are current strong toolbar references. Not every existing module has migrated to this standard.

Tabs:

- Tabs represent stable module views.
- Do not inject hidden domain filters when changing tabs.
- Tabs remain usable on mobile through scrolling/compact treatment.
- Use a dedicated validated return parameter such as `return_view`; do not reuse form data as redirect filters.

Actions:

- Keep list actions in the same bordered toolbar as search/filter where practical.
- One dominant primary action uses the current module accent.
- Export/import and other secondary actions use neutral surfaces.
- Danger actions use red, never module accent.
- Do not give every button a unique color.
- Icon-only controls require accessible labels and tooltips.

### Borders, surfaces, spacing, typography

Use 2px black borders for major tables, primary workstation panels, dialogs, selected navigation, and important action controls. Use lighter 1px neutral borders for passive/nested/secondary containers.

Do not put a heavy black rectangle around every KPI, label, button, and nested section simultaneously. Establish hierarchy with spacing, typography, grouping, and restrained surfaces.

Use a 4px spacing rhythm:

- related controls: 4-8px;
- toolbar groups: 8-12px;
- section padding: 12-20px;
- major section gaps: 20-32px;
- mobile may increase spacing for touch usability.

Typography roles:

- Helvetica/system sans: navigation, controls, labels, headers, tables;
- Times New Roman: intentional OASIS data/editorial accent;
- Arial Black: currently used for major CRM headings;
- uppercase: small labels, table headers, system sections, not body paragraphs.

Avoid arbitrary font mixing within one control.

### Status colors

- Red: error, destructive, overdue/critical.
- Yellow: warning, pending, OASIS brand accent when not confused with warning.
- Green: success, active, complete.
- Blue: information or navigation selection.
- Gray: neutral, inactive, secondary.

Every status also needs text/icon/label. Never rely on color alone. Module accents do not override semantic states.

### Feedback

- Transient CRUD outcomes use the shared toast stack in `layouts.crm`.
- Server redirects flash `success`, `warning`, or `error`.
- AJAX uses `window.oasisToast(...)` or the `oasis:toast` event.
- Field validation, duplicate warnings, assignment guidance, confirmations, empty states, and presence remain inline.
- HTTP 409 uses the shared `oasis-conflict` dialog, not a toast.
- Do not expose secrets or hidden record details in feedback.
- Distinguish no data from temporary integration failure.
- Do not create a page-specific toast/banner system when a shared primitive applies.

Required state coverage where relevant:

- loading;
- empty;
- success;
- warning;
- error;
- denied;
- integration unavailable;
- stale/conflict;
- offline/network failure.

## 14. Responsive and Accessibility

Accessibility is mandatory:

- keyboard-operable controls;
- visible focus states;
- semantic links/buttons;
- bound labels for inputs;
- `aria-label` for icon-only controls;
- Escape close for modal/drawer/dropdown;
- outside click is never the only close method;
- `x-cloak` for Alpine overlays;
- status not conveyed only by color;
- sufficient contrast;
- reduced-motion compatibility when animation is added;
- no hover-only critical content.

Every new modal dialog/drawer must provide:

- `role="dialog"` where appropriate;
- `aria-modal` for modal overlays;
- labelled heading relationship;
- initial focus;
- focus trap;
- focus restoration;
- viewport-safe scrolling.

Current caveat: some legacy/filter/detail dialogs and picker controls do not yet meet this complete standard. Improve them when in scope; do not claim they already do.

Use `x-crm.modal` for new generic CRM dialogs. Existing Sales, Comments, and conflict dialogs retain their verified specialized behavior until migrated deliberately; do not copy an inaccessible legacy modal. Native `confirm()` may remain in untouched legacy workflows, but new multi-step or sensitive workflows should use the canonical modal or a justified specialized dialog.

### Responsive requirements

Desktop:

- compact operational workspace;
- sidebar/main may scroll independently;
- wide tables scroll horizontally.

Tablet:

- navigation never obscures content;
- toolbars may wrap;
- filter/dialog flows remain usable.

Mobile:

- full labeled off-canvas navigation;
- intentional one-column sections, not blind desktop stacking;
- minimum 44px touch targets;
- primary actions reachable without horizontal scrolling;
- secondary actions may enter an overflow menu;
- forms, status summaries, and navigation never require horizontal scrolling;
- tables may scroll horizontally;
- modal content scrolls within viewport;
- sticky action bars cannot cover content/browser controls;
- no hover dependency;
- users must complete the workflow, not only view it.

Check approximately 360px, 390px, 430px, 1024px, and 1366px when browser verification is available.

Motion target:

- 100-200ms for dropdown/drawer/state transitions;
- no decorative bounce or motion that delays work;
- polling must not cause layout jumps;
- respect reduced motion.

### Iconography

- Reuse the existing inline SVG approach until a shared icon component is introduced.
- Do not mix random icon libraries.
- Icons support labels; they do not replace unclear concepts.
- Avoid emoji as permanent UI icons unless intentionally used as a retro/system indicator.
- A new icon dependency requires explicit justification.

