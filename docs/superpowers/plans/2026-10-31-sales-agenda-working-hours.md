# Sales Agenda Working Hours Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Apply the approved weekday working-hours schedule and break-aware effective-minute rules to Sales Agenda receipts, slot defaults, submission validation, and visual status without changing the agenda schema.

**Architecture:** Keep `SalesAgendaReceiptService` as the backend source of truth for schedules, effective minutes, capacity, slot validation, and submission. Keep `sales-agenda-slot.js` as a pure browser helper using the same schedule and effective-minute rules. Derive break status from stored date/start/end values at render time; raw `duration_minutes` remains unchanged.

**Tech Stack:** Laravel 13, PHP 8.3, PHPUnit feature tests, Blade, Alpine.js, Vite, Node built-in test runner, existing OASIS CSS tokens/components.

**Spec:** `docs/superpowers/specs/2026-10-31-sales-agenda-working-hours-design.md`

## Global Constraints

- Senin–Kamis: 08.00–17.00 dengan istirahat 12.00–13.00.
- Jumat: 07.30–17.00 dengan istirahat 11.30–13.00.
- Target efektif hari kerja dihitung dari jam kerja di luar istirahat: 480 menit.
- Durasi mentah agenda tetap disimpan seperti saat ini; menit efektif dihitung dari irisan waktu dengan jam istirahat.
- Agenda yang beririsan dengan jam istirahat tetap tersimpan dan diberi label/warna khusus.
- Sabtu–Minggu tetap tanpa target durasi; agenda tetap dapat dibuat dengan waktu fleksibel.
- Tidak mengubah route, permission, authorization, atau schema database agenda.
- Existing authorization, route, and desktop/mobile modal flow tetap berjalan.

## Review Focus

- Agenda yang seluruhnya berada dalam jam istirahat harus tersimpan sebagai agenda normal, berlabel khusus, dan menyumbang 0 menit efektif — covered by Task 1 and Task 4 tests.
- Agenda yang melintasi batas istirahat harus mengurangi hanya interval istirahat, termasuk Jumat 11.30–13.30 — covered by Task 1 and Task 3 tests.
- Default slot setelah agenda berakhir tepat di awal atau di tengah istirahat harus berpindah ke akhir istirahat, sementara start eksplisit di jam istirahat tetap dihormati — covered by Task 3 tests.
- Agenda valid sebelum jam kerja atau setelah jam kerja tetap mengikuti aturan `is_outside_schedule` yang ada dan tidak mengubah target normal — covered by Task 2 tests.
- Existing/cancelled/rescheduled/outside agendas must not corrupt effective-minute totals or latest valid slot defaults — covered by Task 1 and Task 3 tests.

---

### Task 1: Backend schedule and effective-minute rules

**Files:**
- Modify: `app/Models/SalesAgendaDaySubmission.php`
- Modify: `app/Services/SalesAgendaReceiptService.php`
- Test: `tests/Feature/SalesAgendaReceiptWorkflowTest.php`

**Interfaces:**
- Consumes: existing `ContentItem` agenda fields `scheduled_date`, `start_time`, `end_time`, `duration_minutes`, `status`, and `is_outside_schedule`.
- Produces: `SalesAgendaReceiptService::schedule()` with the new weekday metadata; `effectiveMinutes(CarbonImmutable $date, ?string $startTime, ?string $endTime): int`; `isBreakAgenda(CarbonImmutable $date, ?string $startTime, ?string $endTime): bool`; summaries, capacity checks, and submissions based on effective minutes.

- [ ] **Step 1: Write the failing feature tests**

  Add tests for Monday–Thursday schedule `08:00–17:00`, Friday schedule `07:30–17:00`, target `480`, effective minutes for `12:00–13:00` equal to `0`, effective minutes for Friday `11:30–13:30` equal to `30` under the explicit `11:30–13:00` break, a `11:00–13:30` interval equal to `60`, summaries excluding cancelled/rescheduled/outside rows while subtracting breaks, and exact `480` effective minutes being required for submission.

- [ ] **Step 2: Run the focused tests to verify the new assertions fail**

  Run: `php artisan test --compact tests/Feature/SalesAgendaReceiptWorkflowTest.php`

  Expected: FAIL because the current schedule is `09:00`/`08:30`, target is `420`, break agendas are rejected, and summaries use raw duration minutes.

