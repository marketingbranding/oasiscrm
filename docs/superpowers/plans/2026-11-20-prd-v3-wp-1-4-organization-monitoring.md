# WP-1.4 Organization Monitoring Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver BR-50–BR-53 organization-graph monitoring in six reviewable slices, with `org_hierarchical_monitoring` default-off and canonical graph read mode kept as an independent operator-controlled switch.

**Architecture:** Keep `OrganizationScopeService` as the only monitoring-scope semantic point. Reuse `OrganizationGraphModeService`, `OrganizationGraphService`, `ReportingHierarchyService`, `SalesTeamScopeService`, parity diagnostics, policies, and existing permission catalog. Migrate consumers only where they bypass the shared scope or expose more detail/mutation than contract allows.

**Tech Stack:** Laravel 13, PHP 8.3, Eloquent, PHPUnit, Artisan, Blade/Alpine where existing consumer changes require it.

**Spec:** `docs/superpowers/specs/2026-11-20-prd-v3-wp-1-4-organization-monitoring-design.md`

## Global Constraints

- `org_hierarchical_monitoring` defaults to false.
- Canonical graph read mode and hierarchical monitoring flag are independent switches.
- Flag-off behavior remains unchanged.
- BR-50–BR-52 belong in WP-1.4; BR-53 only gets an extension point and remains WP-2.2.
- `scope_level=pusat` expands only existing `*_branch` permission scope for `manager`, `ass_manager`, and `supervisor`; never `*_own` or `*_assigned`.
- Read-only restriction applies only to Sales team data: Lead, daily plan/agenda, and team history.
- `canEditHistoricalOwnedLead` for BM and Admin Cabang remains until WP-2.1; record review there.
- Distant descendants receive module-specific summary allowlists only for Sales team data.
- No production cutover, backfill write, permission addition, new authorization layer, or dependency addition.
- Every behavior change gets one idempotent Indonesian changelog migration.
- Every BR test name or test comment includes its BR ID.
- Push every slice; do not merge into `prd-v3/fase-1-fondasi` until 1.4f passes.

## Review Focus

- Independent switches: hierarchical flag must work under legacy, shadow, and graph read modes. Test in Task 2.
- Effective-date and invalid graph edges: only valid active `REPORTS_TO` edges contribute distance. Test in Task 2.
- Scope intersection: descendants cannot escape workspace, project, permission, or branch boundaries; pusat cannot widen own/assigned. Test in Task 2.
- Direct URLs and AJAX: hidden menus do not protect monitoring or mutation endpoints. Test in Tasks 3–5.
- Summary leakage: distant descendants never receive activity contents, notes, evidence photos, locations, before/after values, or sensitive fields. Test in Task 4.

---

### Task 1: Prepare WP-1.4a branch and operator contract

**Files:**
- Create/modify: `docs/product/PRD_V3_IMPLEMENTATION_LOG.md`
- Create: `docs/superpowers/plans/2026-11-20-prd-v3-wp-1-4-organization-monitoring.md`
- Existing design: `docs/superpowers/specs/2026-11-20-prd-v3-wp-1-4-organization-monitoring-design.md`

**Interfaces:**
- Consumes: approved WP-1.4 design and existing parity/health commands.
- Produces: implementation log entries for decisions, 1.4a status, D33 operator boundary, and WP-2.1 review note.

- [ ] Record owner decisions: Sales-only read-only boundary, `scope_level=pusat` branch-only widening, summary allowlists, effective-date graph distance, and `canEditHistoricalOwnedLead` review in WP-2.1.
- [ ] Record 1.4a command/report contract: read-only, machine-readable output, no source-mode activation.
- [ ] Run `git diff --check`; commit docs separately with `docs: record WP-1.4 monitoring decisions`.

### Task 2: Implement 1.4a canonical graph read parity

