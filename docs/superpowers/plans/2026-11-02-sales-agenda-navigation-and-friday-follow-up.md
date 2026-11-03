# Sales Agenda Navigation and Friday Follow-up Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Simplify the Sales Agenda mobile workspace, clarify the Friday schedule calculation, and preserve the submitted-agenda list view.

**Architecture:** Keep the existing `work_date` weekly navigation contract and receipt/submission state machine. Remove only the weekly anchor-date picker, add spacing through the existing Sales reference CSS, and pin the Friday effective-minute rules plus submitted list rendering with focused tests.

**Tech Stack:** Laravel 13, PHP 8.3, Blade, CarbonImmutable, existing CRM CSS, PHPUnit, Vite.

**Spec:** User-approved clarification in conversation; existing `docs/superpowers/designs/2026-11-02-sales-agenda-weekly-date-navigation.md` remains the navigation source of truth.

## Global Constraints

- Keep `work_date=Y-m-d` as the canonical selected-day URL state.
- Remove only the weekly date-picker control; keep day links and previous/next week controls.
- Friday remains 07:30–17:00 with an 11:30–13:00 break and a 480-minute effective target.
- Agenda days remain in receipt mode until submitted; submitted days render the agenda list and coordinator status.
- Do not change agenda submit, edit, delete, reschedule, authorization, or data-storage rules.
- Preserve the existing OASIS mobile reference styling and 44px touch targets.
- Add or update regression tests for every behavior change.

## Review Focus

- The removed picker must not remove `work_date` links or break week navigation; cover markup and canonical links in `SalesAgendaWorkspaceRenderTest`.
- Tab navigation and weekly controls must have a visible gap on mobile without breaking the compact layout; cover the source contract and build.
- A Friday agenda from 07:30–17:00 must count as 480 effective minutes, while 08:00–17:00 must correctly count as 450; cover `SalesAgendaReceiptWorkflowTest`.
- Submitted agendas must render the list state and not the draft receipt state; cover `SalesAgendaWorkspaceRenderTest`.
- Unsubmitted agendas must retain the receipt state; preserve existing receipt markup coverage.

---

### Task 1: Remove the weekly anchor-date picker and separate navigation blocks

**Files:**
- Modify: `resources/views/crm/sales-pocketbook/partials/agenda-week-navigation.blade.php`
- Modify: `resources/css/app.css`
- Modify: `tests/Feature/SalesAgendaWorkspaceRenderTest.php`
- Modify: `tests/Feature/SalesAgendaWorkspaceInteractionMarkupTest.php`

**Interfaces:**
- Preserve `agendaWeek['previous_date']`, `agendaWeek['next_date']`, and each day link’s `tab=agenda&work_date=Y-m-d` URL.
- Remove `.sales-week-navigation-picker`, `sales-agenda-week-date`, and `Pilih minggu` from the rendered weekly toolbar.
- Add a deliberate mobile/desktop gap between `.sales-pocketbook-tabs` and `.sales-week-navigation` using existing spacing tokens.

- [x] **Step 1: Write failing markup assertions** that the weekly navigation has no date picker but retains previous/next links, seven date links, and the canonical `work_date` values.
- [x] **Step 2: Run the focused markup tests to verify RED.**

Run: `php artisan test tests/Feature/SalesAgendaWorkspaceRenderTest.php tests/Feature/SalesAgendaWorkspaceInteractionMarkupTest.php --filter='week|navigation|date' --compact`

Expected: FAIL because the current weekly toolbar still renders the date picker.

- [x] **Step 3: Remove only the picker form from `agenda-week-navigation.blade.php` and add spacing between tabs and weekly navigation in the Sales reference CSS.**
- [x] **Step 4: Run the focused markup tests and Vite build to verify GREEN.**

Run: `php artisan test tests/Feature/SalesAgendaWorkspaceRenderTest.php tests/Feature/SalesAgendaWorkspaceInteractionMarkupTest.php --compact`

Run: `npm run build`

Expected: PASS; no picker markup remains and the weekly links still preserve `work_date`.

### Task 2: Pin Friday effective-minute behavior

**Files:**
- Modify: `tests/Feature/SalesAgendaReceiptWorkflowTest.php`
- Modify: `tests/Feature/SalesAgendaWorkspaceRenderTest.php` only if the visible Friday schedule contract needs an updated assertion.
- Modify: `app/Services/SalesAgendaReceiptService.php` only if a failing regression proves the current schedule contract is not applied at runtime.

**Interfaces:**
- `SalesAgendaReceiptService::schedule(CarbonImmutable $date): array` must return Friday `start_time=07:30`, `end_time=17:00`, `break_start=11:30`, `break_end=13:00`, and `target_minutes=480`.
- `SalesAgendaReceiptService::effectiveMinutes()` must subtract only the overlap with the Friday break.

- [x] **Step 1: Add failing/diagnostic tests** for Friday 07:30–17:00 = 480 effective minutes, Friday 08:00–17:00 = 450 effective minutes, and summary remaining minutes.
- [x] **Step 2: Run the tests to identify whether the issue is calculation or only a stale/misleading displayed start time.**

Run: `php artisan test tests/Feature/SalesAgendaReceiptWorkflowTest.php tests/Feature/SalesAgendaWorkspaceRenderTest.php --filter='Friday|friday|schedule|effective|remaining' --compact`

- [x] **Step 3: Make the smallest runtime correction only if the diagnostic tests fail; otherwise retain the approved 07:30/480 contract and update the render assertion to ensure the UI shows 07:30–17:00.**
- [x] **Step 4: Run the focused receipt and render tests to verify GREEN.**

### Task 3: Preserve receipt-until-submit and submitted list mode

**Files:**
- Modify: `tests/Feature/SalesAgendaWorkspaceRenderTest.php`
- Modify: `resources/views/crm/sales-pocketbook/partials/agenda-receipt.blade.php` only if the regression exposes a mismatch with the approved state transition.

**Interfaces:**
- Unsubmitted days with agendas continue to render `.is-receipt-mode` and the receipt workflow.
- Submitted days render `.sales-submitted-plan`, `Terkirim ke Koordinator`, agenda rows, and no draft submit action.

- [x] **Step 1: Add explicit assertions for both states:** unsubmitted agenda remains receipt mode; submitted agenda renders the list mode shown in the reference image.
- [x] **Step 2: Run the focused render tests to verify the current state contract or expose a regression.**
- [x] **Step 3: Correct only the state condition/markup if the tests fail, without changing submit or mutation rules.**
- [x] **Step 4: Run the complete affected agenda suite.**

Run: `php artisan test tests/Feature/SalesAgendaReceiptAuthorizationTest.php tests/Feature/SalesAgendaReceiptWorkflowTest.php tests/Feature/SalesAgendaWorkspaceInteractionMarkupTest.php tests/Feature/SalesAgendaWorkspaceRenderTest.php tests/Feature/SalesPocketbookTest.php --compact`

### Final verification

- [x] Run `php artisan optimize:clear` and `php artisan view:cache`.
- [x] Run `vendor/bin/pint --dirty --format agent`.
- [x] Run `npm run build`.
- [x] Run `git diff --check`.
- [x] Run `graphify update .`.
- [x] Verify the page at desktop and mobile sizes when authenticated browser access is available; report the login limitation otherwise. Browser reached `/login`; authenticated visual verification was unavailable.