- [ ] **Step 3: Implement the backend rules**

  Change `SalesAgendaDaySubmission::TARGET_MINUTES` to `480`. Update `SalesAgendaReceiptService::schedule()` to return the approved schedule. Add typed effective-minute and break-status methods that calculate interval overlap without changing stored raw duration. Let in-schedule break-overlapping slots pass availability checks, retain overlap and work-boundary checks, and make `summarize()`, `canAddNormal()`, and `submit()` use effective minutes. Keep weekend target/submission behavior unchanged.

- [ ] **Step 4: Run the focused tests to verify they pass**

  Run: `php artisan test --compact tests/Feature/SalesAgendaReceiptWorkflowTest.php`

  Expected: PASS, including the existing weekend and deferral coverage.

- [ ] **Step 5: Commit the backend slice**

  ```bash
  git add app/Models/SalesAgendaDaySubmission.php app/Services/SalesAgendaReceiptService.php tests/Feature/SalesAgendaReceiptWorkflowTest.php
  git commit -m "feat: apply break-aware sales agenda working hours"
  ```

### Task 2: Store/submit flow and authorization regression coverage

**Files:**
- Modify: `app/Http/Controllers/Crm/SalesAgendaController.php`
- Modify: `tests/Feature/SalesAgendaReceiptAuthorizationTest.php`

**Interfaces:**
- Consumes: Task 1 `SalesAgendaReceiptService::effectiveMinutes()` and effective-minute capacity behavior.
- Produces: unchanged routes and authorization with controller validation that passes candidate raw and effective minutes to `canAddNormal(User $owner, CarbonImmutable $date, int $durationMinutes, ?int $effectiveMinutes = null)` and permits intentional in-schedule break agendas.

- [ ] **Step 1: Write the failing authorization/HTTP tests**

  Update the old break-rejection test to assert a `12:00–13:00` weekday agenda is accepted, assert a Friday `11:30–13:30` agenda stores raw duration `120` and `is_outside_schedule=false`, and assert the normal submission/capacity messages use `480` where relevant. Add a regression that an after-submit additional agenda still remains additional.

- [ ] **Step 2: Run the focused authorization tests to verify failure**

  Run: `php artisan test --compact tests/Feature/SalesAgendaReceiptAuthorizationTest.php`

  Expected: FAIL because the controller/service currently rejects break overlap and still references the `420` target behavior.

- [ ] **Step 3: Update the controller integration**

  Pass the candidate start/end interval to the service’s effective-minute capacity calculation, update user-facing remaining-minute messages to use the schedule target, and preserve the submitted-day additional-agenda branch and all existing authorization checks.

- [ ] **Step 4: Run the focused authorization and workflow tests**

  Run: `php artisan test --compact tests/Feature/SalesAgendaReceiptAuthorizationTest.php tests/Feature/SalesAgendaReceiptWorkflowTest.php`

  Expected: PASS with break agendas accepted and all existing authorization assertions intact.

- [ ] **Step 5: Commit the controller slice**

  ```bash
  git add app/Http/Controllers/Crm/SalesAgendaController.php tests/Feature/SalesAgendaReceiptAuthorizationTest.php
  git commit -m "feat: allow intentional break-time agenda slots"
  ```

### Task 3: Browser slot helper and automatic defaults

**Files:**
- Modify: `resources/js/sales-agenda-slot.js`
- Test: `tests/frontend/sales-agenda-slot.test.js`

**Interfaces:**
- Consumes: Task 1 schedule contract values and agenda objects already serialized by the Blade form.
- Produces: pure `scheduleForDate()` and `calculateAgendaSlot()` behavior with target `480`, break-aware effective-minute totals, explicit break-time starts, and automatic break-skipping defaults.

- [ ] **Step 1: Write the failing Node tests**

  Update weekday/Friday expected start and target values. Add tests for empty Monday starting `08:00`, empty Friday starting `07:30`, defaulting from an agenda ending `11:30` to `13:00`, explicit `12:00–13:00` acceptance with `remainingMinutes` unchanged, Friday `11:30–13:30` returning `30` effective minutes under the explicit break, Friday `11:00–13:30` returning `60`, and a default slot that does not begin inside the break.

- [ ] **Step 2: Run the Node tests to verify failure**

  Run: `node --test tests/frontend/sales-agenda-slot.test.js`

  Expected: FAIL against the old `420`/`09:00`/`08:30` and break-rejection implementation.

