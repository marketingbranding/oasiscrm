# Sales Agenda Future Date Picker Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Allow the Sales Agenda Rencana view to choose tomorrow or any later date while excluding today and past dates.

**Architecture:** Reuse the existing CRM date picker and URL-backed `work_date` workspace state. Add a minimum-date constraint to the shared picker and validate the dedicated plan-date query server-side without changing Hari ini or historical agenda input behavior.

**Tech Stack:** Laravel 13, Blade, Alpine.js, vanilla JavaScript, PHPUnit, Node built-in test runner, Vite.

**Spec:** Approved conversation design: Rencana defaults to tomorrow, accepts future dates without an upper bound, and rejects today/past dates.

## Global Constraints

- Preserve existing OASIS terminology and the current compact retro workstation visual system.
- Reuse the existing CRM date picker; do not add a calendar dependency.
- Keep Hari ini, Kemarin, and history navigation behavior unchanged.
- Validate user-controlled date state on the server; do not rely only on hidden navigation/UI state.
- Add one idempotent Indonesian Oasis Changelog entry for this user-visible behavior.

## Review Focus

- Tomorrow selected by default: markup test in `SalesAgendaWorkspaceInteractionMarkupTest`.
- A later future date remains selected and drives the workspace: render test in `SalesAgendaWorkspaceRenderTest`.
- Today and past plan-date requests are rejected: controller feature test in `SalesAgendaWorkspaceRenderTest`.
- Existing Hari ini and historical URLs remain valid: existing focused Sales Agenda suite.
- Calendar clicks cannot bypass the minimum date: Node test for the shared date-range helper.

---

### Task 1: Lock the future-date contract with tests

**Files:**
- Modify: `tests/Feature/SalesAgendaWorkspaceInteractionMarkupTest.php`
- Modify: `tests/Feature/SalesAgendaWorkspaceRenderTest.php`
- Modify: `tests/frontend/crm-datepicker.test.js`

- [ ] Add failing feature assertions for the Rencana picker, tomorrow minimum, selected future date, and rejected today/past `plan_date` requests.
- [ ] Add failing Node assertions for allowed dates at/after `min` and rejected dates before `min`.
- [ ] Run the focused PHP and Node tests and confirm RED.

### Task 2: Add server-backed future plan-date resolution

**Files:**
- Modify: `app/Http/Controllers/Crm/SalesAgendaController.php`

- [ ] Validate optional `plan_date` as `Y-m-d`.
- [ ] Reject `plan_date` values before tomorrow with a field validation error.
- [ ] Use valid `plan_date` as the workspace date while preserving existing `work_date` behavior for Hari ini/history and mutation redirects.
- [ ] Run the focused controller/render tests and confirm GREEN.

### Task 3: Add the Rencana date picker UI

**Files:**
- Modify: `resources/views/crm/sales-pocketbook/partials/agenda-receipt.blade.php`
- Modify: `resources/css/app.css`

- [ ] Render the existing CRM date field beside the Rencana context, defaulting to tomorrow when no future plan date is selected.
- [ ] Submit `plan_date` with `tab=agenda` on selection and keep the selected future date visible in the receipt.
- [ ] Apply the existing OASIS spacing and touch-target conventions without changing the other date-navigation links.
- [ ] Run the focused markup/render tests and `npm run build`.

### Task 4: Enforce minimum date in the shared picker

**Files:**
- Modify: `resources/js/crm-datepicker.js`
- Create: `tests/frontend/crm-datepicker.test.js`

- [ ] Export a small pure date-range helper for testability.
- [ ] Prevent calendar selection before the input `min` date and disable the `Hari Ini` shortcut when it is outside the allowed range.
- [ ] Preserve unrestricted behavior for existing date fields without `min`.
- [ ] Run the Node picker tests and rebuild assets.

### Task 5: Record the user-visible change and final verification

**Files:**
- Create: `database/migrations/2026_11_02_*.php`
- Modify: `tests/Feature/SalesAgendaWorkspaceRenderTest.php`

- [ ] Add an idempotent Indonesian Changelog migration and verify exactly one visible row.
- [ ] Run migration, Blade cache, focused PHP tests, Node tests, Pint, build, and `git diff --check`.
- [ ] Run the full suite and report unrelated repository blockers separately.
