# Task 3 report — Implement 1.4b shared hierarchical scope semantics

## Status

Implemented and committed.

## Commit

`a121499d59e67acc07071550f537bb9164d0f039`

Message: `feat: gate hierarchical organization scope`

## Changes

- Added `org_hierarchical_monitoring` gate in `OrganizationScopeService`.
- Preserved legacy scope path when flag is off.
- Added graph-mode-aware hierarchical descendant resolution for `legacy`, `shadow`, and `graph` modes.
- Added active workspace/branch/project intersections for hierarchical visible users.
- Added `scope_level=pusat` branch widening only for `manager`, `ass_manager`, and `supervisor` with existing branch permission.
- Kept `own` and `assigned` project semantics narrow.
- Kept `branch_manager` workspace bounded by role exclusion from pusat widening.
- Added internal `hasSpecialMonitoringGrant()` extension point. Always returns `false` pending WP-2.2.
- Added BR-51, BR-52, and BR-53 tests.

## Verification

- Focused tests: `22 passed`, `57 assertions`.
- `vendor/bin/pint --dirty --format agent`: passed.
- `git diff --check`: passed.
- `graphify update .`: passed; graph rebuilt.
- `php artisan view:cache`: passed.
- `php artisan optimize:clear`: cache clear failed because local `database/database.sqlite` is absent; config clear succeeded. Tests use isolated SQLite and pass.

## Scope exclusions

- No Task 4 consumer migration.
- No permission or authorization service added.
- No production graph cutover or feature flag activation.

## Concerns

- `php artisan optimize:clear` cannot clear database-backed cache in this worktree without `database/database.sqlite`; no code failure observed.
- Existing untracked planning/spec files were not touched.

# Round 1 review fixes

## Changes

- Hierarchical team descendants now intersect active users, workspace branches, project assignments, and authorized branch membership.
- Own scope remains available when no descendants exist.
- Legacy descendants filter through active-user queries before visibility.
- Coordinator path runs under enabled monitoring flag and remains limited to current valid Sales-team mapping plus active workspace branch membership.
- Scope caches include flag state, `scope_level`, and effective graph mode for visible users, branches, projects, teams, and hierarchy reads.
- Added idempotent Indonesian changelog migration: `database/migrations/2026_11_21_000001_add_hierarchical_organization_scope_changelog.php`.
- Added regression tests for inactive/cross-branch descendants, Coordinator isolation, and pusat own/assigned non-expansion.

## Commands and output

- `php artisan test --compact tests/Feature/OrganizationScopeServiceTest.php tests/Feature/OrganizationGraphModeReadsTest.php tests/Unit/FeatureFlagTest.php` — **25 passed, 63 assertions**.
- `vendor/bin/pint --dirty --format agent` — **passed**.
- `git diff --check` — **passed**.
- `$env:CACHE_STORE='array'; php artisan optimize:clear` — **passed** (`config`, `cache`, `compiled`, `events`, `routes`, `views` cleared).
- `php artisan view:cache` — **passed**.
- `graphify update .` — **passed**, graph rebuilt.

## Remaining concerns

- Full suite not run; task verification used all covering scope, graph-mode, and feature-flag tests.
- Existing untracked planning/spec files remain untouched.

# Round 2 review fixes

## Changes

- Strict workspace intersection now requires assigned users to also belong to authorized workspace branches.
- Coordinator `teamIds()` filters mapped Sales across every accessible workspace branch and authorized membership, including multi-branch workspaces.
- Coordinator hierarchical visibility fails closed when requested action has no registered scope permission.
- Cache semantic keys now include requested branch context and effective graph mode, plus flag and `scope_level`.
- Added regression tests for cross-branch assignment, multi-branch Coordinator isolation, unregistered action fail-closed, and changelog-backed behavior.

## Commands and output

- `php artisan test --compact tests/Feature/OrganizationScopeServiceTest.php tests/Feature/OrganizationGraphModeReadsTest.php tests/Unit/FeatureFlagTest.php` — **28 passed, 67 assertions**.
- `vendor/bin/pint --dirty --format agent` — **passed**.
- `git diff --check` — **passed**.
- `$env:CACHE_STORE='array'; php artisan optimize:clear` — **passed**.
- `php artisan view:cache` — **passed**.
- `graphify update .` — **passed**, graph rebuilt.

