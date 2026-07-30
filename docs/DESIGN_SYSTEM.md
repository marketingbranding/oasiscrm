# OASIS Design System 2.0

This document describes the implemented CRM design foundation. `AGENTS.md` remains the primary engineering standard, and executable code remains the final source of truth.

## 1. Philosophy

OASIS is a modern operational CRM with a restrained retro workstation identity.

1. Information before decoration.
2. Action before animation.
3. Hierarchy before density.
4. Consistency before variety.
5. Operational speed before visual effects.

The identity uses OASIS yellow, black structural accents, compact information density, square or minimally rounded geometry, and deliberate workstation references. It is not a generic SaaS dashboard, Windows parody, neobrutalism, cyberpunk UI, or a copy of another product.

## 2. Implementation Status

### Currently canonical

- token source: `resources/css/app.css`;
- component namespace: `resources/views/components/crm/`;
- shell and named page slots: `resources/views/layouts/crm.blade.php`;
- buttons, icon buttons, cards, sections, status badges, alerts, empty/loading states, toolbar, filter chip, field/error composition, and modal shell;
- canonical table classes: `.crm-table-scroll` and `.crm-data-table`;
- direct-click sorting: `x-crm.click-sort-th`;
- existing toast, sync, presence, comments, notifications, and conflict systems.

### Current specialized systems

- Dashboard KPI and timeline components remain under `x-dashboard.*`;
- Database 2.0 is the first full operational module migration and retains its dynamic table, tabs, fields, and sync-refresh orchestration locally;
- conflict handling remains `x-conflict-dialog`;
- comments remain `x-comments.panel`;
- sync remains `x-crm.sync-control` and `x-crm.sync-status-panel`;
- date and time behavior remains in the existing picker components and JavaScript.

### Future target

- month-field component;
- fully keyboard-accessible date/month pickers;
- URL-backed tab primitive;
- table state/action helpers;
- corrected pagination and bulk-action contracts;
- incremental migration of module dialogs, forms, headers, and toolbars.

### Legacy awaiting migration

- default legacy mode of `x-crm.page-header`;
- `x-crm.sortable-th` dropdown sorting;
- inaccessible page-local overlays and unsupported `x-trap` usage;
- page-local alert, empty, loading, field, button, and filter-chip markup;
- Breeze components outside the CRM visual contract.

## 3. Token Source

Do not create a second token file or redefine semantic colors in a module. Use CSS custom properties from `:root` in `resources/css/app.css`.

### Colors

| Token | Meaning |
|---|---|
| `--oasis-page-bg` | Application page background |
| `--oasis-surface` | Primary flat surface |
| `--oasis-surface-muted` | Passive/nested surface |
| `--oasis-surface-raised` | Raised card/dialog surface |
| `--oasis-surface-selected` | Selected navigation/data surface |
| `--oasis-surface-disabled` | Disabled control surface |
| `--oasis-text` | Primary text |
| `--oasis-text-muted` | Supporting text |
| `--oasis-text-disabled` | Disabled text |
| `--oasis-border-subtle` | Passive/nested border |
| `--oasis-border` | Standard neutral border |
| `--oasis-border-strong` | Important structural border |
| `--oasis-yellow` | OASIS brand yellow |
| `--oasis-focus` | Visible focus ring |
| `--oasis-success` | Successful/active/complete state |
| `--oasis-warning` | Warning/pending state |
| `--oasis-danger` | Error/destructive/critical state |
| `--oasis-info` | Informational/navigation state |
| `--oasis-neutral` | Neutral status |

Finite module accents use `--oasis-accent-*`. Module accents identify a workspace and never replace semantic danger, warning, success, or information.

## 4. Surface Hierarchy

Use this order:

1. page: `--oasis-surface-page`;
2. flat: `--oasis-surface-flat`;
3. muted: `--oasis-surface-muted`;
4. raised: `--oasis-surface-raised`;
5. selected/emphasis: `--oasis-surface-selected` or `--oasis-surface-emphasis`;
6. disabled: `--oasis-surface-disabled`.

