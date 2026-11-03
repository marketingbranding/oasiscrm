# Phase 2 Organization & Scope Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a safe, machine-readable legacy-versus-organization-graph parity diagnostic and evidence package without changing production organization reads or executing a write backfill.

**Architecture:** Add an immutable diagnostic filter and a reusable `OrganizationParityService` with explicit legacy and graph comparison paths. Expose it only through a filtered `organization:parity` Artisan command and tests; document source ownership, scope vocabulary, mismatch classifications, backfill findings, integrity findings, and future cutover gates. Existing production services continue using their current legacy behavior.

**Tech Stack:** PHP 8.3, Laravel 13, Eloquent, Artisan commands, PHPUnit, SQLite test database.

**Spec:** `docs/superpowers/specs/2026-10-30-phase-2-organization-scope-parity-design.md`

## Global Constraints

- Do not change `config('organization.graph_mode')` defaults or switch production reads to the graph.
- Do not remove `supervisor_user_id`, `sales_coordinator_sales`, branch/project fallbacks, legacy services, or compatibility projections.
- Do not execute non-dry-run organization backfill.
- Do not add routes, restructure navigation, rename roles, broaden permissions, or introduce a new dependency.
- Do not create database constraints until existing-data violations are measured.
- Do not expose parity diagnostics through normal CRM pages or APIs.
- Preserve all existing worktree modifications and untracked files.

## Review Focus

- **Legacy/graph source contamination:** a comparison must not call a mode-dependent production method twice; test that legacy output remains legacy when graph mode is `shadow` or `canonical`.
- **Cross-branch/project leakage:** graph-only relationships must not produce an allowed representative module record outside the viewer workspace; pin this in module shadow tests.
- **Sales Coordinator ambiguity:** duplicate active mappings must remain a classified mismatch, not silently become a team member; pin this in the Sales comparison test.
- **Malformed graph data:** self-parent, direct/indirect cycle, duplicate active edge, missing parent, and disconnected nodes must terminate and be classified safely.
- **Diagnostic input safety:** invalid IDs, roles, scopes, and JSON/filter combinations must return a non-zero command result or an empty scoped report without leaking unrelated organization data.

---

### Task 1: Freeze the Organization Source and Scope Contract

**Files:**
- Create: `docs/ORGANIZATION_SCOPE_PARITY_PHASE_2.md`
- Create: `tests/Feature/OrganizationParityDocumentationTest.php`

**Interfaces:**
- Consumes: current source behavior from `OrganizationScopeService`, `OrganizationGraphService`, `ReportingHierarchyService`, `WorkspaceAccessService`, `SalesTeamScopeService`, `User`, `OrganizationAssignment`, `SalesCoordinatorSales`, `branch_user`, and `project_user`.
- Produces: the Phase 2 source table, exact scope vocabulary, legacy fallback rules, graph rules, mismatch taxonomy, backfill analysis template, integrity invariants, and cutover readiness checklist.

- [ ] **Step 1: Write the failing documentation-contract test**

  Assert that the document names every required source, all seeded role slugs, every required scope (`own`, `team`, `assigned`, `project`, `branch`, `descendants`, `organization-wide`, `system-wide`, `historical`), every mismatch classification, `organization:backfill --dry-run`, and the no-cutover rule.

- [ ] **Step 2: Run the focused test and verify it fails**

  Run: `php artisan test --compact tests/Feature/OrganizationParityDocumentationTest.php`

  Expected: FAIL because the Phase 2 evidence document is not complete yet.

- [ ] **Step 3: Write the source and scope contract**

  Record for each source its purpose, readers, writers, fallback behavior, and legacy/canonical/compatibility status. Distinguish organization scope from system authorization and record that production remains legacy-read.

- [ ] **Step 4: Run the focused test and verify it passes**

  Run: `php artisan test --compact tests/Feature/OrganizationParityDocumentationTest.php`

  Expected: PASS.

### Task 2: Add the Immutable Diagnostic Filter and Comparison Contract

**Files:**
- Create: `app/Support/OrganizationParityFilter.php`
- Create: `app/Services/OrganizationParityService.php`
- Create: `tests/Feature/OrganizationParityServiceTest.php`

**Interfaces:**
- Consumes: a filter with `?int $userId`, `?int $branchId`, `?string $role`, `?string $scope`, `?string $module`, `bool $onlyDifferences`.
- Produces: `OrganizationParityService::report(OrganizationParityFilter $filter): array` with `meta`, `comparisons`, `summary`, and `readiness` keys. Each comparison must contain `viewer_id`, `viewer_role`, `module`, `scope`, `dimension`, `legacy_ids`, `graph_ids`, `only_legacy`, `only_graph`, `equivalent`, `classification`, `reason`, and `details`.

- [ ] **Step 1: Write failing service tests**

  Cover an equivalent empty scope, a known missing graph assignment, sorted/deduplicated output, `only-differences`, one-user filtering, branch filtering, role filtering, scope filtering, and unknown filters remaining explicit rather than broadening the result.

