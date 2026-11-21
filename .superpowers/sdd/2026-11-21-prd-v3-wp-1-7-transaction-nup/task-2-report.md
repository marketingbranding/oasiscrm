# Task 2 Report

Status: DONE

Implemented typed transaction event storage and minimum lifecycle writers.

- Added nullable `event_type` and indexed transaction-event lookup to `consumer_stage_events`.
- Added idempotent Indonesian changelog migration.
- Added `ConsumerStageEvent::TRANSACTION_EVENT_TYPES`.
- Added `ConsumerApplication::transactionEvents()` relation.
- Added service-level `appendTransactionEvent()` with type validation, actor, source, notes, reason, metadata, and timestamp.
- Ganti Bank writes `GANTI_BANK` while retaining bank attempt/process stage history.
- Ganti Konsumen writes `GANTI_NAMA`.
- Pindah Kavling writes `PINDAH_KAVLING` after existing kavling lifecycle mutation inside one outer transaction with audit.
- Added BR-32, BR-33, and BR-34 regression tests proving event rows stay outside process-stage relation, Ganti Nama closes and links replacement history, and occupied Pindah Kavling rollback writes no event.

Verification:

- `php artisan test --compact tests/Feature/ConsumerOperationalTest.php` — 13 passed, 39 assertions.
- `vendor/bin/pint --dirty --test` — passed.
- `git diff --check` — passed.

Scope exclusions: NUP, UI, authorization, production flag activation, and external integrations unchanged.

## Review fix

- Wrapped Pindah Kavling operational mutation, typed `PINDAH_KAVLING` event append, and audit write in one outer `DB::transaction`.
- Added BR-33 coverage for typed `GANTI_NAMA` event, replacement link, closed status, and immutable transaction IDs.
- Added BR-34 coverage for occupied-target rejection with no typed event and no half move.

Verification:

- `php artisan test --compact tests/Feature/ConsumerLifecycleOrchestrationTest.php tests/Feature/ConsumerKavlingLifecycleTest.php tests/Feature/ConsumerOperationalTest.php tests/Feature/ConsumerCanonicalDomainContractTest.php` — 43 passed, 147 assertions.
