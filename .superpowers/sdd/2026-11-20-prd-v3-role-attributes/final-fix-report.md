# Final WP-1.3 Fix Wave Report

## Scope

Implemented only fixes listed in `final-fix-brief.md`:

- Preserved legacy primary `staff` role permissions when `staff_type` is null.
- Kept unknown non-null `staff_type` values from granting mapped permissions.
- Made migration `down()` non-destructive for `ass_manager` roles and permission pivots.
- Expanded role migration dry-run output with candidate, role-change, attribute-change, target-role, and conflict state.
- Removed EOF blank line from `task-1-report.md`.

No production cutover executed. No D32 permission added. Supplemental `role_user` memberships remain excluded from permission resolution.

## TDD evidence

### RED

`php artisan test --compact tests/Feature/PrdV3RolePermissionParityTest.php`

Failed before fix: null primary `staff` user resolved zero permissions instead of legacy primary role permissions.

`php artisan test --compact tests/Feature/PrdV3RolePermissionParityTest.php tests/Feature/PrdV3RoleMigrationCommandTest.php tests/Feature/PrdV3RoleAttributesTest.php`

Failed after regression tests were added: dry-run lacked attribute-change and target/conflict output.

### GREEN

`php artisan test --compact tests/Feature/PrdV3RolePermissionParityTest.php tests/Feature/PrdV3RoleMigrationCommandTest.php tests/Feature/PrdV3RoleAttributesTest.php`

Passed: 13 tests, 74 assertions.

## Code changes

- `app/Models/User.php`
  - Primary `staff` with `staff_type === null` now resolves `role->permissions`, preserving transition behavior for existing legacy users.
  - Known non-null types still resolve only through canonical `PermissionCatalog::staffTypePermissions()` mapping.
  - Unknown non-null types resolve no mapped permission.
- `database/migrations/2026_11_20_200027_add_prd_v3_user_attributes_and_ass_manager.php`
  - `down()` now removes only its own changelog row.
  - Existing `ass_manager` role, `role_permission` pivots, `role_user` pivots, and user columns remain untouched.
- `app/Console/Commands/MigrateRolesV3.php`
  - Dry-run reports candidate count, role changes, attribute changes, target `staff` availability, and conflict state.
  - Dry-run remains read-only.
- Regression tests cover null legacy permission fallback, unknown type denial, non-destructive rollback, dry-run report, missing target conflict, snapshot parity, and supplemental-role isolation.
- `.superpowers/sdd/2026-11-20-prd-v3-role-attributes/task-1-report.md`
  - Removed trailing blank EOF line.

## Verification

- Focused role suite:
  - `php artisan test --compact tests/Feature/PrdV3RoleAttributesTest.php tests/Feature/PrdV3RolePermissionParityTest.php tests/Feature/PrdV3RoleMigrationCommandTest.php`
  - Passed: 13 tests, 74 assertions.
- Existing permission regression:
  - `php artisan test --compact tests/Feature/RolePermissionTest.php`
  - Passed: 9 tests, 52 assertions.
- Formerly failing full-suite tests:
  - `AdminUserImportDirectActivationTest.php`: passed, 6 tests, 91 assertions.
  - `BranchTransferHistoricalAccessTest.php`: passed, 19 tests, 160 assertions.
  - `ModuleMaintenanceTest.php`: passed, 13 tests, 650 assertions.
  - `WorkPlannerTest.php`: passed, 26 tests, 172 assertions.
  - `SalesAgendaWorkspaceRenderTest.php`: failed, 2 known receipt-render assertions.
- `composer test`
  - Failed: 1,837 tests, 1,835 passed, 2 failed, 13,286 assertions.
  - Both failures are `SalesAgendaWorkspaceRenderTest` receipt-render assertions; no new WP-1.3 role failure.
- `vendor/bin/pint --dirty --format agent`
  - Passed.
- `php artisan optimize:clear`
  - Passed.
- `php artisan view:cache`
  - Passed.
- `git diff --check`
  - Passed on staged diff and committed diff.

## Commit

`40b5801 fix: close final WP-1.3 role review findings`

## Concerns

- Full suite remains red only in two pre-existing `SalesAgendaWorkspaceRenderTest` receipt-render assertions.
- Existing untracked `.superpowers/sdd/2026-11-20-prd-v3-role-attributes/` review artifacts were not added except `task-1-report.md` and this report.