**Files:**
- Modify: `app/Services/OrganizationGraphModeService.php` only if mode diagnostics need a stable read-source contract.
- Modify: `app/Services/OrganizationParityService.php` for visible-user parity by viewer/context.
- Modify: `app/Console/Commands/OrganizationParity.php` or create a narrowly scoped command only if existing command cannot express the report.
- Test: `tests/Feature/OrganizationGraphModeReadsTest.php`
- Test: `tests/Feature/OrganizationParityServiceTest.php`
- Test: `tests/Feature/OrganizationParityCommandTest.php`

**Interfaces:**
- Consumes: `OrganizationGraphModeService::modeFor()`, `OrganizationGraphService`, legacy hierarchy/team readers, `OrganizationParityFilter`.
- Produces: report containing viewer/context, legacy visible user IDs, graph visible user IDs, equality, mismatch classification, and effective read mode.

- [ ] Write failing BR-50 tests for legacy/shadow/graph read modes, equal visible IDs, mismatch reporting, invalid graph integrity, effective dates, and no data mutation.
- [ ] Run focused tests; expected failures prove missing report behavior only.
- [ ] Implement smallest parity extension through existing `OrganizationParityService`; do not change production mode or merge sources.
- [ ] Add/adjust dry-run command output and JSON contract; invalid filters fail closed.
- [ ] Run focused tests, `vendor/bin/pint --dirty --format agent`, `git diff --check`, `php artisan optimize:clear`, `php artisan view:cache`, and `graphify update .`.
- [ ] Commit `feat: add organization graph read parity report`; push WP-1.4a branch.

### Task 3: Implement 1.4b shared hierarchical scope semantics

**Files:**
- Modify: `app/Services/OrganizationScopeService.php`
- Modify: `app/Services/WorkspaceAccessService.php` only if existing workspace intersection cannot represent pusat branch scope.
- Test: `tests/Feature/OrganizationScopeServiceTest.php` or existing equivalent.
- Test: `tests/Feature/OrganizationGraphModeReadsTest.php`
- Test: `tests/Unit/FeatureFlagTest.php` only if flag catalog contract changes.

**Interfaces:**
- Consumes: `FeatureFlag::enabled('org_hierarchical_monitoring')`, graph mode service, graph descendant traversal, permission scopes, workspace branch/project IDs.
- Produces: existing `visibleUserIds()`, `branchIds()`, `projectIds()`, `teamIds()`, and `hierarchyIds()` behavior with flag-gated hierarchical semantics; no new authorization service.

- [ ] Write failing BR-51/BR-52 tests for flag off baseline, flag on under legacy/shadow/graph, recursive descendants, workspace intersection, branch-scoped expansion for pusat, and no expansion of own/assigned.
- [ ] Implement flag check at `OrganizationScopeService`; preserve current path when disabled.
- [ ] Add role/attribute checks through existing role/permission APIs, not role IDs.
- [ ] Add BR-53 extension point that returns no grant until WP-2.2; do not expose feature.
- [ ] Run focused tests and standard PHP/graph checks.
- [ ] Commit `feat: gate hierarchical organization scope`; push WP-1.4b branch.

### Task 4: Migrate and inventory all Sales monitoring consumers (1.4c–1.4d)

**Files:**
- Modify only consumers found by route/service inventory, expected candidates:
  - `app/Services/SupervisorSalesMonitoringService.php`
  - `app/Services/AdminBranchSalesMonitoringService.php`
  - coordinator monitoring services/controllers
  - daily plan/agenda team services/controllers
  - team-history services/controllers
  - exports, KPI/aggregate, autocomplete, notification/deep-link, and AI read tools
- Modify: `docs/product/PRD_V3_IMPLEMENTATION_LOG.md`
- Test: focused feature tests per consumer and direct route tests.

**Interfaces:**
- Consumes: shared `OrganizationScopeService` contracts from Task 3.
- Produces: every Sales monitoring read path uses shared scope; inventory records route, controller/service, scope/action, and test.

