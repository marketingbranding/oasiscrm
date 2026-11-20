# Task 3 Report — PRD v3 Role Transition Command

## RED evidence

Command:

```text
php artisan test --compact tests/Feature/PrdV3RoleMigrationCommandTest.php
```

Initial result: failed, 3 tests, 0 passed, 3 errors.

- `oasis:roles-v3-migrate` did not exist.
- Test setup also exposed a collection helper mistake, corrected before GREEN.

Command behavior was absent before implementation, confirming RED.

## GREEN evidence

Focused command tests:

```text
php artisan test --compact tests/Feature/PrdV3RoleMigrationCommandTest.php
```

Result: passed, 3 tests, 24 assertions.

Combined PRD v3 role foundation tests:

```text
php artisan test --compact tests/Feature/PrdV3RoleAttributesTest.php tests/Feature/PrdV3RolePermissionParityTest.php tests/Feature/PrdV3RoleMigrationCommandTest.php
```

Result: passed.

Formatting:

```text
vendor/bin/pint --dirty --format agent
```

Result: passed; Pint fixed PHPDoc alignment and import ordering.

Whitespace:

```text
git diff --check
```

Result: passed with no output.

## Files changed

- `app/Console/Commands/MigrateRolesV3.php`
  - Adds `oasis:roles-v3-migrate {--dry-run}`.
  - Resolves only primary legacy roles `admin`, `sas`, `sam`, and `staff`.
  - Dry-run performs read-only queries and reports candidate counts.
  - Non-dry-run uses one `DB::transaction`.
  - Writes `staff_type` and `scope_level` before changing `role_id` to active `staff` role.
  - Preserves user IDs and does not touch `role_user` supplemental memberships.
  - Deactivates legacy `admin`, `sas`, and `sam` roles only inside transaction after candidate mapping.
  - Never deletes roles.
  - Repeated runs find zero candidates and remain stable.
  - Handles missing legacy roles; missing `staff` role with candidates fails before writes.

- `tests/Feature/PrdV3RoleMigrationCommandTest.php`
  - Covers dry-run no mutation, including supplemental membership.
  - Covers idempotent mapping, role deactivation, ID preservation, and supplemental membership retention.
  - Covers `pusat`/`cabang` scope backfill.
  - Names/comments include BR/D6/D21/D25 references.

## Self-review

- Command auto-discovered by Laravel; `routes/console.php` unchanged.
- Dry-run has no transaction or write query.
- Legacy role lookup uses slugs, not IDs.
- Primary role controls migration; supplemental `role_user` rows remain untouched.
- `staff` role remains active as target role.
- Existing non-legacy primary roles are excluded.
- No production migration executed.

## Concerns

- Full application suite not run; task scope verification used focused role foundation and command tests.
- Legacy role permission checks elsewhere remain deferred to later WP slices, matching design scope.

## Commit

Pending exact commit: `feat: add dry run for PRD v3 role migration`

## Reviewer fix report

### Findings fixed

1. Non-dry-run now performs role lookup, target-role validation, candidate query, user mapping, and legacy-role deactivation inside one `DB::transaction`. Candidate rows are no longer snapshotted before transaction. Legacy roles use `lockForUpdate()` during transactional lookup. Missing target `staff` throws inside transaction, so all writes roll back.
2. Artisan attribute now exactly uses `#[Signature('oasis:roles-v3-migrate {--dry-run}')]`, with no option description.

### Verification

Focused command test:

```text
php artisan test --compact tests/Feature/PrdV3RoleMigrationCommandTest.php
```

Result: passed, 3 tests, 24 assertions.

Combined PRD role tests:

```text
php artisan test --compact tests/Feature/PrdV3RoleAttributesTest.php tests/Feature/PrdV3RolePermissionParityTest.php tests/Feature/PrdV3RoleMigrationCommandTest.php
```

Result: passed, 11 tests, 63 assertions.

Formatting:

```text
vendor/bin/pint --dirty --format agent
```

Result: passed.

Whitespace:

```text
git diff --check
```

Result: passed with no output.

### Files changed

- `app/Console/Commands/MigrateRolesV3.php`: moved all non-dry-run reads and writes into transaction; exact Artisan signature.

### Concerns

No new concerns. Full application suite not run.
