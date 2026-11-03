# Sales Agenda Draft Edit/Delete Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Allow a Sales owner to edit or permanently delete planned agenda rows from the mobile receipt workspace until the day's receipt is submitted to the coordinator.

**Architecture:** Keep the existing receipt workspace and bottom sheet. Add dedicated plan-update and plan-delete endpoints rather than changing the existing `sales-agendas.update` result-completion contract. Put owner/submission/status/schedule checks in `SalesAgendaReceiptService`, use `OptimisticLockService` for both mutations, and keep the UI hidden-state backed by the same server authorization.

**Tech Stack:** Laravel 13, PHP 8.3, Blade, Alpine.js, existing `SalesAgendaReceiptService`, `OptimisticLockService`, PHPUnit, Vitest.

**Spec:** `docs/superpowers/specs/2026-11-02-sales-agenda-draft-edit-delete-design.md`

## Global Constraints

- Only the agenda owner may edit or delete their Sales agenda draft.
- Edit/delete are allowed only while no `sales_agenda_day_submissions` row exists for the agenda date.
- Only `planned` Sales agendas are draft-editable/deletable; `done`, `cancelled`, and `rescheduled` records remain protected.
- Keep existing owner/branch/project authorization, working-hours, overlap, target-minute, evidence, lifecycle, and optimistic-lock rules.
- Do not add draft persistence, localStorage, a migration for agenda data, a new frontend framework, or a second modal/toast architecture.
- Do not alter or deploy production data.
- Add one idempotent Oasis Changelog migration for the user-visible behavior.

## Review Focus

- A submitted agenda must be rejected server-side even if a stale client manually calls the endpoint — covered by submitted owner edit/delete tests in Task 1.
- Editing an agenda must exclude itself from overlap and capacity calculations — covered by the valid edit and overlap tests in Task 1.
- A non-owner must not mutate another Sales owner's agenda — covered by authorization tests in Task 1.
- A stale edit/delete must return the existing 409 conflict behavior without changing the record — covered by optimistic-lock tests in Task 1.
- Mobile markup must expose accessible edit/delete controls only in draft mode and preserve the existing bottom-sheet focus flow — covered by render/interaction tests in Task 3.

---

### Task 1: Add protected draft mutation contracts and backend behavior

**Files:**
- Create: `app/Http/Requests/Crm/UpdateSalesAgendaPlanRequest.php`
- Create: `app/Http/Requests/Crm/DeleteSalesAgendaPlanRequest.php`
- Modify: `routes/web.php:178-181`
- Modify: `app/Http/Controllers/Crm/SalesAgendaController.php:254-303`
- Modify: `app/Services/SalesAgendaReceiptService.php:95-145,240-244`
- Test: `tests/Feature/SalesAgendaReceiptAuthorizationTest.php`

**Interfaces:**
- `PATCH sales-agendas.plan.update` accepts `sales_activity_category`, `title`, `start_time`, `end_time`, `location`, and `expected_updated_at`; the scheduled date remains the agenda's existing date.
- `DELETE sales-agendas.plan.destroy` accepts `expected_updated_at`.
- `SalesAgendaReceiptService::updateDraft(User $actor, ContentItem $agenda, array $data): ContentItem` validates draft state, rechecks slot availability with the current agenda excluded, recalculates duration, rechecks target capacity, and updates only plan fields.
- `SalesAgendaReceiptService::deleteDraft(User $actor, ContentItem $agenda): void` validates draft state and deletes the agenda inside the optimistic-lock transaction.

- [ ] **Step 1: Write failing feature tests**

  Add tests proving that the owner can update title/category/location/time before submission, duration is recalculated, the owner can delete a planned agenda, editing itself does not trigger overlap, and submitted/non-owner/done/stale mutations are rejected without mutation.

- [ ] **Step 2: Run the focused tests to verify RED**

  Run: `php artisan test tests/Feature/SalesAgendaReceiptAuthorizationTest.php --filter='draft|plan|delete|update' --compact`

  Expected: failures because the named routes, requests, and draft mutation methods do not exist.

- [ ] **Step 3: Add request validation and routes**

  Create the two FormRequests with existing Sales Agenda category/time conventions, minimum five-minute multiple-of-five validation, and required optimistic-lock token. Register PATCH/DELETE routes with `permission:sales_pocketbook.manage_own`.

- [ ] **Step 4: Implement service/controller mutations**

  Add a single draft-state guard that checks Sales agenda identity, actor ownership, `planned` status, and absence of day submission. Use the existing `OptimisticLockService::execute()` in the controller; inside its locked callback call the receipt service. Reuse `assertTimeSlotAvailable(..., $agenda->id)` and add the corresponding capacity calculation that excludes the current agenda. Delete through the model so existing cascading evidence/task relationships remain consistent.

- [ ] **Step 5: Run the focused tests to verify GREEN**

  Run: `php artisan test tests/Feature/SalesAgendaReceiptAuthorizationTest.php --compact`

  Expected: all tests in the file pass, including owner, non-owner, submitted, status, overlap, capacity, and stale-lock cases.

### Task 2: Add server-rendered edit/delete actions to the receipt

**Files:**
- Modify: `resources/views/crm/sales-pocketbook/partials/agenda-receipt-canvas.blade.php:22-69`
- Modify: `resources/views/crm/sales-pocketbook/partials/agenda-receipt.blade.php:167-186`
- Modify: `resources/js/sales-agenda-workspace.js:5-285`
- Modify: `resources/css/app.css` near existing `.sales-receipt-paper-row`/receipt action styles
- Test: `tests/Feature/SalesAgendaWorkspaceInteractionMarkupTest.php`