Do not wrap every section in multiple bordered cards. A page section may remain borderless; a card is a bounded content container.

## 5. Spacing

Use the 4px rhythm:

| Token | Value |
|---|---:|
| `--oasis-space-1` | 4px |
| `--oasis-space-2` | 8px |
| `--oasis-space-3` | 12px |
| `--oasis-space-4` | 16px |
| `--oasis-space-5` | 20px |
| `--oasis-space-6` | 24px |
| `--oasis-space-8` | 32px |
| `--oasis-space-10` | 40px |
| `--oasis-space-12` | 48px |
| `--oasis-space-16` | 64px |

Do not mechanically replace every legacy arbitrary value. Adopt the scale when a view is deliberately migrated.

## 6. Typography

Font tokens:

- `--oasis-font-controls`: Helvetica/system sans for controls, navigation, labels, metadata, and table headers;
- `--oasis-font-data`: Times New Roman for deliberate data/editorial content;
- `--oasis-font-display`: Arial Black for major headings only;
- `--oasis-font-system`: monospace terminal/system accents.

Role classes include `.crm-type-display`, `.crm-type-page-title`, `.crm-type-section-title`, `.crm-type-card-title`, `.crm-type-body`, `.crm-type-compact`, `.crm-type-label`, `.crm-type-caption`, `.crm-type-button`, `.crm-type-table-header`, `.crm-type-table-data`, `.crm-type-badge`, and `.crm-type-system`.

Uppercase is for small labels, table headers, system accents, and concise headings, not body paragraphs.

## 7. Borders, Radius, Shadow, Motion, and Focus

- subtle border: passive/nested content;
- standard border: normal controls/cards;
- strong border: major table, dialog, selected navigation, or dominant action;
- emphasis border: rare workstation identity accent.

Use `--oasis-radius-none`, `--oasis-radius-sm`, or `--oasis-radius-md`. Pill shapes are reserved for semantically appropriate chips/badges.

Use `--oasis-shadow-none`, `--oasis-shadow-subtle`, or `--oasis-shadow-elevated`. Avoid decorative SaaS shadows.

Motion uses `--oasis-duration-fast`, `--oasis-duration-standard`, `--oasis-duration-slow`, and `--oasis-ease-standard`. New motion must respect `prefers-reduced-motion`.

Focus uses `--oasis-focus-width`, `--oasis-focus-offset`, and `--oasis-focus`. Never remove focus without an equivalent visible replacement.

## 8. Component Naming and Extraction

- Shared CRM primitive: `x-crm.*`.
- Complex domain owner: retain its domain namespace, for example `x-comments.panel`.
- Authentication/Breeze components may remain separate.
- Never create `x-oasis`, `x-ui`, or `x-app` as a competing CRM system.

Extract when a compatible pattern occurs approximately three times or has imminent, verified reuse. Do not extract one-off decorative markup.

## 9. Button API

```blade
<x-crm.button variant="primary" accent="database">Simpan</x-crm.button>
<x-crm.button variant="secondary" :href="route('dashboard')">Kembali</x-crm.button>
<x-crm.button variant="danger" type="submit">Hapus</x-crm.button>
<x-crm.button loading>Memproses</x-crm.button>
```

Props:

- `variant`: `primary`, `secondary`, `ghost`, `text`, `danger`;
- `size`: `sm`, `md`, `lg`;
- `href`: renders an anchor when present;
- `type`: native button type;
- `disabled`, `loading`;
- `accent`: one finite module accent, meaningful only for primary actions.

Links remain links; actions remain buttons. Authorization stays at the caller. Use one dominant primary action per region.

Icon-only controls use `x-crm.icon-button` and require `label`.

## 10. Status Badge API

```blade
<x-crm.status-badge variant="success">Aktif</x-crm.status-badge>
<x-crm.status-badge variant="warning">Perlu Review</x-crm.status-badge>
```

Variants: `neutral`, `info`, `pending`, `processing`, `success`, `warning`, `danger`, `inactive`, `archived`.

The caller maps business status to an explicit semantic variant. The component never guesses from a status string. Text is mandatory; color is supplementary.

