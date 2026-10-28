# Final Fix Report — Conflict Resolution Access Matrix

## Status

- Addressed both Task 3 review findings on feature HEAD `d69b314`.
- No migrations, routes, frontend source, or generated assets were changed; protected untracked artifacts were not staged.

## Files changed

- `app/Services/OrganizationScopeService.php`
- `app/Services/CrmAuthorizationService.php`
- `tests/Feature/SupervisorSalesPocketbookTest.php`
- `tests/Feature/AdminUserDirectActivationTest.php`

## Fix reasoning

- Supervisor `sales_pocketbook` visible users now use the centralized team scope instead of unioning branch/assigned users from the legacy branch permission.
- Supervisor sales scopes now require project filtering, and the existing branch early return in `CrmAuthorizationService::allowsRecord()` no longer bypasses the Supervisor team/project boundary.
- The existing Supervisor project-boundary exception now uses the same centralized predicate, keeping generic `SalesLead::visibleTo()`, direct record authorization, and monitoring-compatible scope calculations aligned without changing Admin, Branch Manager, or Coordinator behavior.
- Added generic lead-query and direct-service regression coverage for allowed team/project leads, same-branch non-team owners, and same-team leads on unrelated projects.
- Restored explicit direct-activation assertions for prohibited `submit_action` and `send_immediately` values without changing `AdminUserStoreRequest`.

## Verification

- Focused Supervisor/authorization/onboarding suite: PASS (64 tests, 581 assertions).
- Targeted generic-query and direct-activation tests: PASS (2 tests, 12 assertions).
- `vendor/bin/pint --dirty --format agent`: PASS.
- `git diff --check`: PASS.
- `php artisan optimize:clear`: PASS.
- `php artisan view:cache`: PASS.
- `php artisan route:list --except-vendor`: PASS.
- Full `php artisan test --compact`: PASS (1,483 tests, 10,992 assertions).

## Residual concerns

- The local database retains the 11 pending main-era migrations documented by Task 3; this fix intentionally does not alter migration history or apply migrations.
