# Sales Agenda Weekend Direct Plan Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make weekend Sales Agenda plans direct-save list items, without receipt UI or submission, while preserving the weekday receipt workflow.

**Architecture:** Keep the existing `sales-agendas.store` endpoint, validation, authorization, and modal state. Add a weekend-only list presentation in Blade, bypass the receipt preview for weekend actions, and change the modal copy to the approved labels. Preserve existing historical weekend submission rendering without exposing a new weekend submit action.

**Tech Stack:** Laravel 13, PHP 8.3, Blade, Alpine.js, existing CRM CSS, PHPUnit, Vite.

**Spec:** `docs/superpowers/specs/2026-11-02-sales-agenda-weekend-direct-plan-design.md`

## Global Constraints

- Weekend plans are saved directly through the existing agenda store route.
- Weekend rendering must not include the receipt canvas, receipt preview, or “Sobek & kirim ke Koordinator” action.
- Weekday receipt/submission behavior remains unchanged.
- Existing weekend submission records remain renderable for historical compatibility.
- Modal copy is exactly “Tambah Rencana” and “Tambahkan Rencana” for the create state.
- Preserve existing modal focus management, Escape handling, focus restoration, and body-scroll lock.
- Add regression coverage and one idempotent Indonesian Changelog migration.
- Do not reset, clean, or commit unrelated existing worktree changes.

## Review Focus

- Empty weekend: the add button opens `openAgendaSheet($event)` directly and no preview markup is rendered; cover in `SalesAgendaWorkspaceRenderTest`.
- Weekend with an unsubmitted saved agenda: the plan is visible as a normal list, with no receipt or submit form; cover in `SalesAgendaWorkspaceRenderTest`.
- Historical weekend submission: the existing submitted list remains available without a receipt preview; cover in the weekend render test.
- Weekday regression: an unsubmitted weekday agenda still renders receipt mode and the weekday add flow; preserve existing receipt tests and update only approved copy.
- Modal reset state: create mode uses the new title/button labels after edit state is closed; cover the Blade/JS source contract and focused frontend tests where applicable.

---

### Task 1: Pin the weekend direct-save rendering contract

**Files:**
- Modify: `tests/Feature/SalesAgendaWorkspaceRenderTest.php`
- Modify: `tests/Feature/SalesAgendaWorkspaceInteractionMarkupTest.php`

**Interfaces:**
- Consumes: Existing `salesContext()`, `agendaAt()`, and agenda workspace route helpers.
- Produces: Regression assertions for weekend direct modal opening, no weekend receipt/submit UI, weekend list rendering, and preserved weekday receipt mode.

- [ ] **Step 1: Add failing tests for an empty weekend workspace.** Assert the rendered Sunday page contains `openAgendaSheet($event)`, does not contain `openAgendaPreview($event)`, does not contain `data-agenda-receipt-preview`, and contains `Tambah Rencana` / `Tambahkan Rencana`.
- [ ] **Step 2: Run the focused render and interaction tests to verify RED.**

Run: `php artisan test tests/Feature/SalesAgendaWorkspaceRenderTest.php tests/Feature/SalesAgendaWorkspaceInteractionMarkupTest.php --filter='weekend|mobile_empty|agenda_form' --compact`

Expected: FAIL because weekend currently renders or opens receipt preview and the modal still uses the old copy.

- [ ] **Step 3: Add a failing test for an unsubmitted weekend agenda.** Create one Sunday agenda, request the Sunday workspace, and assert a weekend list marker/title is visible while `sales-receipt-canvas`, `sales-receipt-submit-form`, and `Sobek &amp; kirim ke Koordinator` are absent.
- [ ] **Step 4: Run the new focused test to verify RED.**

Run: `php artisan test tests/Feature/SalesAgendaWorkspaceRenderTest.php --filter='unsubmitted_weekend' --compact`

Expected: FAIL because an unsubmitted weekend with an agenda currently renders receipt mode.

### Task 2: Implement weekend list mode and direct modal opening

**Files:**
- Create: `resources/views/crm/sales-pocketbook/partials/agenda-weekend-list.blade.php`
- Modify: `resources/views/crm/sales-pocketbook/partials/agenda-receipt.blade.php`
- Modify: `resources/views/crm/sales-pocketbook/partials/agenda-receipt-canvas.blade.php` only if a shared weekend-only branch needs removal there
- Modify: `resources/js/sales-agenda-workspace.js`