- [ ] **Step 3: Implement the pure helper changes**

  Set the schedule constants to the approved values. Add interval-overlap helpers for effective minutes. Distinguish automatic starts from explicit `requestedStart`: automatic starts normalize to the end of a break when needed, while explicit break-time starts remain valid. Allow intervals crossing or occupying the break as long as they remain within working bounds, and calculate remaining target minutes from effective rather than raw duration.

- [ ] **Step 4: Run the Node tests to verify pass**

  Run: `node --test tests/frontend/sales-agenda-slot.test.js`

  Expected: PASS with all slot/default/weekend tests.

- [ ] **Step 5: Commit the browser helper slice**

  ```bash
  git add resources/js/sales-agenda-slot.js tests/frontend/sales-agenda-slot.test.js
  git commit -m "feat: make agenda slots break aware"
  ```

### Task 4: Receipt rendering, status styling, changelog, and verification

**Files:**
- Modify: `resources/views/crm/sales-pocketbook/partials/agenda-receipt.blade.php`
- Modify: `resources/views/crm/sales-pocketbook/partials/agenda-receipt-canvas.blade.php`
- Modify: `resources/css/app.css`
- Modify: `tests/Feature/SalesAgendaWorkspaceRenderTest.php`
- Create: `database/migrations/2026_10_31_230000_add_sales_agenda_working_hours_changelog.php`

**Interfaces:**
- Consumes: Task 1 summary `normal_minutes`, `target_minutes`, schedule metadata, and `SalesAgendaReceiptService::isBreakAgenda()` semantics; Task 3 serialized schedule shape.
- Produces: receipt and submitted-list labels/classes for break-overlapping agendas, target copy showing `480 MENIT`, updated empty/submit guidance, and one idempotent Indonesian Oasis Changelog entry.

- [ ] **Step 1: Write the failing render tests**

  Assert the weekday receipt renders `480 MENIT`, no longer renders `420` guidance, a break agenda has a visible “Agenda jam istirahat” label/class, and weekend rendering still omits the weekday target meter. Assert the schedule summary shows the approved Monday/Friday hours.

- [ ] **Step 2: Run the focused render tests to verify failure**

  Run: `php artisan test --compact tests/Feature/SalesAgendaWorkspaceRenderTest.php`

  Expected: FAIL because the current Blade copy is `420` and break rows have no agenda-specific status.

- [ ] **Step 3: Implement the visual/status and changelog changes**

  Derive break status from agenda date/start/end in Blade, add text plus a semantic class so color is not the only signal, use the existing OASIS break token for a restrained distinct treatment, update receipt guidance to the dynamic `target_minutes`, and add an idempotent migration using `updateOrInsert` with `version` null and an Indonesian user-facing title/description. Do not add agenda columns or alter routes.

- [ ] **Step 4: Run render tests, formatting, build, and whitespace checks**

  Run:
  - `php artisan optimize:clear`
  - `php artisan view:cache`
  - `php artisan test --compact tests/Feature/SalesAgendaWorkspaceRenderTest.php tests/Feature/SalesAgendaReceiptWorkflowTest.php tests/Feature/SalesAgendaReceiptAuthorizationTest.php`
  - `vendor/bin/pint --dirty --format agent`
  - `npm run build`
  - `git diff --check`

  Expected: all focused tests pass, Pint exits successfully, Vite builds successfully, and diff check reports no whitespace errors.

- [ ] **Step 5: Verify the browser at mobile and desktop sizes**

  Reuse the project’s existing preview command/session. Inspect the agenda receipt and add-agenda bottom sheet at approximately 390×844 and 1440×900. Confirm target `480`, break label/color, automatic slot defaults, explicit break-time entry, no console errors, and no mobile overflow.

- [ ] **Step 6: Commit the UI/changelog slice**

  ```bash
  git add resources/views/crm/sales-pocketbook/partials/agenda-receipt.blade.php resources/views/crm/sales-pocketbook/partials/agenda-receipt-canvas.blade.php resources/css/app.css tests/Feature/SalesAgendaWorkspaceRenderTest.php database/migrations/2026_10_31_230000_add_sales_agenda_working_hours_changelog.php
  git commit -m "feat: show break-aware agenda receipt status"
  ```
