# Task 1 report

## Status

Implemented minimum Task 1 foundation: canonical transaction statuses, legacy read mapping, configurable Mundur reasons, atomic kavling release path, and idempotent changelog migration.

## Files

- `database/migrations/2026_11_21_140000_add_canonical_transaction_status_fields.php`: additive `closed_by_event` and `withdraw_reason`; idempotent Indonesian changelog.
- `app/Models/ConsumerApplication.php`: `TRANSACTION_STATUSES`, canonical legacy read mapping, new fillable fields.
- `app/Support/AppSettingCatalog.php`: `consumer_progress.mundur_reasons` with BR-31 defaults.
- `app/Services/ConsumerOperationalService.php`: canonical statuses API and new-write guard rejecting manual `DITUTUP`.
- `app/Services/ConsumerApplicationLifecycleService.php`: scoped atomic Mundur flow validates settings and delegates kavling release.
- `app/Http/Requests/ConsumerLifecycleRequest.php`: configured reason boundary validation.
- `app/Http/Controllers/Crm/ConsumerLifecycleController.php`: passes reason to lifecycle service.
- `tests/Feature/ConsumerCanonicalDomainContractTest.php`: BR-30/BR-31 coverage.
- `tests/Feature/ConsumerLifecycleHttpTest.php`: BR-31 missing reason coverage; existing Mundur request updated.

## Tests and checks

Attempted focused PHPUnit command:

```text
php artisan test --filter='br30|br31' tests/Feature/ConsumerCanonicalDomainContractTest.php tests/Feature/ConsumerLifecycleHttpTest.php
```

Blocked: `vendor/autoload.php` absent, so Laravel test runner cannot start.

PHP syntax checks passed for all 9 changed PHP files. `git diff --check` passed. Pint unavailable because `vendor/bin/pint` absent.

## Decisions

- Legacy `REPLACED`, `DIGANTI_KONSUMEN`, `CLOSED`, `COMPLETED`, and `SELESAI` map to `DITUTUP`; legacy withdrawal/reject values map to `MUNDUR`; event-like `Pindah Kavling` defaults to `LANJUT`.
- New non-historical writes accept only `LANJUT` or `MUNDUR`; manual `DITUTUP` rejected.
- `transaction_events_v3` remains default-off and untouched.
- Kavling mutation remains owned by `ConsumerKavlingLifecycleService`.
- Changelog uses `version = null` and `updateOrInsert`.

## Concerns

- Focused tests and Pint require dependency installation in worktree.
- Existing legacy writers outside Task 1 still persist historical values; model mapping preserves reads. Later WP-1.7 tasks must migrate event-specific writes.
- Task 1 intentionally does not add typed events, NUP lifecycle, UI, or production cutover.
