# Sales Agenda Unified Shell Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep the Buku Saku header and agenda context visible across receipt, submitted, and weekend states while making the current workflow status explicit.

**Architecture:** Preserve the existing `buku-saku-header` page shell and the existing weekday receipt/weekend list branches. Add one shared agenda workflow-status strip inside `agenda-receipt.blade.php`, and remove the mobile CSS rules that hide the page header when receipt mode is active. No controller, route, validation, authorization, or storage changes are needed.

**Tech Stack:** Laravel 13, PHP 8.3, Blade, existing CRM CSS, Alpine.js, PHPUnit, Vite.

**Spec:** `docs/superpowers/specs/2026-11-02-sales-agenda-unified-shell-design.md`

## Global Constraints

- Header `Buku Saku Saya`, identity, tabs, and weekly navigation remain in one consistent shell.
- Weekday receipt/submission behavior remains unchanged.
- Weekend direct-save behavior remains unchanged and never exposes a send action.
- Workflow state must be communicated with text, not color alone.
- Reuse existing CRM CSS primitives and existing modal/focus behavior.
- Do not change routes, validation, authorization, persistence, minute calculations, or domain terminology.
- Preserve unrelated uncommitted worktree changes and do not create a commit automatically.
- Add one idempotent Indonesian Changelog migration for this user-visible UX change.

## Review Focus

- Mobile receipt mode must no longer hide the Buku Saku header or tabs; pin this with a CSS source-contract test and rendered shell assertions.
- A weekday draft must say `DRAFT — BELUM DIKIRIM` while retaining the receipt canvas and send flow; pin this in the render test.
- A submitted weekday must say `TERKIRIM KE KOORDINATOR` without changing its existing list/action behavior; pin this in the render test.
- A weekend workspace must say `TERSIMPAN LANGSUNG — AKHIR PEKAN`, retain the direct-add action, and never expose a submit action; pin this in the weekend render test.
- An empty weekday must not be mislabeled as a draft with existing plans; pin a neutral `BELUM ADA RENCANA` state in the empty render test.

---

### Task 1: Pin the unified shell and workflow status contract

**Files:**
- Modify: `tests/Feature/SalesAgendaWorkspaceRenderTest.php`
- Modify: `tests/Feature/SalesAgendaWorkspaceInteractionMarkupTest.php`

**Interfaces:**
- Consumes: Existing `salesContext()`, `agendaAt()`, submission factories/helpers, and agenda workspace routes.
- Produces: Regression assertions for shell persistence, status labels, weekday receipt preservation, submitted compatibility, and weekend direct-save presentation.

- [ ] **Step 1: Add a failing weekday draft render test.**

  Create a weekday agenda and request its workspace. Assert the response contains the existing `sales-reference-header`, `sales-pocketbook-tabs`, `data-agenda-workflow-status="draft"`, and `DRAFT — BELUM DIKIRIM`; also assert `sales-receipt-canvas` and the weekday send contract remain present.

- [ ] **Step 2: Run the focused test to verify RED.**

  Run: `php artisan test tests/Feature/SalesAgendaWorkspaceRenderTest.php --filter='weekday.*draft|draft.*shell|workflow_status' --compact`

  Expected: FAIL because the shared workflow-status markup does not exist yet.

- [ ] **Step 3: Add submitted, weekend, and empty-state assertions.**

  Extend the render tests so:
  - a submitted weekday contains `data-agenda-workflow-status="submitted"` and `TERKIRIM KE KOORDINATOR`;
  - a weekend contains `data-agenda-workflow-status="weekend"` and `TERSIMPAN LANGSUNG — AKHIR PEKAN`, while keeping `openAgendaSheet($event)` and excluding the submit form;
  - an empty weekday contains `data-agenda-workflow-status="empty"` and `BELUM ADA RENCANA`.

- [ ] **Step 4: Add the CSS visibility contract.**

  In `SalesAgendaWorkspaceInteractionMarkupTest`, read `resources/css/app.css` and assert the receipt-mode header-hiding selectors are absent, while the existing receipt canvas and mobile shell selectors remain present. This protects the runtime behavior that rendered HTML alone cannot observe.

- [ ] **Step 5: Run the new focused tests to verify RED.**

  Run: `php artisan test tests/Feature/SalesAgendaWorkspaceRenderTest.php tests/Feature/SalesAgendaWorkspaceInteractionMarkupTest.php --filter='workflow|shell|weekend|receipt' --compact`

  Expected: FAIL only on the new status/header visibility assertions.

### Task 2: Implement the shared shell status strip and restore header visibility

