# Koordinator Agenda Regressions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Resolve the four reported full-suite failures while retaining the approved Koordinator cockpit (Hari ini | Lead | Laporan with Agenda as contextual Rencana), verify the feature branch, and merge it into `main`.

**Architecture:** Trace current coordinator agenda create/update redirects, list rendering, and Sales agenda heading behavior before changes. Use focused PHPUnit regression coverage to distinguish an application defect from expectations made obsolete by the redesign; only change app behavior where the approved flow is broken. Keep authorization and team scoping in existing services/controllers.

**Tech Stack:** Laravel 13, PHP 8.3, Blade, PHPUnit, Vite, SQLite test database, Git.

**Spec:** Approved scope in this conversation and the existing OASIS constraints in `AGENTS.md`.

## Global Constraints

- Preserve the primary Koordinator tabs Hari ini | Lead | Laporan; Agenda remains contextual.
- Preserve current-team authorization and deny out-of-team agenda access.
- Koordinator enters `activity_result` and agenda completion remains attributed to the actor; Koordinator does not upload evidence.
- Do not change business rules, target/reminder rules, or cockpit design outside the agreed scope.
- Do not merge while full tests fail; do not overwrite unrelated worktree changes.
- Include the idempotent Oasis Changelog migration already part of the feature branch; do not invent a release version.

## Review Focus

- A coordinator-created agenda must remain reachable in the contextual plan view after redirect; verify the redirected route and agenda visibility.
- An out-of-team agenda remains denied for create, update, and view; retain scope-matrix coverage.
- Updating a team agenda records the result and completed status without evidence upload; retain coordinator update coverage.
- A planned agenda on a weekend renders a date-appropriate heading without losing weekend-specific summary; cover the failing Saturday case.
- Legacy coordinator routes must not accidentally restore an Agenda top-level tab; assert the approved three-tab navigation remains.

---

### Task 1: Align coordinator agenda workflow with contextual Rencana

**Files:**
- Inspect/modify: `app/Http/Controllers/Crm/SalesAgendaController.php`
- Inspect/modify: `app/Http/Controllers/Crm/CoordinatorSalesLeadWorkspaceController.php`
- Inspect/modify: `resources/views/crm/sales-pocketbook/coordinator-leads.blade.php`
- Test/modify: `tests/Feature/SalesAgendaOnlyWorkspaceTest.php`
- Test/modify: `tests/Feature/SalesPocketbookScopeMatrixTest.php`
- Test/modify: `tests/Feature/SalesRoleWorkspaceScopeTest.php`

**Interfaces:**
- Consumes existing `CoordinatorSalesMonitoringService::todayWorkspace(User $actor, ?CarbonImmutable $referenceDate = null, ?int $salesId = null): array` and existing team scope authorization.
- Preserves existing route names and the `tab=today&view=plans` contextual workspace contract.

- [ ] Reproduce the current failures and inspect redirect target and view data for coordinator create/update.
- [ ] Add/adjust regression assertions first: coordinator CRUD redirects into contextual Rencana and renders the team agenda; out-of-team agenda remains forbidden; the three primary tabs remain unchanged.
- [ ] Run the focused tests and confirm the regression assertions fail for the observed reason.
- [ ] Make the smallest controller/view change that preserves the approved contextual Rencana flow; do not add a top-level agenda tab.
- [ ] Run `php artisan test --compact` for the three affected test files and confirm they pass.
- [ ] Commit this verified behavior/test slice with a focused `fix:` commit after checking the staged diff.

### Task 2: Resolve weekend agenda heading regression

**Files:**
- Inspect/modify: `resources/views/crm/sales-pocketbook/partials/agenda-receipt-canvas.blade.php`
- Test/modify: `tests/Feature/SalesAgendaWorkspaceRenderTest.php`

**Interfaces:**
- Preserve the existing `$agendaDate`, `$isToday`, `$isTomorrow`, `$isWeekend`, and `$daySubmission` view inputs.

- [ ] Add a focused regression assertion for the Saturday date case while preserving the weekend summary/stamp contract.
- [ ] Run the focused test and verify it fails before the correction.
- [ ] Correct the heading only as needed so the selected agenda date is identifiable on weekends and weekday headings remain unchanged.
- [ ] Run `php artisan test --compact tests/Feature/SalesAgendaWorkspaceRenderTest.php` and confirm all tests pass.
- [ ] Commit this verified rendering/test slice with a focused `fix:` or `test:` commit after checking the staged diff.

### Task 3: Full verification, review, and merge

**Files:**
- Verify all branch changes; include migration `database/migrations/2026_11_05_120952_add_coordinator_workspace_changelog.php` and built Vite assets already in the branch.

- [ ] Run all four original regression tests, then full `php artisan test --compact`.
- [ ] Run `vendor/bin/pint --dirty --format agent`, `npm run build`, `git diff --check`, `php artisan optimize:clear`, and `php artisan view:cache` as applicable; do not format unrelated files.
- [ ] Run `graphify update .` and inspect final `git status`, diff, and branch history; confirm no unrelated user changes are lost.
- [ ] Perform a fresh whole-branch review; fix any Critical/Important findings with regression tests and rerun the full suite.
- [ ] Commit the remaining verified branch changes in focused commit(s), including existing cockpit implementation, changelog migration, and generated build assets expected by this repository.
- [ ] Confirm `main` has not advanced incompatibly, merge `fix/sales-lead-detail-mobile-redesign` into `main` without force operations, and verify the resulting merge commit and clean/expected status.

**Final acceptance:** Four regression cases pass; full PHPUnit suite passes; validation commands complete successfully or explicitly documented repository limitations are resolved; implementation retains the approved UI/access behavior; branch is merged into local `main`.
