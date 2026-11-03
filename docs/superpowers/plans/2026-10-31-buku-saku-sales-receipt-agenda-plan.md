# Buku Saku Sales Receipt Agenda Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the primary Sales Buku Saku Agenda and Lead Saya screens to match the supplied mobile references, adding a 420-minute receipt workflow, timed Agenda creation, next-day deferral, and post-submission extra Agendas without changing existing organization or permission boundaries.

**Architecture:** Keep the existing Sales Agenda route and Laravel/Blade/Alpine stack. Move the presentation into focused Blade partials, centralize receipt-day calculations and submission/deferral rules in a small service, and add only the persistence needed to distinguish a submitted owner/day and an Agenda created outside that submitted schedule. Preserve existing evidence, lead, scope, and authorization services.

**Tech Stack:** Laravel 13, PHP 8.3, Blade, Alpine.js, existing OASIS CSS tokens/components, Vite, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-10-31-buku-saku-sales-receipt-agenda-design.md`

## Global Constraints

- Preserve OASIS domain terminology and Indonesian labels.
- Do not add a frontend framework, UI library, date/time library, or second CSS architecture.
- Keep existing named routes, permission middleware, branch/project scope, ownership checks, evidence authorization, and lead validation unless a focused test proves the Agenda workflow needs a separate execution/schedule distinction.
- New Agenda creation requires both `start_time` and `end_time`; existing untimed records remain readable as `Waktu fleksibel`.
- The daily normal Agenda target is fixed at exactly `420` minutes; extra post-submission Agendas do not contribute to that total.
- Submitted planned fields are immutable to the Sales owner, but completion/result/evidence actions remain available.
- All new dialogs/bottom sheets must be keyboard-operable, Escape-closeable, labelled, focus-managed, viewport-safe, and usable without outside-click-only dismissal.
- Use existing CRM date/time picker behavior and shared toast/alert/conflict patterns.
- Do not deploy or alter production during implementation.

## Review Focus

1. **Legacy untimed Agenda:** It must render safely as `Waktu fleksibel` without entering the new 420-minute total; pin this in the workspace/render tests in Task 4.
2. **Exact capacity boundaries:** 419 minutes permits a normal Agenda up to the remaining capacity; 420 enables submission; a normal addition that exceeds 420 is rejected; pin this in Task 2 and Task 3 tests.
3. **Submitted execution versus schedule editing:** Submitted rows reject schedule changes but allow completion, result entry, and evidence upload; pin this in Task 3 authorization tests.
4. **Outside-schedule additions:** After submission, a new Agenda is persisted as additional/green and excluded from normal progress; pin this in Task 2 and Task 3 tests.
5. **Linked deferral:** Deferring preserves the original and creates exactly one next-day linked copy without copying evidence or falsely completing the original; pin this in Task 2 tests.

---

### Task 1: Add the receipt-day persistence contract

**Files:**
- Create: `database/migrations/2026_10_31_120000_add_sales_agenda_receipt_workflow.php`
- Create: `app/Models/SalesAgendaDaySubmission.php`
- Modify: `app/Models/ContentItem.php`
- Test: `tests/Feature/SalesAgendaReceiptPersistenceTest.php`

**Interfaces:**
- Produces `SalesAgendaDaySubmission` with owner/date uniqueness, `target_minutes`, `normal_minutes_at_submission`, and `submitted_at`.
- Produces `ContentItem::is_outside_schedule` as a boolean attribute.
- The migration must be reversible and preserve existing `content_items` rows.

- [ ] **Step 1: Write failing persistence tests**
  - Assert a submission can be created once for one `owner_user_id` and `scheduled_date`.
  - Assert a duplicate owner/date submission is rejected by the database constraint or model write path.
  - Assert existing ContentItem rows default to `is_outside_schedule = false`.

- [ ] **Step 2: Run the focused test and verify it fails**

  Run: `php artisan test tests/Feature/SalesAgendaReceiptPersistenceTest.php --compact`

  Expected: FAIL because the submission model/table and the ContentItem attribute do not exist.

- [ ] **Step 3: Implement the migration and model**
  - Create the day-submission table with a unique owner/date index, foreign key to users using the repository's established delete behavior, integer target/normal-minute fields, and timestamp fields.
  - Add a non-null boolean `is_outside_schedule` column to `content_items` with a safe default.
  - Add the model relationship/casts/fillable fields and the ContentItem cast.
  - Do not add status values or remove existing columns.

- [ ] **Step 4: Run the focused test and verify it passes**

  Run: `php artisan test tests/Feature/SalesAgendaReceiptPersistenceTest.php --compact`

  Expected: PASS.

- [ ] **Step 5: Commit**

  ```bash
  git add database/migrations/2026_10_31_120000_add_sales_agenda_receipt_workflow.php app/Models/SalesAgendaDaySubmission.php app/Models/ContentItem.php tests/Feature/SalesAgendaReceiptPersistenceTest.php
  git commit -m "feat: persist Sales Agenda receipt days"
  ```

### Task 2: Implement receipt summaries, capacity, submission, and deferral rules

**Files:**
- Create: `app/Services/SalesAgendaReceiptService.php`
- Create: `tests/Feature/SalesAgendaReceiptWorkflowTest.php`
- Modify: `app/Models/ContentItem.php` only if the service needs a focused relationship/helper

**Interfaces:**
- `SalesAgendaReceiptService::summarize(User $owner, CarbonImmutable $date): array` returns `normal_minutes`, `target_minutes`, `remaining_minutes`, `completed_count`, `agenda_count`, `submitted`, and `extra_count`.
- `SalesAgendaReceiptService::canAddNormal(User $owner, CarbonImmutable $date, int $durationMinutes): bool` excludes additional Agendas and rejects additions over 420 or to a submitted day.
- `SalesAgendaReceiptService::submit(User $owner, CarbonImmutable $date): SalesAgendaDaySubmission` requires exactly 420 normal minutes and is idempotent for the same owner/date.
- `SalesAgendaReceiptService::deferToNextDay(User $actor, ContentItem $agenda): ContentItem` marks the original as carried forward and creates one next-day linked copy without evidence.
- `SalesAgendaReceiptService::isScheduleEditable(User $actor, ContentItem $agenda): bool` distinguishes planned-field mutation from execution/evidence actions.

- [ ] **Step 1: Write failing workflow tests**
  - Assert normal totals sum `duration_minutes` while `is_outside_schedule = true` rows are excluded.
  - Assert 419 minutes leaves 1 minute, 420 is submittable, and 421 cannot be produced through normal additions.
  - Assert submission records the fixed target and normal total.
  - Assert a second submission does not create a duplicate day record.
  - Assert deferral preserves the original, sets the existing carried-forward relationship/status, creates one next-day copy, and copies no evidence.

- [ ] **Step 2: Run the focused test and verify it fails**

  Run: `php artisan test tests/Feature/SalesAgendaReceiptWorkflowTest.php --compact`

  Expected: FAIL because the service and workflow rules do not exist.

- [ ] **Step 3: Implement `SalesAgendaReceiptService`**
  - Query only owner-scoped Sales Agendas with the canonical `agenda_type` and `item_type` constraints.
  - Treat `420` as a service constant, not a browser-provided value.
  - Sum only normal, non-cancelled/non-carried-forward rows for progress; keep completed rows in the total.
  - Detect submission through the owner/date model, never through a single Agenda row.
  - Mark post-submission creates as `is_outside_schedule = true` in the trusted server path.
  - Use the existing `rescheduled_from_id` relationship for the next-day copy and do not copy evidence rows.

- [ ] **Step 4: Run the focused test and verify it passes**

  Run: `php artisan test tests/Feature/SalesAgendaReceiptWorkflowTest.php --compact`

  Expected: PASS.

- [ ] **Step 5: Commit**

  ```bash
  git add app/Services/SalesAgendaReceiptService.php tests/Feature/SalesAgendaReceiptWorkflowTest.php
  git commit -m "feat: add Sales Agenda receipt rules"
  ```

### Task 3: Wire secure Agenda writes and execution permissions

**Files:**
- Modify: `app/Http/Requests/Crm/StoreSalesAgendaRequest.php`
- Modify: `app/Http/Requests/Crm/RescheduleSalesAgendaRequest.php`
- Modify: `app/Http/Controllers/Crm/SalesAgendaController.php`
- Modify: `app/Services/SalesAgendaEvidenceAuthorizationService.php`
- Modify: `app/Policies/SalesAgendaPolicy.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/SalesAgendaReceiptAuthorizationTest.php`

**Interfaces:**
- Existing `sales-agendas.store` remains the normal create route.
- Add `POST /buku-saku-sales/agendas/submit` as `sales-agendas.submit`, protected by `sales_pocketbook.manage_own`.
- Keep `sales-agendas.reschedule` as the named next-day deferral endpoint, but constrain the action to the next day and the authorized owner.
- Existing `sales-agendas.update` remains the completion/result endpoint.
- Evidence upload remains `sales-agendas.evidence.store`; submitted rows may still accept authorized evidence.

- [ ] **Step 1: Write failing request/controller authorization tests**
  - Missing start or end time returns 422 with Indonesian validation text.
  - End time before/equal to start returns 422.
  - An owner can complete a submitted Agenda and upload evidence.
  - An owner cannot change submitted schedule fields or add a normal Agenda after submission.
  - An owner can add a post-submission additional Agenda through the normal user flow and it is marked outside schedule.
  - An unrelated Sales user, inactive user, or no-permission user cannot submit, defer, complete, or upload.
  - A coordinator/monitoring viewer can view the submitted receipt according to existing scope but cannot use the Sales owner's write actions.

- [ ] **Step 2: Run the focused test and verify it fails**

  Run: `php artisan test tests/Feature/SalesAgendaReceiptAuthorizationTest.php --compact`

  Expected: FAIL because time requirements, submission, deferral, and submitted execution distinctions are not wired.

- [ ] **Step 3: Implement the write boundaries**
  - Make `start_time` and `end_time` required for new Sales Agenda requests; retain server-side `H:i` and end-after-start validation.
  - Calculate authoritative duration on the server and call the receipt service before creating a normal Agenda.
  - When the day is already submitted, classify a newly created Agenda as outside-schedule rather than allowing it to alter normal capacity.
  - Add the submit action with owner/date scope and exact-420 validation.
  - Complete the existing reschedule controller path as next-day deferral; reject arbitrary date changes from this Sales workflow.
  - Separate schedule-field mutation checks from completion/result/evidence checks so submitted execution remains valid.
  - Reuse existing `WorkspaceAccessService`, policy, and evidence authorization rules rather than adding role shortcuts.

- [ ] **Step 4: Run the focused test and verify it passes**

  Run: `php artisan test tests/Feature/SalesAgendaReceiptAuthorizationTest.php --compact`

  Expected: PASS.

- [ ] **Step 5: Commit**

  ```bash
  git add app/Http/Requests/Crm/StoreSalesAgendaRequest.php app/Http/Requests/Crm/RescheduleSalesAgendaRequest.php app/Http/Controllers/Crm/SalesAgendaController.php app/Services/SalesAgendaEvidenceAuthorizationService.php app/Policies/SalesAgendaPolicy.php routes/web.php tests/Feature/SalesAgendaReceiptAuthorizationTest.php
  git commit -m "feat: secure Sales Agenda receipt actions"
  ```

### Task 4: Compose the Agenda workspace states and split the Blade presentation

**Files:**
- Modify: `app/Http/Controllers/Crm/SalesAgendaController.php`
- Modify: `resources/views/crm/sales-pocketbook/sales-agenda.blade.php`
- Create: `resources/views/crm/sales-pocketbook/partials/buku-saku-header.blade.php`
- Create: `resources/views/crm/sales-pocketbook/partials/agenda-day-navigation.blade.php`
- Create: `resources/views/crm/sales-pocketbook/partials/agenda-receipt.blade.php`
- Create: `resources/views/crm/sales-pocketbook/partials/agenda-row.blade.php`
- Create: `resources/views/crm/sales-pocketbook/partials/agenda-bottom-sheet.blade.php`
- Create: `resources/views/crm/sales-pocketbook/partials/lead-saya.blade.php`
- Test: `tests/Feature/SalesAgendaWorkspaceRenderTest.php`

**Interfaces:**
- `SalesAgendaController::index` supplies the existing lead data plus `receiptSummary`, `daySubmission`, `incompletePreviousAgendas`, and the existing `agendaGroups`.
- Partials receive explicit variables and do not query models or invent authorization decisions in Blade.

- [ ] **Step 1: Write failing render/source-contract tests**
  - Assert the page exposes only Agenda and Lead Saya primary tabs.
  - Assert the empty state renders 0/420 and the sticky add action.
  - Assert a populated state renders chronological time, duration, category, status alias, and progress.
  - Assert previous incomplete work renders a warning link.
  - Assert submitted state renders the coordinator action/state and additional-work affordance.
  - Assert Lead Saya renders open/completed counts, card identity fields, and sticky add action.

- [ ] **Step 2: Run the focused test and verify it fails**

  Run: `php artisan test tests/Feature/SalesAgendaWorkspaceRenderTest.php --compact`

  Expected: FAIL because the new receipt structure and state variables are not present.

- [ ] **Step 3: Implement server composition and partials**
  - Keep route/query/filter/pagination behavior intact.
  - Build the fixed-target summary through `SalesAgendaReceiptService`.
  - Render date contexts as `Kemarin`, `Hari ini`, and `Rencana` using the existing future-date semantics.
  - Render gaps/breaks as derived rows only; never create fake ContentItems.
  - Keep Lead Saya fields, counts, pagination, duplicate warning, and detail/edit links intact while moving markup into its partial.
  - Pass errors and old input through the normal Laravel view flow.

- [ ] **Step 4: Run the focused test and verify it passes**

  Run: `php artisan test tests/Feature/SalesAgendaWorkspaceRenderTest.php --compact`

  Expected: PASS.

- [ ] **Step 5: Commit**

  ```bash
  git add app/Http/Controllers/Crm/SalesAgendaController.php resources/views/crm/sales-pocketbook/sales-agenda.blade.php resources/views/crm/sales-pocketbook/partials tests/Feature/SalesAgendaWorkspaceRenderTest.php
  git commit -m "feat: render Sales Agenda receipt workspace"
  ```

### Task 5: Implement the mobile bottom sheets and client-side presentation behavior

**Files:**
- Create: `resources/js/sales-agenda-workspace.js`
- Modify: `resources/js/app.js`
- Modify: `resources/js/sales-agenda-paper.js`
- Modify: `resources/views/crm/sales-pocketbook/partials/agenda-bottom-sheet.blade.php`
- Modify: `resources/views/crm/sales-pocketbook/partials/lead-saya.blade.php`
- Test: `tests/Feature/SalesAgendaWorkspaceInteractionMarkupTest.php`

**Interfaces:**
- Register one Alpine data component, `salesAgendaWorkspace`, for open/close/focus state and duration preview.
- The component exposes `openAgendaSheet()`, `openLeadSheet()`, `closeSheet()`, and `previewEndTime()` behavior through declarative markup.
- No client-side authorization or final capacity decision is trusted; the server remains authoritative.

- [ ] **Step 1: Write failing interaction-contract tests**
  - Assert the Agenda and Lead buttons have labelled controls and sheet targets.
  - Assert the Agenda sheet includes category chips, title, location, start time, duration choices, derived end-time output, and submit action.
  - Assert modal attributes include `role="dialog"`, `aria-modal`, labelled heading, `x-cloak`, and Escape/close hooks.
  - Assert the form posts named fields expected by `StoreSalesAgendaRequest`.

- [ ] **Step 2: Run the focused test and verify it fails**

  Run: `php artisan test tests/Feature/SalesAgendaWorkspaceInteractionMarkupTest.php --compact`

  Expected: FAIL because the bottom-sheet contract and workspace Alpine component do not exist.

- [ ] **Step 3: Implement the Alpine behavior**
  - Reuse existing picker positioning/time-picker modules.
  - Track the previously focused trigger and restore focus on close.
  - Close on Escape and explicit close/cancel controls; trap focus within the active sheet without relying on unsupported `x-trap` behavior.
  - Update the derived end time and remaining minutes when start/duration changes.
  - Preserve the existing reduced-motion handling for Agenda completion animations.

- [ ] **Step 4: Run the focused test and verify it passes**

  Run: `php artisan test tests/Feature/SalesAgendaWorkspaceInteractionMarkupTest.php --compact`

  Expected: PASS.

- [ ] **Step 5: Commit**

  ```bash
  git add resources/js/sales-agenda-workspace.js resources/js/app.js resources/js/sales-agenda-paper.js resources/views/crm/sales-pocketbook/partials/agenda-bottom-sheet.blade.php resources/views/crm/sales-pocketbook/partials/lead-saya.blade.php tests/Feature/SalesAgendaWorkspaceInteractionMarkupTest.php
  git commit -m "feat: add Buku Saku Sales bottom sheets"
  ```

### Task 6: Apply the reference-led responsive visual system

**Files:**
- Modify: `resources/css/app.css`
- Modify: `resources/views/crm/sales-pocketbook/partials/buku-saku-header.blade.php`
- Modify: `resources/views/crm/sales-pocketbook/partials/agenda-receipt.blade.php`
- Modify: `resources/views/crm/sales-pocketbook/partials/agenda-row.blade.php`
- Modify: `resources/views/crm/sales-pocketbook/partials/lead-saya.blade.php`
- Modify: `resources/views/crm/sales-pocketbook/partials/agenda-bottom-sheet.blade.php`

- [ ] **Step 1: Add scoped tokens and layout rules**
  - Use existing OASIS tokens for cream/page surfaces, black structural borders, yellow action state, semantic green/gray states, typography, 4px spacing, and shell dimensions.
  - Add scoped receipt, timeline row, lead card, sticky action, and bottom-sheet classes; do not add arbitrary inline-style duplication.
  - Keep wide content readable on desktop while making 390px the reference composition.

- [ ] **Step 2: Implement states and responsive rules**
  - Empty/partial/full/submitted/additional/previous-warning states must remain distinguishable by text and structure, not color alone.
  - Use a receipt-like bordered surface for normal Agenda rows and a clear green treatment for additional work.
  - Ensure sticky actions reserve bottom padding and never cover content.
  - Preserve horizontal navigation usability, visible focus, 44px touch targets, and reduced-motion behavior.

- [ ] **Step 3: Run formatting/build checks**

  Run: `npm run build`

  Expected: Vite exits successfully and produces the updated manifest.

- [ ] **Step 4: Commit**

  ```bash
  git add resources/css/app.css resources/views/crm/sales-pocketbook/partials
  git commit -m "feat: style Buku Saku Sales to receipt references"
  ```

### Task 7: Complete verification and release readiness

**Files:**
- Modify only files required by failed tests or verification findings.
- No unrelated cleanup or dependency changes.

- [ ] **Step 1: Run focused backend and render tests**

  Run:

  ```bash
  php artisan optimize:clear
  php artisan view:cache
  php artisan test tests/Feature/SalesAgendaReceiptPersistenceTest.php tests/Feature/SalesAgendaReceiptWorkflowTest.php tests/Feature/SalesAgendaReceiptAuthorizationTest.php tests/Feature/SalesAgendaWorkspaceRenderTest.php tests/Feature/SalesAgendaWorkspaceInteractionMarkupTest.php --compact
  ```

  Expected: all focused tests pass.

- [ ] **Step 2: Run PHP formatting and repository checks**

  Run:

  ```bash
  vendor/bin/pint --dirty --format agent
  git diff --check
  ```

  Expected: changed PHP files are formatted and no whitespace errors are reported.

- [ ] **Step 3: Verify routes and full test risk**

  Run:

  ```bash
  php artisan route:list --name=sales-agendas --except-vendor
  composer test
  ```

  Expected: named Agenda routes retain the intended middleware and the full suite passes, or any pre-existing unrelated failure is recorded explicitly.

- [ ] **Step 4: Browser-verify the reference states**

  Use the existing local development command and Playwright at approximately 390px, 768px, 1024px, and 1440px. Verify:

  - Agenda empty, partial, exact-420, submitted, extra, future, and incomplete-previous states;
  - Lead Saya open/completed cards and add flow;
  - bottom-sheet open, focus, Escape, cancel, validation, and submit behavior;
  - no horizontal overflow, covered sticky content, console errors, missing assets, or inaccessible controls;
  - submitted Agenda can still complete and upload evidence;
  - deferred Agenda creates the next-day linked copy.

- [ ] **Step 5: Review final diff and commit verification fixes**

  Run:

  ```bash
  git status --short
  git diff --stat HEAD
  git log --oneline -8
  ```

  Commit only intentional fixes with a focused message. Do not commit `.playwright-mcp/` or other generated browser artifacts.

## Execution Notes

This plan intentionally separates persistence/rules from presentation so each slice is
testable before the next one. The recommended execution method is **native execution**
in the current session: the tasks share the same Laravel route and view-model
interfaces, and a single implementer can keep the UI, authorization, and browser
states aligned without creating parallel worktrees.

---

## Expanded Rework Pass: mobile-first Sales app contract

The 2026-10-31 rework brief expands the approved visual contract. Execute these tasks
after the existing receipt workflow is stable; do not rebuild the existing lead,
evidence, or authorization architecture.

### Task 8: Replace legacy onboarding with the in-context five-step tour

**Files:**
- Modify: `app/Services/SalesPocketbookOnboardingService.php`
- Modify: `app/Http/Controllers/Crm/SalesAgendaController.php`
- Modify: `app/Http/Controllers/Crm/SalesPocketbookOnboardingController.php`
- Modify: `resources/js/sales-pocketbook-onboarding.js` or replace with a named tour module
- Modify: `resources/views/crm/sales-pocketbook/sales-agenda.blade.php`
- Create/modify: `resources/views/crm/sales-pocketbook/partials/sales-tour.blade.php`
- Test: focused Sales workspace/tour tests

- [ ] Store the new tour at a new progress version so old onboarding completion does not suppress it.
- [ ] Render the tour over the real Agenda workspace with five spotlight targets: welcome, work-hours card, add-plan action, agenda evidence/result row, and Lead Saya tab.
- [ ] Make every step skippable; support `Lewati tour`, `Lanjut`, final `Mulai bekerja`, keyboard focus, Escape, focus restoration, and viewport-safe tooltip positioning.
- [ ] Make the help menu replay the tour without returning to the old illustrated onboarding screen.
- [ ] Remove the old onboarding page from user-visible navigation and preserve compatibility only where existing route tests require it.

### Task 9: Implement weekday/weekend schedule semantics

**Files:**
- Modify: `app/Services/SalesAgendaReceiptService.php`
- Modify: `app/Http/Controllers/Crm/SalesAgendaController.php`
- Modify: `app/Http/Requests/Crm/StoreSalesAgendaRequest.php`
- Modify: `app/Models/SalesAgendaDaySubmission.php`
- Create: migration adding the persisted weekend marker
- Test: receipt workflow and authorization tests

- [ ] Centralize schedule metadata for Monday-Thursday, Friday, and weekend dates.
- [ ] Reject overlaps and break-time placement server-side; keep browser calculations advisory.
- [ ] Weekdays require exactly 420 normal minutes before submission; weekends require at least one agenda and have no target/break row.
- [ ] Persist and expose `is_weekend` on the day submission/coordination contract.
- [ ] Preserve legacy untimed rows as readable records without counting them toward the new timed target.

### Task 10: Rebuild the receipt and empty states against the reference contract

**Files:**
- Modify: `resources/views/crm/sales-pocketbook/partials/agenda-receipt.blade.php`
- Modify: `resources/css/app.css`
- Modify: `resources/views/crm/sales-pocketbook/partials/buku-saku-header.blade.php`
- Test: render and interaction markup tests

- [ ] Add route-scoped Archivo/Courier tokens, brutalist cards, dark receipt canvas, toothed receipt edge, break row, empty slots, weekday meter, weekend stamp, and safe-area bottom action.
- [ ] Keep the view centered at 390px on mobile and scale to a narrow content column or deliberate desktop columns.
- [ ] Preserve submitted/additional/legacy untimed states and keep status meaning visible as text.
- [ ] Verify empty, partial, exact-420, submitted, and weekend screenshots at 390px and desktop.

### Task 11: Align Lead Saya and agenda detail interactions

**Files:**
- Modify: `resources/views/crm/sales-pocketbook/partials/lead-saya.blade.php`
- Modify: relevant Sales detail Blade views
- Modify: `resources/css/app.css`
- Test: existing Lead Saya, evidence, and completion tests

- [ ] Use card-only mobile leads, segmented counts, a bottom-sheet add flow, and paired save actions without changing lead validation/completion semantics.
- [ ] Make agenda detail show evidence slots, required result, next agenda, and disabled completion until the business rules pass.
- [ ] Cover loading, upload error, empty, denied, offline/draft, and conflict states with existing shared feedback patterns where available.

### Task 12: Final visual and regression gate

- [ ] Run focused Sales tests, `php artisan optimize:clear`, `php artisan view:cache`, Pint, `git diff --check`, and `npm run build`.
- [ ] Run the full suite and record the known unrelated `OrganizationParityFilter.php` failures without changing that scope.
- [ ] Browser-verify 390px and 1440px, including tour, weekday/weekend receipt, Lead Saya, bottom sheets, keyboard/Escape, no overflow, and console errors.
- [ ] Verify exactly one changelog row for the visible rework migration.
