# Sales Agenda Draft, Duration, and History Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix the mobile Sales Agenda preview and custom duration flow, make date navigation unambiguous, and add an owner-scoped “Lihat semua agenda” history view without introducing draft persistence.

**Architecture:** Keep `sales-agendas.index` as the personal Sales entry point. The default `view=day` path continues rendering the selected-date receipt; `view=all` renders a separate paginated owner-only history partial. Alpine keeps duration calculations client-side for immediate feedback, while the existing server-side start/end and receipt service rules remain authoritative.

**Tech Stack:** Laravel 13, PHP 8.3, Blade, Alpine.js, vanilla ES modules, PHPUnit, Node’s built-in test runner, Vite/Tailwind build.

**Spec:** `docs/superpowers/specs/2026-11-02-sales-agenda-draft-duration-history-design.md`

## Global Constraints

- Local development only; do not change production or production data.
- Do not add draft persistence, migrations for draft rows, localStorage state, or a new frontend framework.
- Preserve `ContentItem`, `SalesAgendaReceiptService`, named routes, owner authorization, evidence, submission, and working-hours rules.
- `Simpan draft struk` closes the receipt preview only; it does not submit the day or send a request.
- `view=all` must remain owner-scoped and must not weaken route, policy, branch, project, or Sales restrictions.
- Use existing OASIS receipt tokens, minimum 44px controls, focus restoration, Escape close, and shared pagination conventions.
- Add one idempotent Indonesian Oasis Changelog entry for the user-visible behavior change.

## Review Focus

- Custom duration `180` must not be lost because the preset `<select>` has no matching option; pin this in `tests/frontend/sales-agenda-slot.test.js` through the duration-resolution contract (Task 1).
- A selected “Hari ini” must not also mark “Rencana” active; pin the rendered classes/ARIA state for today (Task 1).
- `view=all` must include older and future personal agendas while excluding another Sales user’s agenda; pin owner isolation and pagination input (Task 1).
- The unfinished badge must count active unfinished agendas only, excluding `done`, `cancelled`, and `rescheduled`; pin this with mixed statuses (Task 1).
- Closing the mobile receipt preview must not submit or mutate `SalesAgendaDaySubmission`; pin the markup contract and preserve the existing submit form separately (Task 1).

---

### Task 1: Write failing regression coverage

**Files:**
- Modify: `tests/frontend/sales-agenda-slot.test.js`
- Modify: `tests/Feature/SalesAgendaWorkspaceRenderTest.php`

**Interfaces:**
- Consumes: current `salesAgendaWorkspace` markup, `sales-agendas.index`, and `calculateAgendaSlot` behavior.
- Produces: failing contracts for duration resolution, preview draft label, exact date active state, history mode, owner isolation, and unfinished-count semantics.

- [ ] **Step 1: Add the frontend custom-duration regression test**

  Import the named duration-resolution helper from `resources/js/sales-agenda-workspace.js` and assert that an empty preset plus custom `180` resolves to `180`, while a selected preset still wins and an empty custom value remains invalid/empty.

- [ ] **Step 2: Add the rendered workspace regressions**

  Extend `SalesAgendaWorkspaceRenderTest` with tests that:

  - render today and assert exactly one date-context link has `is-active`/`aria-current`, on `Hari ini`, while `Rencana` does not;
  - render the mobile preview contract and assert `Simpan draft struk` is present, the old `&times;` close control is absent from the preview header, and the existing `data-receipt-submit` contract remains;
  - create personal agendas across yesterday, today, and a future date plus an agenda for another Sales user, request `view=all`, and assert only the current user’s titles render;
  - mix `planned`, `confirmed`, `done`, `cancelled`, and `rescheduled` records and assert the history action reports only the active unfinished count;
  - assert the history action is linked with `view=all` and the selected `work_date` remains available for returning to the receipt.

- [ ] **Step 3: Run the narrow tests and verify they fail for the intended reasons**

  Run:

  ```powershell
  node --test tests/frontend/sales-agenda-slot.test.js
  php artisan test --compact tests/Feature/SalesAgendaWorkspaceRenderTest.php
  ```

  Expected: the new duration helper is unavailable or returns the wrong value, the preview still exposes the old close label, today/Rencana state or history markup is missing, and the new history query contract is not yet implemented. Do not alter unrelated worktree changes to make this red state pass.

- [ ] **Step 4: Commit the regression tests**

  ```powershell
  git add tests/frontend/sales-agenda-slot.test.js tests/Feature/SalesAgendaWorkspaceRenderTest.php
  git commit -m "test: cover sales agenda history and draft controls"
  ```

### Task 2: Fix custom duration state in Alpine

**Files:**
- Modify: `resources/js/sales-agenda-workspace.js`
- Test: `tests/frontend/sales-agenda-slot.test.js`

**Interfaces:**
- Consumes: preset select value, custom numeric input value, and `calculateAgendaSlot`.
- Produces: named `resolveAgendaDuration(presetValue, customValue)` export and a `syncSlot(durationOverride)` path that calculates custom durations without requiring a matching select option.

