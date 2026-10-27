# Task 4 Implementation Report

## Status

Implemented KC/KCP transfer state and historical read-only access. The final commit hash is recorded in the completion response after commit.

## Changes

- Added `branch_user.is_current`, indexes for user/current and branch/current lookups, and an idempotent Indonesian Changelog entry.
- Existing memberships are preserved and default to current during migration; no existing membership rows are deleted or rewritten by the migration.
- `BranchAssignmentService` now keeps prior memberships as historical with `can_view=true` and all mutation/sync/member-management flags false, while new transfers create one current primary assignment.
- Transfer mutations are Superadmin-only in both `AdminUserController`/`UserAdministrationService` and the assignment service. Existing optimistic locking and account audit logging remain active.
- `WorkspaceAccessService` separates current accessible branches from historical branches. Historical memberships cannot satisfy edit, sync, or member-management checks.
- `OrganizationScopeService` includes historical branches/projects only for `view` scopes that include `own`; historical branches are excluded from manage/export/sync scopes.
- Sales lead listing/policy access includes only the transferred user’s own historical leads. Historical leads remain read-only for the transferred Sales user, with branch/project and Sales ownership unchanged.
- Content items owned by a transferred user can be viewed through the historical view path but cannot be updated.
- Original-branch Admin and Branch Manager users retain the required edit path for leads owned by transferred users, without restoring ordinary Buku Saku mutation permissions to either role.
- Returning a user to a previously historical branch does not promote that pivot or restore edit/sync access.
- Project assignment validation permits a historical project only to complete a Superadmin-approved return transaction; current workspace access still excludes that historical branch.
- Updated the existing direct assignment test to pass an explicit Superadmin actor for the now-protected transfer operation.

## Schema/backfill review

The existing `branch_user` schema already contained `membership_role`, `can_view`, `can_edit`, `can_sync`, and `can_manage_members`; no duplicate permission columns were added. The new `is_current` column defaults to `true`, preserving all existing memberships and legacy access until an explicit transfer occurs.

## Rulings

1. **Existing membership state** — Existing rows are treated as current during backfill because the old schema cannot distinguish an active additional membership from historical access. This is the smallest compatible behavior and avoids silently discarding branch access. Cost if wrong: an administrator may need to explicitly normalize legacy memberships later.
2. **Historical return** — A historical pivot is monotonic: returning to its branch updates `users.branch_id` but does not promote the historical pivot. This guarantees the required non-restoration of edit/sync access. Cost if wrong: the user can temporarily have no current editable branch until a new current assignment is created.
3. **Admin/Branch Manager historical lead edit** — Task 4 explicitly requires original-branch Admin/Branch Manager edit access while Task 3 removed their ordinary Buku Saku mutation grants. The implementation preserves the Task 3 permission mappings and adds only a narrowly scoped historical-owner policy exception. Cost if wrong: a later policy review may need to replace this exception with a dedicated registered capability.
4. **Return project validation** — A Superadmin return may retain/assign a project attached to a historical branch so the existing atomic user-update workflow completes, but current workspace queries still exclude that branch and the user cannot mutate historical records. Cost if wrong: project assignment UI should later model historical ownership separately.

## Verification

- `php artisan test --compact tests/Feature/BranchTransferHistoricalAccessTest.php` — passed, 6 tests / 38 assertions.
- `php artisan test --compact tests/Feature/BranchTransferHistoricalAccessTest.php tests/Feature/AdminUserOnboardingTest.php tests/Feature/BranchRenameTest.php` — passed, 17 tests / 86 assertions.
- Related assignment/onboarding regressions (`WorkspaceAccessTest`, `OrganizationAssignmentServiceTest`, `AdminUserDirectActivationTest`, `AdminUserImportDirectActivationTest`, `AdminUserImportOperationsTest`) — passed, 33 tests / 299 assertions.
- `php artisan test --compact` — passed, 1,391 tests / 10,248 assertions.
- `php artisan optimize:clear` — passed.
- `php artisan view:cache` — passed.
- `php artisan migrate --force` — applied the access-scope migration successfully.
- Schema verification confirmed `branch_user.is_current` and both indexes exist; database verification confirmed exactly one Changelog row for `Akses historis cabang setelah perpindahan pengguna`.
- Route inspection confirmed `admin-users.update` retains optimistic-lock and authorization middleware.
- `vendor/bin/pint --dirty --format agent` — passed.
- `git diff --check` — passed.
- `graphify update .` — completed.

## Concerns

- The current model has no separate historical assignment entity, so historical status is intentionally monotonic on the existing pivot. A future explicit reassignment workflow should create a new current assignment rather than promoting a historical row.

## Round 1 Reviewer Fix

### Findings addressed

- Branch transfers now preserve every omitted existing membership. Any prior current membership omitted during a transfer is retained and converted to historical read-only access; no pivot row is silently detached.
- The Admin/Branch Manager exception is explicitly limited to `SalesLeadPolicy::update` for a lead owned by a transferred user from the operator's current branch. Stage changes, reversals, lifecycle status changes, site visits, direct UTJ, consumer conversion, SLIK operations, and freelance conversion remain denied. This is the approved narrow historical edit path; no ordinary Admin/Branch Manager Buku Saku mutation permission was added.
- Historical Work Planner visibility is owner/assignee-only. Current branch assignments retain team visibility.
- Branch-based manage-scope user resolution now requires `branch_user.is_current = true`, so historical memberships cannot supply mutation targets.
- The migration continues to add `is_current` with a `true` default and performs no destructive normalization. The regression suite verifies that a user with two existing memberships retains both rows and both current flags before an explicit transfer, while the transfer regression verifies omitted memberships become historical with mutation flags disabled.

### Ruling

The historical Admin/Branch Manager edit path is intentionally a policy-only compatibility exception because the existing permission catalog has no dedicated historical-edit capability. It permits only generic lead edit access for an owned historical lead and never delegates lifecycle operations through that exception. A future dedicated permission may replace this path without changing the historical read-only contract.

### Verification

- `php artisan test --compact tests/Feature/BranchTransferHistoricalAccessTest.php` — passed, 11 tests / 72 assertions.
- `php artisan test --compact tests/Feature/OrganizationAssignmentServiceTest.php tests/Feature/OrganizationScopeServiceTest.php tests/Feature/WorkspaceAccessTest.php tests/Feature/AdminUserOnboardingTest.php tests/Feature/BranchRenameTest.php tests/Feature/AdminUserDirectActivationTest.php tests/Feature/AdminUserImportDirectActivationTest.php tests/Feature/AdminUserImportOperationsTest.php` — passed, 50 tests / 371 assertions.
- `php artisan test --compact` — passed, 1,396 tests / 10,282 assertions.
- `vendor/bin/pint --dirty --format agent` — passed.
- `git diff --check` — passed.
- `php artisan optimize:clear` — passed.
- `php artisan view:cache` — passed.
- `php artisan migrate:status --no-ansi` — `2026_10_27_120806_add_current_branch_state_to_branch_user_table` is `[10] Ran`.
- `php artisan route:list --name=admin-users.update --except-vendor` — one `PUT admin-users/{admin_user}` route remains registered as `admin-users.update`.
- `graphify update .` — completed; graph rebuilt with 9,166 nodes and 23,659 edges.
