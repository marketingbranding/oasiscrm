# Sales Agenda Weekly Date Navigation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Sales Agenda Kemarin/Hari ini/Rencana navigation with a Monday–Sunday weekly date strip while preserving the existing single-day receipt and agenda workflow.

**Architecture:** Keep `work_date=Y-m-d` as the canonical selected-day URL state. Add a focused `SalesAgendaWeekNavigationService` that derives the Monday–Sunday range and scoped per-day indicators, then render that data through a dedicated Blade partial. The existing CRM date picker will select one anchor date; server-side logic derives the containing Monday-start week.

**Tech Stack:** Laravel 13, PHP 8.3, CarbonImmutable, Blade, existing CRM date picker, Tailwind/PostCSS CSS, PHPUnit, Node built-in test runner, Playwright/browser verification.

**Spec:** `docs/superpowers/designs/2026-11-02-sales-agenda-weekly-date-navigation.md`

## Global Constraints

- Preserve the current OASIS receipt, toolbar, form, slot calculation, agenda actions, colors, typography, and compact workstation identity.
- Use `work_date=Y-m-d` as the canonical selected-day URL parameter; keep old `work_date` links valid.
- The displayed week always starts Monday and ends Sunday.
- The calendar chooses one anchor date; it must not introduce a free-form `from`/`to` range.
- Use the existing CRM date picker; do not add a calendar dependency.
- Do not change agenda creation, edit, delete, complete, reschedule, evidence, or submit rules.
- Keep branch/project/permission scope identical to the current agenda query.
- Status must not be communicated by color alone; use text, icon, or accessible labels.
- Add one idempotent Indonesian Oasis Changelog migration for the user-visible navigation change.
- Do not deploy to production.

## Review Focus

- Monday boundary: a selected Sunday must render the Monday six days earlier through Sunday, not a Sunday-starting range; test in `SalesAgendaWeekNavigationServiceTest`.
- Selected-day continuity: clicking a date and using the calendar must keep that date as `agendaDate` and preserve `work_date`; test in `SalesAgendaWorkspaceRenderTest`.
- Empty-day semantics: a date with no active agenda must show the empty-plan indicator, while cancelled/rescheduled-only records must not falsely count as an active plan; test in service/render coverage.
- Unfinished indicator: done, cancelled, and rescheduled agendas must not produce `!`; planned/confirmed agendas must; test in service coverage.
- Organization scope: indicators must not leak agendas from inaccessible branches/projects; test through an authorized and inaccessible fixture.

---

### Task 1: Define the Monday-start week navigation data contract

**Files:**
- Create: `app/Services/SalesAgendaWeekNavigationService.php`
- Create: `tests/Unit/SalesAgendaWeekNavigationServiceTest.php`
- Modify: `app/Http/Controllers/Crm/SalesAgendaController.php`
- Modify: `tests/Feature/SalesAgendaWorkspaceRenderTest.php`

**Interfaces:**
- Produces `SalesAgendaWeekNavigationService::for(User $owner, CarbonImmutable $selectedDate): array` with `start`, `end`, `previous_date`, `next_date`, and seven `days` entries.
- Each day entry contains `date: CarbonImmutable`, `day_label: string`, `date_label: string`, `has_plan: bool`, `has_incomplete_agenda: bool`, and `incomplete_count: int`.
- Controller passes the result as `agendaWeek` to `crm.sales-pocketbook.sales-agenda`.

- [ ] **Step 1: Write failing service tests** for Monday-start calculation, Sunday selection, active-plan detection, unfinished status detection, and scoped branch/project data.
- [ ] **Step 2: Run the service tests to verify RED.**

Run: `php artisan test tests/Unit/SalesAgendaWeekNavigationServiceTest.php --compact`

Expected: FAIL because the service and contract do not exist.

- [ ] **Step 3: Implement `SalesAgendaWeekNavigationService::for()`** using `startOfWeek(CarbonImmutable::MONDAY)` and one scoped agenda query for the selected owner's visible branch/project records. Treat `cancelled` and `rescheduled` as inactive for `has_plan`; treat every status except `done`, `cancelled`, and `rescheduled` as incomplete.
- [ ] **Step 4: Update `SalesAgendaController::index()`** to resolve `work_date` first, accept `plan_date` only as a backward-compatible alias without the old tomorrow-only restriction, and pass `agendaWeek` to the view. Keep the existing `agendaDate` and all receipt queries unchanged.
- [ ] **Step 5: Run the service and focused render tests to verify GREEN.**

Run: `php artisan test tests/Unit/SalesAgendaWeekNavigationServiceTest.php tests/Feature/SalesAgendaWorkspaceRenderTest.php --compact`

Expected: PASS for the new week contract and existing receipt rendering.

### Task 2: Replace the three context links with the weekly strip

**Files:**
- Create: `resources/views/crm/sales-pocketbook/partials/agenda-week-navigation.blade.php`
- Modify: `resources/views/crm/sales-pocketbook/partials/agenda-receipt.blade.php`
- Modify: `tests/Feature/SalesAgendaWorkspaceRenderTest.php`
- Modify: `tests/Feature/SalesAgendaWorkspaceInteractionMarkupTest.php`

**Interfaces:**
- Consumes the controller's `agendaWeek` array and current `agendaDate`/`agendaView` values.
- Produces links with `tab=agenda&work_date=Y-m-d` for each day, previous/next week controls, and a calendar form using the existing CRM date field.

