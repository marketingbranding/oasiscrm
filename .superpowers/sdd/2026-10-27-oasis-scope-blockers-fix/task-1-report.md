# Task 1 Report: Constrain Supervisor Team Reads by Workspace

## Status

Implemented and verified. The change is limited to Supervisor read/monitoring scope; lifecycle mutation authorization remains unchanged.

## Root cause

1. `SalesLeadPolicy::view()` checked Supervisor team membership, but then the generic branch/project return could still authorize a lead outside the Supervisor's effective team when the lead's branch and project were otherwise visible.
2. `SalesTeamScopeService::displayedFor()` filtered people with a broad branch-or-project condition. A Sales user in the Supervisor hierarchy could therefore remain visible when their current project assignment was outside the supplied workspace.
3. Historical monitoring needed the same workspace filtering after loading ended mappings; otherwise an ended mapping could reintroduce an out-of-workspace Sales user.

## Implementation

- Added `SalesTeamScopeService::forWorkspace(User $actor, array $branchIds, array $projectIds, bool $includeHistorical = false): array`.
  - Preserves the existing `for()` result shape and mapping keys.
  - Reuses `for()`/`displayedFor()` mapping and historical behavior.
  - Restricts Supervisors/Coordinators to allowed branches.
  - Restricts Sales to allowed branches and either no current project assignment or a current assignment in the supplied project IDs.
  - Re-filters both relationship maps after people are removed.
  - Ended mappings remain available only when `includeHistorical` is true.
- Updated `SalesLeadPolicy::view()` so Supervisor checks run before generic global/branch returns, use the workspace-constrained team, require allowed branch/project IDs, and use current mappings only. Supervisor remains read-only through the unchanged mutation policy paths.
- Updated `SupervisorSalesMonitoringService` scope/team resolution to use the same workspace-constrained helper. Historical monitoring/export uses `includeHistorical: true` only after workspace filtering; lead and agenda branch/project filters remain in place.
- Added feature regressions for:
  - same-hierarchy Sales assigned only outside the workspace;
  - forged Supervisor lead branch/project ownership;
  - ended historical mapping outside the workspace, including export exclusion.
- Added an idempotent Oasis Changelog migration:
  `2026_10_27_144831_add_supervisor_sales_scope_changelog.php`.

## Rulings

- Existing pivot-only behavior is preserved: a Sales user in an allowed branch with no current project assignment remains eligible for the existing team display behavior.
- Because the requirement states that ended mappings are for workspace-filtered monitoring/export only, Supervisor lead detail policy uses current mappings (`includeHistorical: false`). Task 2 remains responsible for any historical edit-flow work.
- No new permission, route, authorization architecture, or dormant module was added.

## Verification

- RED baseline confirmed before implementation:
  - 2 new workspace/policy tests failed against the existing code.
  - 1 new historical monitoring/export test failed against the existing code.
- Focused suite: **28 tests, 156 assertions passed**.
- Related scope/history suite: **53 tests, 345 assertions passed**.
- Full suite: **1,433 tests, 10,490 assertions passed**.
- `vendor/bin/pint --dirty --format agent`: passed.
- `git diff --check`: passed.
- Migration applied successfully; read-only database verification found exactly one matching changelog row.

## Concerns

- The repository already contained unrelated untracked `.playwright-mcp/` and `docs/superpowers/` paths. They were not modified or staged.

## Round 1 Reviewer Fix

Addressed all six reviewer findings without changing the `for()` result shape, Supervisor read-only policy, current-team boundaries, dormant gates, routes, or the existing Task 1 changelog.

- Monitoring/export project IDs now intersect `OrganizationScopeService::projectIds()` with workspace-accessible active projects.
- Supervisor direct-detail access no longer widens an empty organization project scope to every accessible branch project.
- Historical monitoring uses the workspace project scope independently of current Sales project assignment, so ended mappings without a current project assignment remain visible only when their lead is in scope.
- `forWorkspace()` applies the same project-aware assignment predicate to Supervisors and Coordinators, including historical assignment semantics.
- Monitoring filter authorization now validates Coordinator/Sales selections against the already workspace-filtered historical team.
- Added regressions for same-branch unrelated projects, empty project scope, project-aware Coordinator filtering, historical Sales without current project assignment, monitoring, export, and direct-detail denial.
- Updated the existing hierarchy fixture to explicitly assign the Supervisor to its authorized project, matching the now-enforced organization project-scope contract.

### Exact verification commands and output

RED baseline:

```text
$ php artisan test --compact tests/Feature/SupervisorSalesPocketbookTest.php
failed: tests 20, passed 17, assertions 103, failures 2, errors 1
```

Focused reviewer regression suite:

```text
$ php artisan test --compact tests/Feature/SupervisorSalesPocketbookTest.php tests/Feature/SalesPocketbookScopeMatrixTest.php
passed: tests 31, assertions 169
```

Affected hierarchy regression suite:

```text
$ php artisan test --compact tests/Feature/SalesRoleWorkspaceScopeTest.php
passed: tests 3, assertions 49
```

Full suite:

```text
$ php artisan test --compact
passed: tests 1436, assertions 10503
```

Formatting and whitespace:

```text
$ vendor/bin/pint --dirty --format agent
passed

$ git diff --check
passed (no output)
```

Graph refresh:

```text
$ graphify . --update --no-viz --code-only
[graphify extract] wrote graphify-out/graph.json: 9277 nodes, 24136 edges, 782 communities
```