**Files:**
- Modify: `resources/views/crm/sales-pocketbook/partials/agenda-receipt.blade.php`
- Modify: `resources/css/app.css`

**Interfaces:**
- Consumes: `$isWeekend`, `$daySubmission`, `$agendas`, `$agendaDate`, and existing receipt/list state variables.
- Produces: A shared `sales-agenda-workflow-status` block with stable `data-agenda-workflow-status` values `draft`, `submitted`, `weekend`, or `empty`; existing receipt/list branches remain responsible for their current content and actions.

- [ ] **Step 1: Add the status-state derivation in `agenda-receipt.blade.php`.**

  Derive the state in this order: `weekend` when `$isWeekend`, `submitted` when `$daySubmission`, `draft` when a non-weekend agenda exists without submission, otherwise `empty`. Use exact labels `TERSIMPAN LANGSUNG — AKHIR PEKAN`, `TERKIRIM KE KOORDINATOR`, `DRAFT — BELUM DIKIRIM`, and `BELUM ADA RENCANA`.

- [ ] **Step 2: Render the shared status strip immediately after weekly navigation.**

  Add an accessible labelled status region before the content branch. Include the selected date in Indonesian, the status label, and one concise supporting sentence. Use text and a semantic status/heading structure; do not rely on color alone. Keep the existing `agenda-receipt-title`/weekend label relationships valid.

- [ ] **Step 3: Remove all receipt-mode rules that hide `.sales-reference-header` and `.sales-pocketbook-tabs`.**

  Remove the duplicated selectors from each relevant CSS precedence block, but do not remove the receipt canvas styling or alter the existing modal/submit controls. Keep the page shell visible above the receipt canvas on mobile.

- [ ] **Step 4: Add focused status-strip styling.**

  Style the strip using existing OASIS paper/ink/yellow/status tokens, with a compact desktop layout and a readable single-column mobile layout. Keep touch targets and focus states intact, and do not introduce new dependencies or a second component system.

- [ ] **Step 5: Run the focused tests to verify GREEN.**

  Run: `php artisan test tests/Feature/SalesAgendaWorkspaceRenderTest.php tests/Feature/SalesAgendaWorkspaceInteractionMarkupTest.php --filter='workflow|shell|weekend|mobile_empty|agenda_form|receipt' --compact`

  Expected: PASS, with weekday receipt markup and weekend direct-list behavior preserved.

### Task 3: Add the Changelog entry and verify the complete affected workflow

**Files:**
- Create: `database/migrations/2026_11_02_140000_add_sales_agenda_unified_shell_changelog.php`
- Modify: `tests/Feature/SalesAgendaWorkspaceRenderTest.php`

**Interfaces:**
- Consumes: The existing `changelogs` table and migration idempotence pattern.
- Produces: One stable Indonesian Changelog entry for the unified Agenda shell and a test proving idempotence plus visibility on the Changelog page.

- [ ] **Step 1: Add the failing Changelog idempotence/visibility test.**

  Use a stable title such as `Shell Agenda Buku Saku menyatukan status dan tampilan` and assert two `up()` calls create exactly one `version = null` row, then assert a Super Admin can see the title on `changelogs.index`.

- [ ] **Step 2: Run the focused Changelog test to verify RED.**

  Run: `php artisan test tests/Feature/SalesAgendaWorkspaceRenderTest.php --filter='unified_shell_changelog' --compact`

  Expected: FAIL because the migration does not exist.

- [ ] **Step 3: Implement the idempotent Changelog migration.**

  Use `DB::table('changelogs')->updateOrInsert()` keyed by `version = null` and the exact stable title, with category `changed`, Indonesian description, `created_by = null`, and `down()` deleting only that title/version pair.

- [ ] **Step 4: Run migration and focused HTTP regression tests.**

  Run: `php artisan migrate --no-interaction` and then:

  `php artisan test tests/Feature/SalesAgendaReceiptAuthorizationTest.php tests/Feature/SalesAgendaReceiptWorkflowTest.php tests/Feature/SalesAgendaWorkspaceInteractionMarkupTest.php tests/Feature/SalesAgendaWorkspaceRenderTest.php tests/Feature/SalesPocketbookTest.php --compact`

  Expected: PASS for all affected agenda tests, including weekend persistence and weekday receipt/submission flows.

- [ ] **Step 5: Run final project checks for this change.**

  Run `php artisan optimize:clear`, `php artisan view:cache`, `npm run build`, `vendor/bin/pint --dirty --format agent`, `git diff --check`, and `graphify update .`. Inspect the final diff and preserve unrelated worktree changes. If the full suite is run, report any pre-existing failures separately rather than changing unrelated organization-parity code.