- [ ] **Step 1: Implement `resolveAgendaDuration(presetValue, customValue): string`**

  Return the non-empty custom value when the custom control is active; otherwise return the selected preset. Keep the helper small and deterministic so the Node test covers the browser wiring contract without introducing a DOM dependency.

- [ ] **Step 2: Update `syncCustomDuration()` and `syncSlot()`**

  Pass the custom numeric value as the duration override into `syncSlot()` instead of assigning it to the preset `<select>`. Keep preset chip selection unchanged. Make `syncChipState()` use the resolved value so the Custom chip is active only when a valid non-preset value is present.

- [ ] **Step 3: Run the frontend tests**

  Run `node --test tests/frontend/sales-agenda-slot.test.js` and expect all existing slot tests plus the new custom-resolution regression to pass.

- [ ] **Step 4: Add server-side duration integrity validation**

  In `app/Http/Requests/Crm/StoreSalesAgendaRequest.php`, add an `after` validation check that derives the submitted start/end duration and rejects values below 5 minutes or not divisible by 5. This keeps the server authoritative without trusting the browser’s preset/custom field and preserves the controller’s existing authoritative duration calculation. Add the focused invalid-duration request assertion to `tests/Feature/SalesAgendaReceiptAuthorizationTest.php`.

- [ ] **Step 5: Run the backend duration test and commit the focused fix**

  ```powershell
  php artisan test --compact tests/Feature/SalesAgendaReceiptAuthorizationTest.php --filter="duration"
  vendor/bin/pint --dirty --format agent
  git add resources/js/sales-agenda-workspace.js app/Http/Requests/Crm/StoreSalesAgendaRequest.php tests/frontend/sales-agenda-slot.test.js tests/Feature/SalesAgendaReceiptAuthorizationTest.php
  git commit -m "fix: make custom sales agenda durations calculate"
  ```

### Task 3: Replace preview close control and correct date active state

**Files:**
- Modify: `resources/views/crm/sales-pocketbook/partials/agenda-receipt-canvas.blade.php`
- Modify: `resources/views/crm/sales-pocketbook/partials/agenda-receipt.blade.php`
- Modify: `resources/css/app.css`
- Test: `tests/Feature/SalesAgendaWorkspaceRenderTest.php`

**Interfaces:**
- Consumes: `closeReceipt()` focus-restoration behavior and `$agendaDate`/`$today` values.
- Produces: accessible `Simpan draft struk` preview control and exact next-day `Rencana` active-state calculation.

- [ ] **Step 1: Replace only the mobile receipt-preview close button**

  In `agenda-receipt-canvas.blade.php`, keep the existing `@click="closeReceipt()"`, `x-ref`, and accessible dialog relationship, but replace the glyph-only button text with visible `Simpan draft struk`. Leave the agenda form bottom-sheet close button unchanged because it closes an editing form, not the receipt preview.

- [ ] **Step 2: Make the active date comparison explicit**

  In `agenda-receipt.blade.php`, set `$isPlan` only when `$agendaDate` is the exact next day (`$today->addDay()`). Keep every date link’s `work_date` contract unchanged; `view=all` handling is owned by Task 4.

- [ ] **Step 3: Adjust preview-header styling**

  Update the route-specific preview-header button rules so the text control has an appropriate compact width/padding and readable focus state rather than a large 24px X glyph. Preserve the 44px minimum touch target and OASIS yellow/ink contrast.

- [ ] **Step 4: Run the focused feature test**

  Run `php artisan test --compact tests/Feature/SalesAgendaWorkspaceRenderTest.php --filter="date_navigation|mobile_agenda_form|mobile_empty|preview"` and expect the new preview/date assertions to pass.

- [ ] **Step 5: Commit the preview/date fix**

  ```powershell
  git add resources/views/crm/sales-pocketbook/partials/agenda-receipt-canvas.blade.php resources/views/crm/sales-pocketbook/partials/agenda-receipt.blade.php resources/css/app.css tests/Feature/SalesAgendaWorkspaceRenderTest.php
  git commit -m "fix: clarify sales agenda receipt draft close"
  ```

### Task 4: Add owner-scoped all-agenda history mode

**Files:**
- Modify: `app/Http/Controllers/Crm/SalesAgendaController.php`
- Modify: `resources/views/crm/sales-pocketbook/partials/agenda-receipt.blade.php`
- Create: `resources/views/crm/sales-pocketbook/partials/agenda-history.blade.php`
- Modify: `resources/css/app.css`
- Test: `tests/Feature/SalesAgendaWorkspaceRenderTest.php`

**Interfaces:**
- Consumes: validated `view=all`, current Sales owner, selected `work_date`, and existing `ContentItem` relations/statuses.
- Produces: `$agendaView`, `$allAgendas`, and `$incompleteAgendaCount` for the view; URL `sales-agendas.index?tab=agenda&view=all&work_date=YYYY-MM-DD`.

- [ ] **Step 1: Add the validated view mode and query composition**

  In `SalesAgendaController::index`, validate `view` as nullable/`all`, normalize it to `day` or `all`, and keep the existing selected-date `$agendas` query for receipt data. Add a paginated owner-only `$allAgendas` query with `branch`, `salesProject`, and `evidence` eager loaded, ordered by scheduled date descending/start time/id, using the existing `agenda_page` paginator key and `withQueryString()`.

