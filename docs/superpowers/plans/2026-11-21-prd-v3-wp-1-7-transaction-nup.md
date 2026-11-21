# WP-1.7 Status, Event Transaksi, dan NUP Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Menerapkan status transaksi kanonis, typed transaction events, dan lifecycle NUP sesuai PRD v3 BR-20–BR-36 tanpa merusak data legacy, scope, atau invariant kavling.

**Architecture:** Pertahankan `consumer_stage_events` sebagai tabel riwayat dengan kolom additive `event_type`: `stage` tetap untuk milestone Proses Penjualan, `event_type` untuk event transaksi. Status transition dan event append dimiliki service lifecycle, sementara `ConsumerKavlingLifecycleService` tetap satu-satunya pemilik mutasi kavling. NUP menyimpan provenance dan menjalankan Kembali ke NUP/konversi dalam transaksi DB atomik.

**Tech Stack:** Laravel 13, PHP 8.3, Eloquent, Blade, Alpine.js, Tailwind CSS, PHPUnit, SQLite tests, existing AppSetting/OrganizationScope/WorkspaceAccess services.

**Spec:** `docs/superpowers/specs/2026-11-21-prd-v3-wp-1-7-transaction-nup-design.md`; PRD v3 BR-20–BR-36.

## Global Constraints

- Canonical transaction statuses: `LANJUT`, `MUNDUR`, `DITUTUP`.
- `DITUTUP` only via `GANTI_NAMA` or `KEMBALI_KE_NUP`; never manual.
- Transaction events are not process stages; no event may advance `current_stage` or `current_process`.
- NUP has no kavling; one active `LANJUT` transaction per kavling.
- Preserve immutable `id_transaksi`; preserve legacy reads without fabricated history.
- All mutations require existing account, permission, module, workspace, organization-scope, and policy checks.
- Additive migrations and one idempotent Indonesian changelog migration per behavioral change set.
- No production flag activation, data cutover, dependency addition, secret, or external Sheet change.
- Feature flag `transaction_events_v3` defaults off; flag-off compatibility remains tested.

## Review Focus

- Existing `consumer_status` and `transaction_status` consumers must display canonical semantics without treating events as statuses; covered by Tasks 1 and 5.
- Invalid/missing configured withdrawal reason must rollback all writes, including kavling release and event/audit; covered by Task 1.
- Event rows must not enter process-stage queries or alter process position; covered by Task 2.
- Kembali ke NUP must be atomic under retry/failure and preserve old transaction history; covered by Task 3.
- NUP conversion and kavling assignment must remain idempotent and collision-safe; covered by Task 3.
- Direct URL, JSON, supplemental role, and out-of-scope access must not bypass BR-23 or lifecycle scope; covered by Task 4.

---

### Task 1: Canonical transaction status and configurable Mundur reasons

**Files:**
- Create: additive migration for canonical transaction fields and withdrawal reason, plus idempotent changelog.
- Modify: `app/Models/ConsumerApplication.php`.
- Modify: `app/Services/ConsumerOperationalService.php`.
- Modify: `app/Services/ConsumerApplicationLifecycleService.php`.
- Modify: `app/Http/Requests/ConsumerLifecycleRequest.php` and existing settings catalog/resolver only as required.
- Test: `tests/Feature/ConsumerCanonicalDomainContractTest.php`, `tests/Feature/ConsumerLifecycleHttpTest.php`, focused new status tests if existing files cannot hold coverage.

**Interfaces:**
- `ConsumerApplication::TRANSACTION_STATUSES` exposes `LANJUT`, `MUNDUR`, `DITUTUP`.
- `ConsumerApplication::canonicalTransactionStatus(): string` maps legacy persisted values for reads.
- `ConsumerApplicationLifecycleService::mundur(ConsumerApplication $application, string $withdrawReason, User $actor): ConsumerApplication` validates configured reason and performs atomic status/release/event delegation.
- Existing `ConsumerOperationalService::statuses()` no longer presents event names as new transaction statuses.

