# Task 1 Report

## Implementation
- Added nullable `users.sales_type`, `users.staff_type`, and `users.scope_level` in additive guarded migration.
- Backfilled only null `staff_type`: `admin` → `admin_cabang`, `sas` → `sales`, `sam` → `marketing`, legacy `staff` → `umum`.
- Backfilled only null `scope_level`: no `branch_id` → `pusat`, branch present → `cabang`.
- Added `ass_manager` with UI name `Ass. Manager`, manager authority level, non-superadmin metadata, and explicit copy of manager permission mapping.
- Added `User` fillable and string casts.
- Added idempotent changelog row. No role IDs or primary roles changed. No production cutover executed.

## Tests
- RED: `php artisan test --compact tests/Feature/PrdV3RoleAttributesTest.php` failed before implementation: missing `ass_manager` and missing user attribute columns.
- GREEN: `php artisan test --compact tests/Feature/PrdV3RoleAttributesTest.php` passed: 2 tests, 22 assertions.
- Regression: `php artisan test --compact tests/Feature/RolePermissionTest.php` passed: 9 tests, 52 assertions.
- Formatting: `vendor/bin/pint --dirty --format agent` passed.
- Diff check: `git diff --check` passed.
- Fresh migration/seed: `php artisan migrate:fresh --seed --force` passed.

## Files
- `database/migrations/2026_11_20_200027_add_prd_v3_user_attributes_and_ass_manager.php`
- `database/seeders/RoleSeeder.php`
- `app/Models/User.php`
- `tests/Feature/PrdV3RoleAttributesTest.php`

## Self-review
- Migration uses `Schema::hasColumn` guards and `updateOrInsert` for idempotence.
- Backfill filters null values, preserving pre-existing attributes.
- Legacy users retain original `role_id`; migration does not deactivate roles.
- Ass. Manager permission mapping is explicit and independent of seeder execution.
- Rollback deletes only migration-created changelog and unused Ass. Manager role; drops only attributes present.

## Concerns
- Ass. Manager permission mapping copies current manager pivot rows during migration. Future permission catalog changes require the existing permission sync path or a later migration.
- Test manually invokes migration after `RefreshDatabase` to prove backfill; normal fresh migration execution also passed.