- [ ] **Step 2: Run the focused tests and verify they fail**

  Run: `php artisan test --compact tests/Feature/OrganizationParityServiceTest.php`

  Expected: FAIL because the filter and service do not exist.

- [ ] **Step 3: Implement the filter and service contract**

  Validate filter vocabulary in the support object. Keep legacy and graph resolution in separate private paths. Use explicit current-date predicates, active-account predicates, and stable integer ID ordering. Do not mutate config or invoke production canonical mode.

- [ ] **Step 4: Implement deterministic mismatch classification**

  Classify only when source evidence proves the category; otherwise return `UNKNOWN`. Preserve raw source facts in `details`, including duplicate, stale, missing, and conflicting rows.

- [ ] **Step 5: Run focused tests and verify they pass**

  Run: `php artisan test --compact tests/Feature/OrganizationParityServiceTest.php`

  Expected: PASS.

### Task 3: Cover All Roles, Relationships, and Scope Dimensions

**Files:**
- Modify: `tests/Feature/OrganizationParityServiceTest.php`
- Modify: `tests/Feature/OrganizationScopeServiceTest.php` only when a regression contract is needed
- Modify: `tests/Feature/OrganizationGraphTest.php` only when a traversal safety contract is needed

**Interfaces:**
- Consumes: `OrganizationParityService::report()` from Task 2.
- Produces: fixture-backed evidence for `sales`, `sales_coordinator`, `supervisor`, `manager`, `branch_manager`, `sas`, `sam`, `pusat`, `admin`, `staff`, and `superadmin`.

- [ ] **Step 1: Add role and relationship fixtures/tests**

  Create cases for one branch, multiple current branches, primary-branch fallback, active project assignments, supervisor parent/descendants, Sales Coordinator mappings, no graph assignment, historical branch/mapping access, supplemental roles, and disconnected users.

- [ ] **Step 2: Add dimension assertions**

  Compare visible users, team members, branches, projects, reporting descendants, Sales Coordinator-to-Sales membership, and Supervisor-to-Coordinator membership. Assert that a mismatch receives a classification and reason.

- [ ] **Step 3: Add cycle and malformed-data assertions**

  Verify direct cycle, indirect cycle, self-reference, missing parent, duplicate active primary edge, deep hierarchy, and disconnected node cases terminate safely and never produce unbounded traversal.

- [ ] **Step 4: Run the focused organization tests**

  Run: `php artisan test --compact tests/Feature/OrganizationParityServiceTest.php tests/Feature/OrganizationScopeServiceTest.php tests/Feature/OrganizationGraphTest.php`

  Expected: PASS, with production scope behavior unchanged.

### Task 4: Add Representative Module Access Shadow Evaluation

**Files:**
- Modify: `app/Services/OrganizationParityService.php`
- Modify: `tests/Feature/OrganizationParityServiceTest.php`

**Interfaces:**
- Consumes: explicit legacy/graph ID comparisons from Task 2 and the existing `CrmAuthorizationService`, `OrganizationScopeService`, and `WorkspaceAccessService` contracts.
- Produces: `module_access` comparisons for `sales_pocketbook`, `consumer_progress`, `work_planner`, `database`, `bridge_fund`, `expenses`, reporting, and user/organization administration, represented as `allow`/`deny` with record identifiers and no production decision changes.

- [ ] **Step 1: Write failing module shadow tests**

  Build representative in-scope and out-of-scope records across separate branches and projects. Assert that the report exposes legacy and graph decisions independently and identifies cross-branch or cross-project leakage as a critical mismatch.

- [ ] **Step 2: Run the tests and verify they fail**

  Run: `php artisan test --compact tests/Feature/OrganizationParityServiceTest.php --filter=module`

  Expected: FAIL because module access comparison is not yet present.

- [ ] **Step 3: Implement non-authoritative module evaluation**

  Evaluate representative records against each side’s calculated IDs and existing branch/project predicates. Never replace or call the production authorization result as the graph result; label the output as diagnostic shadow data.

- [ ] **Step 4: Run focused tests and verify they pass**

  Run: `php artisan test --compact tests/Feature/OrganizationParityServiceTest.php --filter=module`

  Expected: PASS.

### Task 5: Expose the Safe `organization:parity` Diagnostic Command

**Files:**
- Create: `app/Console/Commands/OrganizationParity.php`
- Create: `tests/Feature/OrganizationParityCommandTest.php`

**Interfaces:**
- Consumes: `OrganizationParityFilter` and `OrganizationParityService::report()`.
- Produces: `organization:parity {--user=} {--branch=} {--role=} {--scope=} {--module=} {--only-differences} {--json}` with human-readable tables by default and JSON containing the exact service result when `--json` is supplied.

- [ ] **Step 1: Write failing command tests**

  Assert command registration, JSON shape, all filters, differences-only output, invalid filter handling, and no normal-user route exposure. Assert that the command does not alter `organization_assignments`, `users.supervisor_user_id`, or `sales_coordinator_sales`.

