# Task 2 report — WP-1.4 canonical graph read parity

## Status

Implemented 1.4a parity diagnostics. Existing `OrganizationParityService` remains report source; no duplicate command or scope service added.

## Changes

- Added report metadata for configured read mode, effective read mode, and independent `org_hierarchical_monitoring` flag state.
- Added same read-mode/flag context to each viewer comparison.
- Added BR-50 coverage for independent mode/flag diagnostics, read-only report behavior, and invalid active `REPORTS_TO` integrity evidence.
- Preserved legacy/canonical comparison paths, existing filters, JSON command contract, and no-write command semantics.
- Did not modify `OrganizationScopeService`, production data, cutover, or backfill write.

## Verification

- Focused tests: `php artisan test --compact tests/Feature/OrganizationParityServiceTest.php tests/Feature/OrganizationParityCommandTest.php tests/Feature/OrganizationGraphModeReadsTest.php` — 40 passed, 175 assertions.
- `vendor/bin/pint --dirty --format agent` — passed.
- `git diff --check` — passed.
- `php artisan view:cache` — passed.
- `graphify update .` — passed.
- `php artisan optimize:clear` — failed because worktree lacks `database/database.sqlite`; only zero-byte `database/sqlite.db` exists. No code failure.

## Scope

Changed tracked files:

- `app/Services/OrganizationParityService.php`
- `tests/Feature/OrganizationParityServiceTest.php`

Pre-existing untracked plan/spec files were not modified or staged.

## Commit

Commit message: `feat: add organization graph read parity report`