- [ ] Build inventory from routes, controllers, services, policies, exports, notifications, and `AiToolRegistry`; mark already-compliant consumers.
- [ ] Write direct-URL tests for flag-on isolation and flag-off compatibility for each consumer.
- [ ] Migrate bypasses; keep Consumers, Dana Talangan, and Expenses on current scope without distant-summary mode.
- [ ] Add module-specific distant descendant summary serializers/query projections:
  - Lead: name, owner Sales, project, stage, last active date.
  - Daily plan/agenda: Sales name, date, total minutes/target, send status, activity count, late count.
  - History: time, change type, actor, object code/name; no before/after values.
- [ ] Test absent forbidden fields, exports matching screen scope/detail, AJAX, notification/deep-link, and AI read scope.
- [ ] Update implementation log inventory and run standard checks.
- [ ] Commit one atomic slice per consumer group if needed; push after each reviewable slice.

### Task 5: Enforce Sales-team read-only mutations (1.4e)

**Files:**
- Modify relevant Sales lead, daily plan/agenda, and team-history policies/requests/controllers/services only where current authorization permits non-Coordinator team mutation.
- Preserve `canEditHistoricalOwnedLead` for BM/Admin Cabang.
- Test: mutation endpoint feature tests for Supervisor, Branch Manager, Manager, Ass. Manager, and Coordinator.
- Test: direct URL and JSON/AJAX request variants.

**Interfaces:**
- Consumes: shared scope, existing permission catalog, existing policies, Coordinator team identity.
- Produces: team monitoring mutations allowed only to Coordinator under existing permission/policy; other listed monitoring roles receive 403, while unrelated module mutations remain unchanged.

- [ ] Write failing BR-51 tests for every relevant mutation endpoint and role; include Manager 403 and BM/Admin Cabang historical-owned exception.
- [ ] Implement minimum policy/controller guard at existing authorization boundary; do not hide-only or add parallel policy layer.
- [ ] Test unrelated module mutation remains governed by existing permission/policy.
- [ ] Run focused tests and standard checks; commit `fix: enforce sales monitoring read-only boundary`; push WP-1.4e.

### Task 6: Prove Coordinator team isolation (1.4f)

**Files:**
- Modify only remaining coordinator scope consumers or policies.
- Test: coordinator monitoring, export, mutation, autocomplete, notification/deep-link, and AI tool feature tests.
- Modify: `docs/product/PRD_V3_IMPLEMENTATION_LOG.md` with final inventory/status.
- Add/modify: one idempotent changelog migration for shipped WP-1.4 behavior.

**Interfaces:**
- Consumes: all prior shared scope/detail/mutation contracts.
- Produces: Coordinator A cannot view, export, mutate, autocomplete, deep-link, or AI-read Coordinator B team data in same branch, including direct URLs and AJAX.

- [ ] Write failing BR-53 boundary tests for Coordinator A/B isolation; test unauthorized IDs, exports, JSON, and direct routes.
- [ ] Fix only bypasses discovered by tests; preserve legacy behavior when flag is off.
- [ ] Add changelog migration with `version=null`, Indonesian title/description, correct category, `created_by=null`, idempotent `updateOrInsert`, and scoped `down()`.
- [ ] Run full focused WP-1.4 suite, full `composer test`, `vendor/bin/pint --dirty --format agent`, `git diff --check`, `php artisan optimize:clear && php artisan view:cache`, `graphify update .`, and `npm run build` only if JS/CSS/Blade Tailwind changes.
- [ ] Record exactly two known baseline `SalesAgendaWorkspaceRenderTest` failures if still present; do not weaken tests.
- [ ] Commit `feat: complete hierarchical sales monitoring isolation`; push branch.
- [ ] Request review. Merge into `prd-v3/fase-1-fondasi` only after review and all 1.4a–1.4f checks pass; use non-squash merge.

## Execution Notes

- Start from `prd-v3/fase-1-fondasi` in a new WP-1.4 worktree/branch; do not place implementation commits on WP-1.3 branch.
- Install/restore existing JS dependencies before any required `npm run build`; do not add dependencies.
- Never execute production cutover, `organization:backfill --write`, or enable flags in production.