## 11. Card and Section API

`x-crm.card` is bounded content. Variants: `default`, `muted`, `raised`, `emphasis`. Padding: `none`, `sm`, `md`, `lg`. Optional named slots: `header`, `footer`.

`x-crm.section` is a page-level grouping with required `id` and `title`, optional `eyebrow`, `description`, and `actions`. It provides a heading relationship and does not add a decorative border.

## 12. Alerts and Content States

`x-crm.alert` variants are `info`, `success`, `warning`, and `error`. Use field errors for validation, the conflict dialog for HTTP 409, sync controls for synchronization, and toast only for transient outcomes.

`x-crm.empty-state` contains a clear title, short description, and optional action slot.

`x-crm.loading-state` is an inline/page-region loading indicator. It is not a sync replacement and must not block a page when work can continue.

A generic skeleton is not canonical yet. Database retains its local skeleton until another compatible consumer exists.

## 13. Toolbar and Filters

```blade
<x-crm.toolbar label="Daftar data">
    {{-- visible search and up to two simple filters --}}
    <x-slot:actions>
        {{-- filter/export/create controls --}}
    </x-slot:actions>
</x-crm.toolbar>
```

`x-crm.toolbar` owns layout only. The caller owns forms, query names, hidden state, permissions, filter behavior, and routes.

`x-crm.filter-chip` renders a readable active filter and optional removal link. The caller constructs the reset URL and preserves required query context.

## 14. Form Composition

```blade
<x-crm.field label="Nama" for="name" required hint="Nama lengkap" :error="$errors->first('name')">
    <input id="name" name="name" value="{{ old('name') }}"
           class="crm-control"
           aria-describedby="name-hint name-error">
</x-crm.field>
```

The field component owns visible label, required marker, hint, and inline error placement. The caller owns native input semantics, old input, `aria-describedby`, validation attributes, and FormRequest behavior.

Continue using the existing date/time picker architecture. No new picker dependency is permitted.

## 15. Modal Accessibility Contract

`x-crm.modal` supports:

- `name`, `title`, optional `description`;
- sizes `sm`, `md`, `lg`, `xl`;
- body slot and optional `footer` slot;
- `role="dialog"`, `aria-modal`, labelled title, optional description;
- initial focus through `[data-autofocus]` or the close control;
- Tab/Shift+Tab trap;
- Escape and backdrop close;
- body-scroll lock and trigger focus restoration;
- viewport-safe body scrolling and reduced motion.

Open it locally:

```blade
<x-crm.button
    @click="$dispatch('oasis:modal-open', { name: 'example', trigger: $el })">
    Buka
</x-crm.button>
```

Do not replace `x-conflict-dialog` with this modal. Operational modal migrations require workflow-specific tests.

## 16. Page Contract

Prefer the named sections in `layouts.crm`: `breadcrumbs`, `page-title`, `page-description`, `page-actions`, `page-tabs`, `toolbar`, and `content`.

`x-crm.page-header` now supports `variant="canonical"`, but its default `legacy` mode remains for compatibility. Do not bulk-switch 28 consumers without module-level review.

## 17. Table Conventions

- use `.crm-table-scroll` and `.crm-data-table`;
- keep separated borders and zero spacing;
- preserve sticky headers and frozen identity columns where useful;
- use `x-crm.click-sort-th` for new server sorting;
- keep important data visible and allow table-local horizontal scrolling;
- keep loading, empty, and error states inside the table region;
- use textual Edit/Hapus action links according to `AGENTS.md`;
- do not create a universal data-grid component.

## 18. Toast, Sync, Conflict, Notifications, and Presence

- transient outcome: existing toast stack, `window.oasisToast`, or `oasis:toast`;
- synchronization: existing sync control/status panel;
- optimistic conflict: existing conflict dialog;
- collaboration notifications: existing topbar notification system;
- presence: existing page/record presence component.

These systems have distinct lifecycles and must not be merged.

## 19. Database Operational Workspace

Database 2.0 is the first full operational consumer of the canonical CRM foundation.

Consumed components:

- `x-crm.page-header`, `x-crm.toolbar`, buttons, status badges, alerts, empty/loading states, sections, filter chips, and modal;
- existing `x-crm.sync-control`, `x-crm.sync-status-panel`, and `x-crm.page-presence`;
- canonical `.crm-table-scroll`, `.crm-data-table`, `.crm-control`, and table action conventions.

Backward-compatible extensions:

- `x-crm.filter-chip` accepts caller-owned dynamic content and a remove-control slot;
- `x-crm.modal` emits opened/closed lifecycle events with a close reason and lets an open date/month/time picker consume Escape before the containing modal closes;
- the shared conflict submission system emits a non-conflict form-error event so the Database edit modal can retain and display the draft after HTTP 422 or network failure.

Database-specific contracts remain local:

- lazy Google sheet tabs and counts;
- dynamic columns and metadata-driven add/edit fields;
- client-side substring search and three-state sorting;
- frozen row number and normalized `id_kavling` column;
- formula-column presentation and edit exclusion;
- table skeleton, active-sheet refresh, scroll restoration, and sync-draft warning;
- template-row requirement for adding records.

Table and responsive decisions:

- the wide table remains complete and scrolls only inside its table wrapper;
- separated borders, sticky headers, and frozen identity cells remain required;
- sorting is a keyboard-operable button and communicates `aria-sort`;
- sheet tabs support arrow, Home, and End keys;
- mobile keeps the full table workflow, stacks dynamic form fields, wraps toolbars, and uses the canonical viewport-safe modal;
- no desktop/mobile record duplication is rendered.

Database 2.0 does not add project filters, domain filters, pagination, export/import, bulk actions, a detail route, or comments. Date/month calendar-grid keyboard behavior and the global legacy mobile control override remain system-level gaps.

## 20. Buku Saku Sales Operational Workspace

Buku Saku Sales 2.0 is the second full operational consumer of the canonical CRM foundation.

Consumed components:

- `x-crm.page-header`, toolbar, buttons, cards, sections, status badges, alerts, empty states, fields, filter chips, modal, pagination, and page presence;
- existing date/time pickers, toast, optimistic conflict, comments, mentions, and presence systems;
- canonical table classes and direct-click sorting for the monitoring report.

Backward-compatible extensions:

- `x-crm.click-sort-th` accepts the caller's direction parameter and named paginator keys to reset while retaining legacy defaults;
- `x-crm.pagination` can hide the page-size selector when the backend has no `per_page` contract and can strip an unrelated named paginator key.

Buku-Saku-specific contracts remain local:

- URL-backed Leads, Agenda, and Laporan views with compatible query-state transitions;
- primary-Sales personal hierarchy versus organization monitoring hierarchy;
- branch/project/Sales cascade and weekly/custom period popover;
- authoritative daily reminder presentation and dismissal/navigation lifecycle;
- responsive lead operational records, exact stage controls, and duplicate-phone advisory flow;
- Sales Agenda statuses, result/reschedule actions, and linkage context;
- event-based metric blocks, monitoring columns, drilldowns, and export scope.

Responsive and accessibility decisions:

- each lead and its edit payload render once; CSS rearranges the same record for desktop and mobile;
- tabs scroll horizontally, forms collapse to one column, standalone controls target 44px, and report tables scroll only inside their wrapper;
- specialized lead/stage dialogs retain their verified conflict lifecycle while adding body lock, focus containment/restoration, validation announcement, and viewport-safe scrolling;
- no fake search is shown because there is no backend search over paginated leads;
- no chart, Kanban, stage, probability, metric, route, permission, query, or frontend dependency was added.

See `docs/BUKU_SAKU_2_AUDIT.md` for role/scope, reminder, report, query-state, performance, and remaining-gap contracts.

## 21. Work Planner Operational Workspace

Work Planner 2.0 is the third completed operational migration. It consumes the canonical page, toolbar, button, card, field, alert, status, state, modal, presence, and table foundations while retaining its URL-backed Hari Ini/Kalender/Tugas/Agenda/Konten/Semua views, ContentItem model, route names, policies, module-aware branch authorization, item type/status semantics, assignment behavior, import/export routes, comments, presence, and optimistic conflict handling.