- [ ] **Step 2: Compute the unfinished badge from active statuses**

  Add `$incompleteAgendaCount` for the same owner and Sales agenda type, excluding `done`, `cancelled`, and `rescheduled`. Do not filter these records out of `$allAgendas`; the history list remains complete.

- [ ] **Step 3: Render the history branch in a focused partial**

  Create `agenda-history.blade.php` with a labelled section, a return-to-selected-day link, responsive agenda rows/cards showing date, time, title, category, project/location, status text, and a named detail link. Use the existing empty-state and pagination conventions; do not create a second data model or action route.

- [ ] **Step 4: Add the bottom history action to the day receipt**

  In `agenda-receipt.blade.php`, render `Lihat semua agenda` below the selected-day content whenever `view=day`. Include a visible text badge only when `$incompleteAgendaCount > 0`, using copy such as `N belum selesai`; make the entire control keyboard accessible and route-generated.

- [ ] **Step 5: Branch the page safely**

  Ensure receipt calculations and add-preview controls use only the selected-date `$agendas` collection. When `view=all`, render the history partial instead of treating the paginated all-agenda result as a receipt. Keep date navigation visible and ensure no all-mode context link is incorrectly marked active.

- [ ] **Step 6: Add responsive history styles**

  Use existing receipt/workspace tokens and spacing. Keep rows readable at approximately 390px without horizontal scrolling; desktop may use a compact grid. Provide visible focus styling and semantic status text rather than color-only badges.

- [ ] **Step 7: Run the feature regressions**

  Run `php artisan test --compact tests/Feature/SalesAgendaWorkspaceRenderTest.php` and expect the owner-isolation, mixed-status badge, history link, and date-state tests to pass.

- [ ] **Step 8: Commit the history slice**

  ```powershell
  git add app/Http/Controllers/Crm/SalesAgendaController.php resources/views/crm/sales-pocketbook/partials/agenda-receipt.blade.php resources/views/crm/sales-pocketbook/partials/agenda-history.blade.php resources/css/app.css tests/Feature/SalesAgendaWorkspaceRenderTest.php
  git commit -m "feat: add personal sales agenda history"
  ```

### Task 5: Record the user-visible change in Oasis Changelog

**Files:**
- Create: `database/migrations/2026_11_02_120100_add_sales_agenda_history_ux_changelog.php`
- Modify: `tests/Feature/SalesAgendaWorkspaceRenderTest.php`

**Interfaces:**
- Consumes: `changelogs` table and existing `updateOrInsert` migration conventions.
- Produces: one idempotent system changelog entry titled `Perbaikan draft, durasi custom, dan riwayat Agenda Sales` with category `changed` and null version.

- [ ] **Step 1: Add the migration**

  Use `DB::table('changelogs')->updateOrInsert(...)` keyed by stable `version` + `title` identity, with Indonesian user-facing description, `created_by` null, category `changed`, and a `down()` that removes only the exact title/version pair.

- [ ] **Step 2: Add idempotency/render assertions**

  Run the migration twice in the feature test, assert exactly one matching row, and assert a superadmin can see the title on `changelogs.index`.

- [ ] **Step 3: Run the focused migration test and format PHP**

  Run:

  ```powershell
  php artisan test --compact tests/Feature/SalesAgendaWorkspaceRenderTest.php
  vendor/bin/pint --dirty --format agent
  ```

- [ ] **Step 4: Commit the changelog slice**

  ```powershell
  git add database/migrations/2026_11_02_120100_add_sales_agenda_history_ux_changelog.php tests/Feature/SalesAgendaWorkspaceRenderTest.php
  git commit -m "docs: record sales agenda history improvements"
  ```

### Task 6: Full verification and local browser review

**Files:**
- Modify: only files already listed above if verification reveals a defect.

- [ ] **Step 1: Clear/cache application views and run focused backend tests**

  Run `php artisan optimize:clear`, `php artisan view:cache`, and the focused Sales Agenda feature test file. Expected: all focused tests pass; known unrelated full-suite baseline errors remain documented and are not masked.

- [ ] **Step 2: Run frontend tests and build assets**

  Run `node --test tests/frontend/sales-agenda-slot.test.js` and `npm run build`. Expected: custom 180 calculation passes and Vite emits a successful build.

- [ ] **Step 3: Run hygiene checks**

  Run `vendor/bin/pint --dirty --format agent` and `git diff --check`. Inspect `git status --short` and ensure no unrelated pre-existing worktree files are staged or committed.

- [ ] **Step 4: Verify the local preview**

  Reuse the existing local preview at port `8002` if available. At approximately 390x844 and 1440x900, inspect: preview header/button sizing, close-to-workspace focus restoration, custom 180 duration/end-time feedback, only one active date context, history badge/list, mobile overflow, and console errors. Do not claim browser verification if authentication prevents access.

- [ ] **Step 5: Run the repository graph update**

  Run `graphify update .` after code changes so `graphify-out/` reflects the final implementation.