- [ ] **Step 2: Run the command tests and verify they fail**

  Run: `php artisan test --compact tests/Feature/OrganizationParityCommandTest.php`

  Expected: FAIL because the command is not registered.

- [ ] **Step 3: Implement the command**

  Follow the existing attribute-based command convention. Return a non-zero failure for malformed numeric IDs or unsupported vocabulary; return success with an empty scoped report for valid filters that match no users. Do not print secrets or unrelated user details.

- [ ] **Step 4: Run command tests and a dry diagnostic**

  Run: `php artisan test --compact tests/Feature/OrganizationParityCommandTest.php`  
  Run: `php artisan organization:parity --json`.

  Expected: PASS and machine-readable output without database writes.

### Task 6: Verify Existing Backfill Dry-Run and Idempotency

**Files:**
- Create: `tests/Feature/OrganizationBackfillTest.php`
- Modify: `docs/ORGANIZATION_SCOPE_PARITY_PHASE_2.md`

**Interfaces:**
- Consumes: existing `OrganizationBackfillService::run(bool $dryRun = true): array` and `organization:backfill --dry-run`.
- Produces: documented parent precedence, safe inference limits, conflict categories, duplicate behavior, missing-parent behavior, multi-branch behavior, Sales Coordinator handling, and isolated dry-run idempotency evidence.

- [ ] **Step 1: Write failing backfill tests**

  Cover supervisor precedence, coordinator precedence, coordinator/supervisor conflict, multiple active coordinator mappings, missing users, inactive parents, cycles, existing matching assignments, differing assignments, multi-branch users, and repeated dry-runs.

- [ ] **Step 2: Run tests and verify the baseline**

  Run: `php artisan test --compact tests/Feature/OrganizationBackfillTest.php`

  Expected: any unsupported or currently unsafe behavior is captured as a red test or explicit diagnostic finding; no production write is executed.

- [ ] **Step 3: Make only non-destructive diagnostic fixes**

  Fix classification/reporting or deterministic dry-run behavior only when required by the tests. Do not change write-mode semantics or invoke `run(false)`.

- [ ] **Step 4: Run isolated dry-run twice and verify stable proposals**

  Run: `php artisan organization:backfill --dry-run` twice against the development/test fixture setup.

  Expected: no writes, stable proposal/conflict counts, and documented differences.

### Task 7: Complete Evidence Report and Cutover Readiness Gates

**Files:**
- Modify: `docs/ORGANIZATION_SCOPE_PARITY_PHASE_2.md`
- Modify: `tests/Feature/OrganizationParityDocumentationTest.php`

**Interfaces:**
- Consumes: service/command outputs, backfill dry-run findings, existing graph integrity tests, and module shadow results.
- Produces: the required completion-report sections A–Q and an explicit statement that Phase 3 cutover was not performed.

- [ ] **Step 1: Update the evidence document**

  Include organization data sources, legacy scope contract, canonical graph scope contract, parity results, mismatch summary, role × scope matrix, module access impact, backfill analysis, integrity findings, cycle safety, changed files, tests, remaining debt, readiness assessment, controlled future cutover plan, and rollback plan.

- [ ] **Step 2: Add documentation assertions for objective readiness gates**

  Assert that the document requires zero unknown mismatches, zero critical cross-branch/project leakage, reporting and Sales Coordinator parity, idempotent backfill, survivable integrity rules, and a tested legacy-read rollback before any future cutover.

- [ ] **Step 3: Run the documentation and focused parity suite**

  Run: `php artisan test --compact tests/Feature/OrganizationParityDocumentationTest.php tests/Feature/OrganizationParityServiceTest.php tests/Feature/OrganizationParityCommandTest.php tests/Feature/OrganizationBackfillTest.php`

  Expected: PASS.

### Task 8: Final Phase 2 Verification

**Files:**
- No application files beyond those listed above; preserve unrelated worktree changes.

- [ ] **Step 1: Clear and compile Laravel caches**

  Run: `php artisan optimize:clear`  
  Run: `php artisan view:cache`

- [ ] **Step 2: Run the complete test suite**

  Run: `php artisan test --compact`

  Expected: zero failures; record exact test and assertion counts.

- [ ] **Step 3: Run static, whitespace, route, and frontend verification**

  Run: `git diff --check`  
  Run: `vendor/bin/pint --dirty --test`  
  Run: `php artisan route:list --except-vendor`  
  Run: `npm run build`

- [ ] **Step 4: Verify no cutover or destructive backfill occurred**

  Inspect `git diff`, `git status --short`, `config/organization.php`, and migration status. Confirm no legacy columns/relationships were removed, no graph mode default changed, and no non-dry-run backfill was run.

- [ ] **Step 5: Stop after Phase 2**

  Do not begin Phase 3 or change production organization reads. Report remaining mismatches and wait for review.