## Remaining concerns

- Full suite not run; covering scope, graph-mode, and feature-flag tests passed.
- Existing untracked planning/spec files remain untouched.

# Round 3 review fixes

## Changes

- Assigned/project hierarchical visibility now intersects descendant IDs with strict active workspace branch membership.
- Coordinator `teamIds()` now fails closed for explicit out-of-workspace branch context and uses requested branch for query and authorization filtering.
- Coordinator hierarchical visibility now requires `team` scope for team data; own-only returns viewer only, unregistered actions return empty.
- Team cache key now passes requested branch context into `semanticCacheKey()`.
- Added regression tests for cross-branch assigned descendants, explicit branch boundary, and Coordinator own-only scope.

# Round 4 fix

## Changes

- `teamIds()` now intersects project-assigned descendants with authorized workspace-branch membership before applying descendant visibility, preventing out-of-workspace project assignment leaks.
- Added BR-51 regression for direct `teamIds()` project-assignment workspace isolation.
- Added BR-51 flag-off baseline regression.
- Added BR-51 hierarchical scope regression across `legacy`, `shadow`, and `graph` modes.
- Added BR-51 cache regression proving distinct `teamIds()` branch contexts do not reuse results.

## Commands and output

- `php artisan test --compact tests/Feature/OrganizationScopeServiceTest.php tests/Feature/OrganizationGraphModeReadsTest.php tests/Unit/FeatureFlagTest.php` — **35 passed, 78 assertions**.
- `vendor/bin/pint --dirty --format agent` — **passed**.
- `git diff --check` — **passed**.
- `$env:CACHE_STORE='array'; php artisan optimize:clear` — **passed**.
- `php artisan view:cache` — **passed**.
- `graphify update .` — **passed**, graph rebuilt.

## Concerns

- Full suite not run; focused Task 3 coverage passed.
- Existing untracked planning/spec files remain untouched.

## Commands and output

- `php artisan test --compact tests/Feature/OrganizationScopeServiceTest.php tests/Feature/OrganizationGraphModeReadsTest.php tests/Unit/FeatureFlagTest.php` — **31 passed, 70 assertions**.
- `vendor/bin/pint --dirty --format agent` — **passed**.
- `git diff --check` — **passed**.
- `$env:CACHE_STORE='array'; php artisan optimize:clear` — **passed**.
- `php artisan view:cache` — **passed**.
- `graphify update .` — **passed**, graph rebuilt.

## Remaining concerns

- Full suite not run; covering scope, graph-mode, and feature-flag tests passed.
- Existing untracked planning/spec files remain untouched.

# Round 5 final fixes

## Changes

- `teamIds()` now keeps legacy project-assignment behavior when `org_hierarchical_monitoring=false`; strict project-user and authorized-branch intersection applies only when flag is enabled.
- Explicit branch context now fails closed outside viewer workspace and constrains non-Coordinator descendants to requested branch, preventing sibling workspace leakage in legacy, shadow, and graph modes.
- Added direct BR-51 flag-off `teamIds()` regression for legacy project-assignment baseline.
- Added BR-51 branch-context regressions across legacy, shadow, and graph modes, including outside-workspace rejection.
- Added BR-51 fixture proving legacy and canonical graph reporting sources differ; graph mode includes canonical-only descendant while legacy/shadow use legacy supervisor source.

## Commands and output

- `php artisan test --compact tests/Feature/OrganizationScopeServiceTest.php --filter='br51'` — **15 passed, 33 assertions**.
- `php artisan test --compact tests/Feature/OrganizationScopeServiceTest.php tests/Feature/OrganizationGraphModeReadsTest.php tests/Unit/FeatureFlagTest.php` — **37 passed, 91 assertions**.
- `vendor/bin/pint --dirty --format agent` — **passed**.
- `git diff --check` — **passed**.
- `$env:CACHE_STORE='array'; php artisan optimize:clear` — **passed**.
- `php artisan view:cache` — **passed**.
- `graphify update .` — **passed**, graph rebuilt.

## Concerns

- Full suite not run; focused Task 3 suite passed.
- Existing untracked planning/spec files remain untouched.