**Interfaces:**
- Each draft row exposes a labelled `Edit` button and `Hapus` button with the agenda id, update URL, delete URL, optimistic-lock token, and editable fields.
- `salesAgendaWorkspace()` exposes `editingAgenda`, `deleteAgendaUrl`, `deleteAgendaToken`, `openAgendaEdit(event)`, `closeDeleteModal()`, and form-reset behavior.
- The existing add bottom sheet switches between create and edit mode without creating a second form or picker.

- [ ] **Step 1: Write failing render/interaction assertions**

  Assert draft markup contains labelled edit/delete controls, update/delete routes, the delete confirmation modal, and edit form state hooks; assert submitted markup contains no draft mutation controls.

- [ ] **Step 2: Run the focused render tests to verify RED**

  Run: `php artisan test tests/Feature/SalesAgendaWorkspaceInteractionMarkupTest.php --compact`

  Expected: failures because the receipt has no draft action markup or edit/delete modal.

- [ ] **Step 3: Render draft-only row actions and confirmation modal**

  Add compact touch-safe actions to each receipt row only when `$daySubmission` is absent. Use the existing CRM modal primitive for delete confirmation, with clear text that the action cannot be undone and a keyboard-operable close path.

- [ ] **Step 4: Add Alpine edit/delete state**

  Populate the existing bottom-sheet fields from the selected row, switch its form action/method/token, update the heading and submit label, and restore create mode when closed. Submit deletion through the dynamic DELETE form and preserve existing body-lock/focus restoration behavior.

- [ ] **Step 5: Add restrained receipt action styling**

  Use existing OASIS semantic/danger tokens, compact spacing, visible focus, and minimum 44px touch targets. Do not add a new color system or rounded SaaS card treatment.

- [ ] **Step 6: Run render and frontend tests to verify GREEN**

  Run: `php artisan test tests/Feature/SalesAgendaWorkspaceInteractionMarkupTest.php --compact`

  Run: `npx vitest run tests/frontend/sales-agenda-slot.test.js` if Vitest is available in the existing local toolchain; this repository has no npm test script, so do not add one.

  Expected: backend markup and existing slot behavior pass.

### Task 3: Changelog, integration verification, and quality gates

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_add_sales_agenda_draft_edit_delete_changelog.php` via Artisan
- Modify: `tests/Feature/SalesAgendaWorkspaceRenderTest.php` or `tests/Feature/SalesAgendaReceiptAuthorizationTest.php` for the exact changelog entry
- Test: focused Sales Agenda feature tests and full repository test command

**Interfaces:**
- Changelog identity: `version = null`, title `Sales dapat mengubah rencana sebelum dikirim`, category `changed`, idempotent `updateOrInsert`, and `down()` removes only that title.

- [ ] **Step 1: Add the idempotent Changelog migration and test it twice**

  Verify exactly one row exists and the Changelog page renders the Indonesian title.

- [ ] **Step 2: Run all affected backend tests**

  Run: `php artisan test tests/Feature/SalesAgendaReceiptAuthorizationTest.php tests/Feature/SalesAgendaWorkspaceInteractionMarkupTest.php tests/Feature/SalesAgendaWorkspaceRenderTest.php --compact`

  Expected: zero failures.

- [ ] **Step 3: Clear/cache Blade and build frontend assets**

  Run: `php artisan optimize:clear; php artisan view:cache` and `npm run build`.

- [ ] **Step 4: Run formatting, graph, and diff checks**

  Run: `vendor/bin/pint --dirty --format agent`, `graphify update .`, and `git diff --check`.

- [ ] **Step 5: Run the repository's full test command and report pre-existing failures honestly**

  Run: `composer test`.

Expected: affected Sales Agenda tests pass; any known unrelated suite errors (such as the existing missing `OrganizationParityFilter` or non-scope failures) are reported rather than hidden.

### Follow-up: Preserve Selected Agenda Date After Mutations

**Goal:** Keep the Sales workspace on the selected `work_date` after adding, editing, or deleting an agenda, and make the receipt heading reflect that selected date.

**Approach:** Extend `SalesAgendaController::successRedirect()` with an optional date context and pass the agenda's scheduled date from Sales mutation actions. Coordinator redirects remain unchanged. Derive a context-aware receipt heading: `RENCANA HARI INI`, `RENCANA BESOK`, or `RENCANA <tanggal>`. Add feature coverage for today and future-date (`Rencana`) redirects and headings.

**Verification:** Run the focused Sales Agenda authorization/render tests, Pint, and `git diff --check`.

### Task 4: Preserve workspace date and receipt context

**Files:**
- Modify: `app/Http/Controllers/Crm/SalesAgendaController.php`
- Modify: `resources/views/crm/sales-pocketbook/partials/agenda-receipt-canvas.blade.php`
- Test: `tests/Feature/SalesAgendaReceiptAuthorizationTest.php`
- Test: `tests/Feature/SalesAgendaWorkspaceRenderTest.php`

**Steps:**

- [ ] Write redirect and heading regression tests for today and future agenda dates; run them RED.
- [ ] Add optional `work_date` handling to `successRedirect()` and pass the relevant scheduled date from agenda mutations.
- [ ] Derive the receipt heading from the selected date: `RENCANA HARI INI`, `RENCANA BESOK`, or `RENCANA <tanggal>`.
- [ ] Run focused tests, Pint, and `git diff --check`.