- [ ] Write failing BR-30 tests: only canonical statuses are accepted for new writes; manual `DITUTUP` is rejected; legacy values map for display/reporting.
- [ ] Write failing BR-31 tests: default reasons, changed configured list, missing/invalid reason, and rollback with held kavling.
- [ ] Run focused tests and confirm RED.
- [ ] Add additive fields/settings and idempotent changelog; keep legacy columns readable.
- [ ] Implement canonical status mapping and `Mundur` reason validation through existing settings resolver.
- [ ] Update lifecycle request/controller call path without duplicating authorization or kavling logic.
- [ ] Run focused tests, Pint, and diff check.
- [ ] Commit `feat: add canonical transaction statuses and withdrawal reasons`.

### Task 2: Typed transaction-event storage and existing lifecycle writers

**Files:**
- Create: additive migration adding nullable `event_type` to `consumer_stage_events`, with changelog if separate.
- Modify: `app/Models/ConsumerStageEvent.php` and `app/Models/ConsumerApplication.php`.
- Modify: `app/Services/ConsumerOperationalService.php`.
- Modify: `app/Services/ConsumerApplicationLifecycleService.php`.
- Modify: `app/Services/ConsumerKavlingLifecycleService.php` only where event append must wrap existing atomic mutation.
- Test: `tests/Feature/ConsumerOperationalTest.php`, `tests/Feature/ConsumerLifecycleOrchestrationTest.php`, `tests/Feature/ConsumerKavlingLifecycleTest.php`.

**Interfaces:**
- `ConsumerStageEvent::TRANSACTION_EVENT_TYPES` lists `PINDAH_KAVLING`, `GANTI_NAMA`, `GANTI_BANK`, `KEMBALI_KE_NUP`, `KOREKSI_DATA`, `MUNDUR`.
- `ConsumerApplication::transactionEvents()` returns only events with non-null `event_type`.
- Service-level append helper accepts application, event type, actor, source, reason/notes, and metadata; controllers never create event rows.

- [ ] Write failing BR-32 tests proving typed transaction events do not change process stage/process and are excluded from process-stage queries.
- [ ] Write failing BR-33/BR-34 tests proving Ganti Nama and Pindah Kavling create typed events, preserve links/history, and preserve `id_transaksi`.
- [ ] Write failing BR-32/BR-34 tests proving Ganti Bank uses typed `GANTI_BANK` while bank attempts and process facts remain intact.
- [ ] Run focused tests and confirm RED.
- [ ] Add nullable `event_type` and model constants/relation.
- [ ] Add centralized event append inside existing service transactions; map legacy status writes to canonical status while preserving historical values.
- [ ] Ensure process writers and historical import continue treating `stage` as process stage only.
- [ ] Run focused tests, Pint, and diff check.
- [ ] Commit `feat: add typed transaction event history`.

### Task 3: NUP provenance, Kembali ke NUP, and conversion invariant

**Files:**
- Create: additive migration for `consumer_nups.source_application_id`, `issue_id`, `entry_type`, indexes/FKs, and changelog.
- Modify: `app/Models/ConsumerNup.php`, `app/Models/ConsumerApplication.php`, `app/Models/ConsumerIssue.php` relations.
- Modify: `app/Services/ConsumerNupService.php`, `app/Services/ConsumerApplicationLifecycleService.php`.
- Modify: `app/Http/Requests/ConsumerNupRequest.php`, `app/Http/Controllers/Crm/ConsumerNupController.php`, `app/Http/Controllers/Crm/ConsumerLifecycleController.php`, and `routes/web.php`.
- Test: `tests/Feature/ConsumerV2ProcessTest.php`, `tests/Feature/ConsumerLifecycleHttpTest.php`, `tests/Feature/ConsumerLifecycleOrchestrationTest.php`, `tests/Feature/ConsumerKavlingLifecycleTest.php`, plus migration test if needed.

**Interfaces:**
- `ConsumerNup::ENTRY_TYPES` exposes `from_lead`, `returned`, `legacy`.
- `ConsumerNupService::createFromLead(...)` requires a Lead resolved at v3 `utj` and creates no kavling relation.
- `ConsumerNupService::returnFromTransaction(ConsumerApplication $application, ConsumerIssue $issue, array $data, User $actor): ConsumerNup` atomically closes source transaction, releases kavling, records `KEMBALI_KE_NUP`, and links issue/source transaction.
- `ConsumerNupService::convert(ConsumerNup $nup, array $data, User $actor): ConsumerApplication` requires available `kavling_id`, reuses Customer, and remains idempotent.

