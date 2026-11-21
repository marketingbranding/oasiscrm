
## Fix round 1 — reviewer findings

### Changes

- Routed `ConsumerEntryService` transaction status through `ConsumerApplication::validateNewTransactionStatus()`.
- Centralized new-write validation in `ConsumerApplication`; manual `DITUTUP` and arbitrary statuses now reject.
- Changed `ConsumerApplicationLifecycleService::gantiKonsumen()` old transaction write from legacy `DIGANTI_KONSUMEN` to canonical `DITUTUP`; legacy application status and replacement link remain unchanged.
- Changed warranty completion writer from `SELESAI` transaction status to canonical `LANJUT`; completion remains represented by process/warranty facts and `completedQuery()` still reads legacy `SELESAI` during transition.
- Fixed lifecycle audit helper to accept event/action; Mundur now records `consumer_mundur` / `mundur`, while Ganti Konsumen retains `consumer_ganti_konsumen` / `ganti_konsumen`.
- Unknown legacy transaction status now throws `DomainException` instead of silently mapping to `LANJUT`; known legacy values remain readable.

### Tests/checks

- Existing focused tests updated by prior round; no dependency install available for PHPUnit.
- PHP syntax checks and `git diff --check` run before commit.
- `vendor/bin/pint` unavailable because `vendor/` is absent.

### Concerns

- `ConsumerProcessService` completed query retains `SELESAI` read compatibility for historical rows; no new writer persists it.
- Typed events, NUP, and UI remain out of Task 1 scope.

## Fix round 2

- Historical entries preserve supplied legacy transaction statuses; non-historical entries use canonical validation.
- Completed query requires BAST plus completed warranty facts for canonical LANJUT; historical SELESAI remains readable.
- Added BR-30 manual DITUTUP regression and BR-31 held-kavling/custom-setting coverage.
- PHP syntax checks and git diff --check run; PHPUnit/Pint blocked because vendor is absent.

Fix round 3: corrected custom Mundur success, held-kavling rollback, historical legacy-status preservation, and manual DITUTUP tests. PHP syntax and git diff --check pass; PHPUnit/Pint blocked by absent vendor.