Work-Planner-specific contracts remain local:

- Today grouping for overdue, today's tasks, today's agenda, today's content, and tomorrow preview;
- server-built month calendar grid and day/detail overlays;
- task, agenda, and content status workspaces with existing Sortable status update behavior;
- mixed All view table with the existing 20-row paginator;
- dynamic task/agenda/content routed form behavior;
- legacy synchronous XLSX import/export mapping.

Responsive and accessibility decisions:

- the six views remain URL-backed links with current-page semantics;
- board and calendar horizontal overflow stays local to the workspace region;
- selection controls have accessible names and bulk affordances are permission-aware;
- detail/day overlays now expose dialog roles and labelled close controls, but they are not full `x-crm.modal` consumers because they are driven by existing item/day JSON state;
- Sortable drag/drop still has no complete keyboard equivalent, and shared date/month picker keyboard gaps remain system-level limitations.

See `docs/WORK_PLANNER_2_AUDIT.md` for route, authorization, query-state, import/export, performance, and remaining-gap contracts.

## 22. Responsive Behavior

- mobile touch targets: at least 44px;
- toolbars wrap; forms remain completable;
- dialogs stay within `100dvh` and scroll internally;
- page headers/actions stack when space is insufficient;
- tables scroll inside their wrapper, not at page level;
- no hover-only critical controls;
- fixed controls must not cover content or browser safe areas.

Source contracts are not browser verification. Manually check approximately 360, 390, 430, 768, 1024, 1366, and 1440px when a browser is available.

## 23. Accessibility

- semantic link/button/form behavior;
- correctly associated visible labels;
- accessible names for icon-only controls;
- visible focus;
- status text in addition to color;
- Escape, focus trap, initial focus, and restoration for modal overlays;
- reduced-motion support;
- sufficient contrast;
- no raw untrusted HTML.

Known gaps remain in legacy dialogs, date/month keyboard behavior, some dropdown menu navigation, and the broad legacy mobile control override.

## 24. Correct Usage

- use a primary button for the one dominant action;
- use secondary/ghost/text for supporting actions;
- pass `danger` only for destructive actions;
- use a status badge with explicit semantic mapping;
- use a section for page hierarchy and a card only for bounded content;
- keep permissions and routes in the caller;
- keep business filters and query preservation in the module;
- use static synthetic data in the internal showcase.

## 25. Anti-Patterns

- parallel component namespaces;
- arbitrary status-to-color inference inside a visual component;
- route or permission logic inside a primitive;
- nested card-on-card decoration;
- module accent for danger;
- page-blocking loaders for inline work;
- modal without focus lifecycle;
- `x-trap` without Alpine Focus;
- replacing tables globally with generic cards;
- new UI, picker, icon, chart, or notification dependencies;
- claiming a future primitive is already canonical.

## 26. Migration Guide

1. Audit the module route, permissions, policies, scopes, query parameters, and tests.
2. Identify visual-only duplication; do not change backend behavior during migration.
3. Move the page to named shell sections where safe.
4. Replace buttons, badges, alerts, states, fields, and toolbars incrementally.
5. Keep module-specific tables/forms/workflows local.
6. Add rendered markup and source-contract tests.
7. Verify search/filter/sort/pagination/export state.
8. Verify desktop, tablet, mobile, keyboard, empty, loading, error, and conflict states.
9. Add a changelog migration for user-visible behavior.

See `docs/DESIGN_SYSTEM_AUDIT.md` for the module migration map and deferred patterns.

## 27. Future Component Checklist

- Is the pattern repeated or imminently reused?
- Does an existing `x-crm.*` component already match?
- Is the API small and composable?
- Are authorization and routes caller-owned?
- Are native semantics preserved?
- Are loading, disabled, empty, and error states defined?
- Is keyboard operation complete?
- Are focus and reduced-motion behavior covered?
- Does mobile remain usable at 360px?
- Are tests based on behavior/semantic markup rather than only classes?
- Is current versus target status documented honestly?