- [ ] **Step 1: Add failing markup tests** asserting seven Monday–Sunday date controls, active selected date, previous/next week URLs, calendar control, and removal of the old Kemarin/Hari ini/Rencana navigation labels.
- [ ] **Step 2: Run the focused markup tests to verify RED.**

Run: `php artisan test tests/Feature/SalesAgendaWorkspaceRenderTest.php tests/Feature/SalesAgendaWorkspaceInteractionMarkupTest.php --filter='week|navigation|date_picker' --compact`

Expected: FAIL because the existing view still renders the three old context links.

- [ ] **Step 3: Implement `agenda-week-navigation.blade.php`** with a Monday-to-Sunday row, accessible day links, active-date treatment, previous/next week controls, and a calendar form whose date input submits `work_date`.
- [ ] **Step 4: Include the new partial from `agenda-receipt.blade.php`** and remove only the old three-link/date-picker navigation block; preserve receipt mode, all-agenda mode, and the rest of the agenda markup.
- [ ] **Step 5: Run the focused markup/render tests to verify GREEN.**

### Task 3: Render empty and unfinished indicators in the weekly strip

**Files:**
- Modify: `resources/views/crm/sales-pocketbook/partials/agenda-week-navigation.blade.php`
- Modify: `resources/views/crm/sales-pocketbook/partials/agenda-receipt.blade.php`
- Modify: `resources/css/app.css`
- Modify: `tests/Feature/SalesAgendaWorkspaceRenderTest.php`

- [ ] **Step 1: Add failing assertions** for the box-style `Belum ada rencana` indicator on an empty date and the `!` indicator on a date with an unfinished agenda. Assert that completed/cancelled/rescheduled dates do not show the unfinished marker.
- [ ] **Step 2: Run the focused render tests to verify RED.**
- [ ] **Step 3: Render the indicators** from `agendaWeek.days`: a compact box mark plus accessible label for `has_plan=false`, and a visible `!` plus accessible label for `has_incomplete_agenda=true`. Keep the existing detailed empty state in the selected-day receipt.
- [ ] **Step 4: Add responsive OASIS CSS** for the weekly strip, selected date, box mark, warning mark, previous/next controls, and calendar control. Preserve the current receipt styles; use a horizontally scrollable strip only where the viewport cannot fit all seven targets at 44px.
- [ ] **Step 5: Run focused PHP tests and `npm run build` to verify GREEN and compile the new CSS/markup.**

### Task 4: Preserve date/form compatibility and remove the obsolete future-only path

**Files:**
- Modify: `app/Http/Controllers/Crm/SalesAgendaController.php`
- Modify: `resources/views/crm/sales-pocketbook/partials/agenda-receipt-canvas.blade.php`
- Modify: `tests/Feature/SalesAgendaWorkspaceRenderTest.php`
- Modify: `tests/Feature/SalesAgendaReceiptAuthorizationTest.php`

- [ ] **Step 1: Update regression tests** so past, today, tomorrow, and later dates all resolve through `work_date`; keep a `plan_date` alias test only if backward compatibility is retained.
- [ ] **Step 2: Verify the failing/changed tests identify any remaining future-only `plan_date` behavior.**
- [ ] **Step 3: Normalize legacy `plan_date` to the canonical selected date** and remove the old minimum-tomorrow validation that conflicts with weekly historical navigation. Ensure receipt headings, hidden `scheduled_date`, add/edit/delete redirects, and submit URLs all use the selected `agendaDate`.
- [ ] **Step 4: Keep the existing receipt heading behavior** (`RENCANA HARI INI`, `RENCANA BESOK`, and date-specific headings) while ensuring the weekly strip is the only replacement for the old context navigation.
- [ ] **Step 5: Run the complete affected agenda suite.**

Run: `php artisan test tests/Feature/SalesAgendaReceiptAuthorizationTest.php tests/Feature/SalesAgendaWorkspaceInteractionMarkupTest.php tests/Feature/SalesAgendaWorkspaceRenderTest.php tests/Feature/SalesPocketbookTest.php --compact`

Expected: PASS with no changes to agenda mutation behavior.

### Task 5: Record the change and verify desktop/mobile behavior

**Files:**
- Create: `database/migrations/2026_11_02_*.php`
- Modify: `tests/Feature/SalesAgendaWorkspaceRenderTest.php`
- Modify: `docs/superpowers/plans/2026-11-02-sales-agenda-weekly-date-navigation.md`

- [ ] **Step 1: Add a changelog migration** using `DB::table('changelogs')->updateOrInsert(...)` with a stable null-version/title identity, Indonesian title/description, category `changed`, and a precise `down()` delete.
- [ ] **Step 2: Add an idempotency/render test** proving exactly one changelog row exists after two `up()` calls and the Changelog page displays it.
- [ ] **Step 3: Run migrations, clear/cache Blade, and run formatting/build checks.**

Run: `php artisan migrate --force`

Run: `php artisan optimize:clear; php artisan view:cache`

Run: `vendor/bin/pint --dirty --format agent`

Run: `npm run build`

Run: `git diff --check`

- [ ] **Step 4: Verify the rendered page in a real browser** at approximately 1440x900 and 390x844. Check Monday–Sunday order, active day, previous/next week, calendar selection, empty box indicator, unfinished `!`, mobile scrolling/touch targets, receipt/form preservation, and browser console errors.
- [ ] **Step 5: Run the affected PHP and Node suites, then the full suite where practical.** Report pre-existing repository errors separately and do not claim them as caused by this feature.