- [ ] Write failing BR-20 tests for UTJ provenance, no NUP kavling, and legacy NUP classification without fabricated history.
- [ ] Write failing BR-21 test for atomic return, kavling release, `DITUTUP`, issue/source links, event, audit, and rollback.
- [ ] Write failing BR-22 test for required kavling, Customer reuse, one new transaction, retry idempotency, and collision rejection.
- [ ] Write failing BR-25 test for concurrent/locked kavling assignment allowing one active `LANJUT` holder.
- [ ] Run focused tests and confirm RED.
- [ ] Add nullable provenance fields/FKs/indexes with safe legacy defaults/backfill semantics.
- [ ] Implement NUP origin and return flow through existing scope and kavling services; do not duplicate assignment logic.
- [ ] Make conversion require kavling while preserving existing idempotency behavior and legacy read compatibility.
- [ ] Add named route/request/controller adapters with JSON and redirect behavior matching existing conventions.
- [ ] Run focused tests, migration tests, Pint, and diff check.
- [ ] Commit `feat: add transactional NUP lifecycle`.

**Task 3 review follow-up (2026-11-21):**

- Conversion now rechecks selected project scope after resolving the project ID; BR-22 rejects an out-of-scope project without mutation.
- Kembali ke NUP now requires source canonical status `LANJUT` after transaction lock; BR-21 rejects `MUNDUR`, `DITUTUP`, and terminal legacy statuses before kavling release.
- `transaction_events_v3` remains default-off and typed event writes stay flag-gated.
- `ConsumerApplicationFactory` defaults new records to `LANJUT`; lifecycle scope HTTP regression supplies configured `withdraw_reason` so service authorization is exercised.
- Focused tests: 22 passed, 78 assertions. Full-suite verification pending.

### Task 4: BR-23 lead conversion authorization and audit

**Files:**
- Modify: `app/Http/Requests/Crm/ConvertSalesLeadConsumerRequest.php`.
- Modify: `app/Policies/SalesLeadPolicy.php`.
- Modify: `app/Services/SalesLeadLifecycleService.php`.
- Modify: existing lead conversion controller/view only where reason input and error display are required.
- Test: `tests/Feature/SalesLeadStageAuthorizationTest.php`, existing lead lifecycle tests, new BR-23-focused feature test if needed.

**Interfaces:**
- Pre-UTJ conversion receives mandatory exception reason only for eligible Admin Cabang, SAS, or Koordinator actors.
- `SalesLeadPolicy` remains the record authorization boundary; service rechecks stage, role/permission, scope, and idempotency.
- Audit payload identifies pre-UTJ exception and reason without exposing private data unnecessarily.

- [ ] Write failing BR-23 tests for allowed role + reason, missing reason, disallowed Sales, supplemental-role non-escalation, direct endpoint, JSON, and out-of-scope lead.
- [ ] Write failing BR-24 test for duplicate lead conversion and explicit existing-link idempotency.
- [ ] Run focused tests and confirm RED.
- [ ] Implement smallest policy/request/service changes using existing permission and role abstractions; do not compare raw role IDs.
- [ ] Add visible reason field/error only in existing conversion UI boundary.
- [ ] Run focused authorization tests, Pint, and diff check.
- [ ] Commit `feat: enforce pre-UTJ conversion authorization`.

### Task 5: Canonical status/event/NUP UI and query semantics

**Files:**
- Modify: `app/Services/ConsumerDatabaseWorkspaceService.php`, `app/Http/Controllers/WorkspaceV2/WorkspaceController.php`, and only related scoped query services.
- Modify: `resources/views/workspace-v2/transactions/index.blade.php`, `resources/views/workspace-v2/nup/index.blade.php`, `resources/views/crm/nups/index.blade.php`, and existing status component.
- Modify: existing event/detail view or add one small reusable view partial if needed.
- Test: `tests/Feature/WorkspaceV2FrontendTest.php` and relevant feature tests.

**Interfaces:**
- Transaction list uses canonical status display and excludes `DITUTUP` from active/gugur counts.
- NUP list displays provenance, issue, queue age, Sales owner, and prior transaction for returned NUP.
- Transaction history separates typed transaction events from process stages.
- Existing `x-crm.*` components, named routes, inline errors, and semantic confirmation patterns remain in use.

