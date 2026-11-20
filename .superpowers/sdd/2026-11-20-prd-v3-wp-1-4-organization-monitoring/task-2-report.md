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

## Round 1 fixes

- Removed invalid active reporting IDs from canonical `graph_ids`, module user access, expansion, and security-critical calculations; retained them in `details.invalid_graph_ids` and integrity mismatch classification.
- Added BR-50 effective-date coverage for future, ended, and current `REPORTS_TO` edges.
- Added BR-50 report-level switch coverage for `legacy`, `shadow`, and `graph` modes with independent monitoring flag state.
- Added explicit `effective_read_modes` and `mixed` aggregate mode for multi-viewer reports.
- Added fail-closed nonexistent active branch filter handling and readiness blocker.
- Updated legacy cycle and cross-branch tests to reflect invalid-edge non-access semantics.

## Round 1 verification

- `php artisan test --compact tests/Feature/OrganizationParityServiceTest.php tests/Feature/OrganizationParityCommandTest.php tests/Feature/OrganizationGraphModeReadsTest.php` — 44 passed, 200 assertions.
- `vendor/bin/pint --dirty --format agent` — passed.
- `git diff --check` — passed.
- `$env:CACHE_STORE='array'; php artisan optimize:clear` — passed.
- `php artisan view:cache` — passed.
- `graphify update .` — passed.

## Round 1 commit

Commit: `e4ec1e0` (`fix: correct organization graph parity diagnostics`)
