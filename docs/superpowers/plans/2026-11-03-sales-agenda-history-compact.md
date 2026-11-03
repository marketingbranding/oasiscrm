# Sales Agenda History Compact Layout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the spacious “Semua agenda” cards with compact agenda rows that are easier to scan on mobile while preserving detail access, pagination, scope, and workflow behavior.

**Architecture:** Keep the existing `agenda-history.blade.php` partial and backend data contract. Replace only its large facts-grid presentation with a compact row structure using a date eyebrow, time column, title/category content, status badge, and detail link. Add route-scoped CSS in `resources/css/app.css`; do not reuse receipt markup directly because the history view spans multiple dates and has pagination.

**Tech Stack:** Laravel Blade, CSS in `resources/css/app.css`, existing CRM status badge/pagination components, PHPUnit feature/source-contract tests, Vite.

**Spec:** `docs/superpowers/specs/2026-11-03-sales-agenda-history-compact-design.md`

## Global Constraints

- Do not change routes, query/backend data, ordering, pagination, authorization, or status workflow.
- Keep project and location information available on the agenda detail page.
- Preserve semantic status text, keyboard-accessible links, and minimum 44px touch targets for the detail action.
- Use existing OASIS typography, tokens, square geometry, and spacing scale; do not add dependencies or a second component architecture.
- Add one idempotent Indonesian Oasis Changelog migration for the user-visible layout change.

## Review Focus

- All-view owner and branch scope must remain unchanged while markup is replaced; pin with the existing owner-scope test plus the new compact-row render test.
- Cross-date ordering and pagination links must remain intact; pin with the existing pagination test.
- Long titles/categories must wrap without horizontal overflow; pin with CSS/source assertions and browser mobile verification.
- Status labels and detail return parameters must remain present; pin with rendered assertions for status and `return_view=all`/`return_work_date`.
- The empty state and day view must not receive the all-view compact styles accidentally; pin with all-view-only selectors and existing day-view tests.

---

### Task 1: Add failing regression coverage for compact history rows

**Files:**
- Modify: `tests/Feature/SalesAgendaWorkspaceRenderTest.php`

**Interfaces:**
- Consumes: Existing `sales-agendas.index` route with `view=all`, `salesContext()`, and `agendaAt()` helpers.
- Produces: Render contract for compact history rows without the old facts grid.

- [ ] **Step 1: Add a failing all-view compact markup test**

  Add `test_all_agenda_view_uses_compact_rows_for_mobile_scanning()` that creates an agenda, requests the all view, and asserts the response contains the compact history row class, date/time/category/status/detail link, and `return_view=all`. Assert it does not contain `sales-agenda-history-facts`.

- [ ] **Step 2: Run the focused test to verify RED**

  ```bash
  php artisan test tests/Feature/SalesAgendaWorkspaceRenderTest.php --filter=all_agenda_view_uses_compact_rows_for_mobile_scanning --compact
  ```

  Expected: FAIL because the current view still renders `sales-agenda-history-item` with `sales-agenda-history-facts`.

### Task 2: Implement compact history markup and responsive styling

**Files:**
- Modify: `resources/views/crm/sales-pocketbook/partials/agenda-history.blade.php`
- Modify: `resources/css/app.css`

**Interfaces:**
- Consumes: The compact row class names and rendered fields defined by Task 1.
- Produces: A compact, keyboard-accessible all-agenda list with no backend changes.

- [ ] **Step 1: Replace the facts-grid markup with a compact row**

  Keep the existing status map, pagination, empty state, detail route parameters, and agenda loop. Render each item with a date/category eyebrow, a schedule block, title, category label, status badge, and a visible `Buka detail agenda` link. Do not render the four-cell `sales-agenda-history-facts` grid.

- [ ] **Step 2: Add all-view-scoped compact CSS**

  Define the compact row layout using existing sales reference tokens. On mobile use a single-column row with tight spacing, wrapped title text, and a detail link with at least 44px effective touch height. On desktop use a compact grid for time/content/status without forcing horizontal scrolling. Keep the header, empty state, and pagination styling intact.

- [ ] **Step 3: Run focused render tests to verify GREEN**

  ```bash
  php artisan test tests/Feature/SalesAgendaWorkspaceRenderTest.php --filter='all_agenda_view|agenda_detail_back_link|agenda_history_paginates' --compact
  ```

  Expected: all selected tests pass, including scope, return context, pagination, and compact markup.

### Task 3: Record the user-visible change and validate the frontend bundle

**Files:**
- Create: `database/migrations/2026_11_03_140000_add_sales_agenda_history_compact_changelog.php`
- Modify: `tests/Feature/SalesAgendaWorkspaceRenderTest.php`
- Modify: `public/build/manifest.json` and generated `public/build/assets/*` via Vite.

**Interfaces:**
- Consumes: Completed compact history layout from Task 2.
- Produces: Exactly one idempotent Changelog entry titled `Daftar Semua Agenda Lebih Ringkas`.

- [ ] **Step 1: Add a failing migration idempotency test**

  Require the migration, call `up()` twice, assert one null-version row with category `changed`, call `down()`, and assert the exact title is removed.

- [ ] **Step 2: Implement the migration**

  Use `DB::table('changelogs')->updateOrInsert(['version' => null, 'title' => ...])`; `down()` must delete only that exact null-version/title pair. Use Indonesian copy describing the compact all-agenda list.

- [ ] **Step 3: Run migration, formatting, tests, build, and cache**

  ```bash
  php artisan migrate --no-interaction
  vendor/bin/pint --dirty --format agent
  php artisan test tests/Feature/SalesAgendaWorkspaceRenderTest.php --compact
  npm run build
  php artisan optimize:clear
  php artisan view:cache
  git diff --check
  ```

  Expected: migration succeeds, the full focused render file passes, Pint/build/cache/diff checks pass.

### Task 4: Browser and final verification

**Files:**
- No source changes expected unless verification finds a defect.

- [ ] **Step 1: Verify the existing local preview at 390×844 and 1440×900**

  Reuse the running local server. Navigate to the authenticated all-agenda route if an existing test session is available; otherwise report the login boundary without requesting credentials.

- [ ] **Step 2: Inspect the compact layout states**

  Verify long titles wrap, status and detail links remain visible, no horizontal overflow appears, pagination remains usable, and the day view is unchanged.

- [ ] **Step 3: Inspect console and final repository state**

  Read browser console logs, run the full test suite where appropriate, run `graphify update .`, confirm the Changelog row count is one, and confirm `git diff --check` plus `git status` are clean after commit.

- [ ] **Step 4: Commit the focused change**

  ```bash
  git add resources/views/crm/sales-pocketbook/partials/agenda-history.blade.php resources/css/app.css tests/Feature/SalesAgendaWorkspaceRenderTest.php database/migrations/2026_11_03_140000_add_sales_agenda_history_compact_changelog.php docs/superpowers/specs/2026-11-03-sales-agenda-history-compact-design.md docs/superpowers/plans/2026-11-03-sales-agenda-history-compact.md public/build
  git commit -m "feat: compact sales agenda history"
  ```