- [ ] Write failing BR-30/BR-31/BR-32 UI/query tests for status labels, counts, reason reporting, and separate event/process history.
- [ ] Write failing BR-20/BR-21 UI tests for NUP provenance, issue, prior transaction, and available actions.
- [ ] Implement query mappings in services, not Blade; keep views presentation-only.
- [ ] Implement minimal responsive markup with existing CRM components and accessible forms.
- [ ] Run focused frontend tests, `php artisan optimize:clear`, `php artisan view:cache`, and `npm run build`.
- [ ] Commit `feat: expose canonical transaction and NUP views`.

### Task 6: Historical compatibility, BR-35/BR-36 coverage, implementation log, and full verification

**Files:**
- Modify: historical import/process tests and only import code if regression exposes a behavior gap.
- Modify: `docs/product/PRD_V3_IMPLEMENTATION_LOG.md`.
- Create: one idempotent WP-1.7 changelog migration if prior tasks did not already consolidate it.
- Test: `tests/Feature/ConsumerOperationalTest.php`, historical import tests, full relevant suite.

- [ ] Add/rename tests for BR-35 SLIK-first and PSJB-first behavior.
- [ ] Add/rename BR-36 historical-position test proving no fabricated process/event history.
- [ ] Verify flag-off `transaction_events_v3` behavior and legacy status display.
- [ ] Update implementation log with delivered scope, BR test evidence, conflicts, migration safety, and no-cutover boundary.
- [ ] Run fresh SQLite migration verification and inspect migration on legacy-shaped records.
- [ ] Run all WP-1.7 focused tests.
- [ ] Run `composer test`, `vendor/bin/pint --dirty --test`, `git diff --check`, `php artisan optimize:clear`, `php artisan view:cache`, and `npm run build` if frontend changed.
- [ ] Run `graphify update .` if installed and inspect status for unintended generated changes.
- [ ] Review diff for secrets, PII, hardcoded URLs, scope bypass, and forbidden browser confirmation.
- [ ] Commit final documentation/verification changes separately if needed.

## Checkpoints

### Checkpoint A: Status and event foundation

- [ ] Tasks 1–2 complete.
- [ ] Focused status/event tests pass.
- [ ] Process-stage queries unaffected.
- [ ] No legacy data rewrite beyond additive migration/backfill.

### Checkpoint B: NUP domain

- [ ] Task 3 complete.
- [ ] Return-to-NUP rollback and idempotency pass.
- [ ] Kavling uniqueness remains enforced.
- [ ] `id_transaksi` unchanged.

### Checkpoint C: Authorization and UI

- [ ] Tasks 4–5 complete.
- [ ] Direct endpoint/scope/role tests pass.
- [ ] Blade cache and frontend build pass.
- [ ] Desktop/mobile visual review performed if UI changed.

### Checkpoint D: Handoff

- [ ] Task 6 complete.
- [ ] Full suite green.
- [ ] Pint and diff check clean.
- [ ] Branch contains no secrets or unrelated user files.
- [ ] Feature flag remains default off; no production cutover.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Legacy status fields feed dashboard/workspace queries | Wrong active/gugur counts or UI regressions | Centralize canonical mapping and add query/UI regression tests before changing consumers |
| Event rows pollute process history | Fake milestones or wrong current process | Nullable `event_type`, dedicated relation, explicit process query filters |
| Return-to-NUP partial mutation | Kavling loss or closed transaction without NUP | One DB transaction, row locks, failure rollback test |
| NUP conversion race | Two active transactions on one kavling | Existing lifecycle service, DB uniqueness, lock/concurrency test |
| BR-23 bypass through direct request/supplemental role | Unauthorized early conversion | Policy + request + service checks and direct JSON/URL tests |
| Legacy data lacks provenance | False historical claims | `legacy` entry type, additive nullable fields, no fabricated events |
| Scope expansion in UI/controller | Cross-branch data exposure | Existing `OrganizationScopeService`/`WorkspaceAccessService`, endpoint tests |

## Open Questions

- Confirm exact existing Pengaturan key for withdrawal reasons during Task 1; if absent, use existing settings catalog/resolver path and record decision in implementation log.
- Confirm whether event records must survive hard delete; no FK behavior change until product decision.