**Interfaces:**
- Consumes: `$agendas`, `$agendaDate`, `$receiptSummary`, `$daySubmission`, existing agenda detail routes, and the existing `openAgendaSheet()` Alpine method.
- Produces: Weekend pages that render `sales-weekend-plan`, direct add buttons, and no receipt/submit UI; create-mode modal labels `Tambah Rencana` and `Tambahkan Rencana`.

- [ ] **Step 1: Change the Blade state condition so weekend agendas never enter `$isReceiptMode`.** Keep weekday receipt mode exactly as-is.
- [ ] **Step 2: Add `agenda-weekend-list.blade.php` for saved weekend plans.** Render a labelled weekend summary, `AKHIR PEKAN`, flexible/clock time rows, category/status text, detail links, and a direct add button using `@click="openAgendaSheet($event)"`; do not render receipt controls or submission forms.
- [ ] **Step 3: Route all non-historical weekend rendering to the weekend list branch.** Keep existing `$daySubmission` list rendering for historical submission records, but ensure its add action also opens the modal directly.
- [ ] **Step 4: Prevent the weekend receipt preview include from rendering.** Weekday empty workspaces continue to render the mobile receipt preview contract.
- [ ] **Step 5: Update create-mode labels.** Set the Blade title/button and JavaScript reset fallback to exactly `Tambah Rencana` and `Tambahkan Rencana`; keep edit and submitted-extra labels unchanged.
- [ ] **Step 6: Run the focused tests to verify GREEN.**

Run: `php artisan test tests/Feature/SalesAgendaWorkspaceRenderTest.php tests/Feature/SalesAgendaWorkspaceInteractionMarkupTest.php --filter='weekend|mobile_empty|agenda_form|unsubmitted|receipt' --compact`

Expected: PASS; weekend is list/direct-modal mode and weekday receipt behavior remains covered.

### Task 3: Preserve server behavior and add the user-visible changelog

**Files:**
- Modify: `tests/Feature/SalesAgendaReceiptAuthorizationTest.php` only if the direct-save weekend regression needs a focused HTTP assertion
- Create: `database/migrations/2026_11_02_130000_add_sales_agenda_weekend_direct_plan_changelog.php`

**Interfaces:**
- Consumes: Existing `sales-agendas.store` behavior and `changelogs` table contract.
- Produces: Proof that an authenticated Sales user can still create a weekend agenda through the existing route, plus an idempotent Changelog entry with `version = null`, category `changed`, and Indonesian user-facing copy.

- [ ] **Step 1: Add or update the focused authorization test.** Assert a Sunday agenda can be stored without a day submission or weekday target and redirects back to the selected Sunday.
- [ ] **Step 2: Run the focused HTTP test to verify it remains green before migration work.**

Run: `php artisan test tests/Feature/SalesAgendaReceiptAuthorizationTest.php --filter='weekend' --compact`

Expected: PASS, proving no controller/request/storage change is needed.

- [ ] **Step 3: Add the idempotent Changelog migration.** Use `DB::table('changelogs')->updateOrInsert()` keyed by `version = null` and a stable Indonesian title; `down()` deletes only that exact title/version pair.
- [ ] **Step 4: Run the migration and verify exactly one Changelog row exists.**

Run: `php artisan migrate --no-interaction`

Verify with the repository's read-only database query tooling or the existing Changelog feature test; expected exactly one row for the new title.

### Task 4: Full verification and review

**Files:**
- Verify all changed files; no additional product files expected.

- [ ] **Step 1: Clear and rebuild Laravel view caches.**

Run: `php artisan optimize:clear` then `php artisan view:cache`

- [ ] **Step 2: Run the complete affected agenda suite.**

Run: `php artisan test tests/Feature/SalesAgendaReceiptAuthorizationTest.php tests/Feature/SalesAgendaReceiptWorkflowTest.php tests/Feature/SalesAgendaWorkspaceInteractionMarkupTest.php tests/Feature/SalesAgendaWorkspaceRenderTest.php tests/Feature/SalesPocketbookTest.php --compact`

Expected: PASS with no skipped tests.

- [ ] **Step 3: Run frontend build and formatting checks.**

Run: `npm run build`, `vendor/bin/pint --dirty --format agent`, and `git diff --check`.

- [ ] **Step 4: Run `graphify update .` and inspect the final diff/status.** Confirm only the approved weekend workflow, tests, changelog, and design/plan documents are represented; do not claim authenticated visual verification unless credentials are available.

Because this worktree already contains unrelated uncommitted changes, do not create a commit automatically; report the exact changed paths and leave integration/commit decisions to the user.
