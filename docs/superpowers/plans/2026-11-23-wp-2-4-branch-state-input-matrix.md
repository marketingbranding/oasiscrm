# WP-2.4 Branch State Input Matrix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Enforce per-branch input ownership using one `BranchMigrationStateService` policy without changing global Workspace availability.

**Architecture:** Add three boolean policy methods to `BranchMigrationStateService`; consumer OASIS services reject `sheet`/`migrasi`, while sheet mirror services reject writes on `paralel`/`oasis_penuh` and skip sync only on `oasis_penuh`. Keep guards at service boundaries so HTTP, commands, and jobs share behavior.

**Tech Stack:** Laravel 13, PHP 8.3, PHPUnit, Blade, existing Google Sheets services, SQLite test DB.

**Spec:** `docs/superpowers/specs/2026-11-23-wp-2-4-branch-state-input-matrix-design.md`

## Global Constraints

- `OASIS_NEW_WORKSPACE_ENABLED` remains global feature availability switch.
- Status values remain `sheet`, `migrasi`, `paralel`, `oasis_penuh`.
- No automatic branch status changes or production cutover.
- No new dependency or second access architecture.
- User-facing denial for OASIS input: `Cabang ini masih memakai spreadsheet untuk proses penjualan.`
- Every behavior change gets idempotent changelog migration.
- Tests must mock external Google integrations.

## Review Focus

- Missing state resolves to `sheet`; OASIS input must reject with friendly message.
- `migrasi` keeps old sheet write and sync behavior.
- `paralel` permits OASIS input, rejects every sheet write, but still syncs sheet.
- `oasis_penuh` permits OASIS input and rejects/skips all sheet mutations and syncs.
- Global Workspace flag false still hides feature regardless of branch state.

---

### Task 1: Centralize branch policy

**Files:**
- Modify: `app/Services/BranchMigrationStateService.php`
- Test: `tests/Feature/BranchMigrationStateTest.php`

**Interfaces:**
- Produce `allowsOasisConsumerInput(Branch|int $branch): bool`.
- Produce `allowsSheetWrite(Branch|int $branch): bool`.
- Produce `allowsSheetSync(Branch|int $branch): bool`.
- Produce friendly authorization guards for OASIS input and sheet writes, preserving `AuthorizationException` behavior.

- [ ] Write failing tests for all four states and exact OASIS denial message.
- [ ] Run focused test and confirm failure.
- [ ] Implement policy methods from matrix: input only `paralel`/`oasis_penuh`; sheet write except `paralel`/`oasis_penuh`; sheet sync except `oasis_penuh`.
- [ ] Add `assertOasisConsumerInputAllowed()` and `assertSheetWriteAllowed()` using policy methods.
- [ ] Run `vendor/bin/phpunit --colors=never tests/Feature/BranchMigrationStateTest.php`.
- [ ] Commit policy and tests.

### Task 2: Apply policy to OASIS consumer services

**Files:**
- Modify: `app/Services/ConsumerEntryService.php`
- Modify: `app/Services/ConsumerOperationalService.php`
- Modify: `app/Services/ConsumerNupService.php`
- Test: `tests/Feature/PrdV3D32ConsumerInputTest.php` and relevant consumer feature tests

**Interfaces:**
- Consume `BranchMigrationStateService::assertOasisConsumerInputAllowed()` at every consumer mutation boundary.

- [ ] Add failing tests: `sheet` and `migrasi` reject OASIS input; `paralel` and `oasis_penuh` accept NUP/consumer/process input.
- [ ] Run focused tests and confirm failure.
- [ ] Inject existing service into the three consumer services; guard entry, NUP, process, and transaction-event mutations before DB writes.
- [ ] Preserve existing scope and permission checks.
- [ ] Run D32 and consumer process suites.
- [ ] Commit OASIS input policy.

### Task 3: Apply policy to sheet mirror writes and sync

**Files:**
- Modify: `app/Services/DatabaseSheetWriteService.php`
- Modify: `app/Services/DatabaseSheetSyncService.php`
- Modify: `app/Services/KonsumenProgressSyncService.php`
- Modify: `app/Services/SalesLeadLifecycleSyncService.php`
- Test: `tests/Feature/BranchMigrationStateTest.php` plus existing sheet/sync suites

**Interfaces:**
- Consume `assertSheetWriteAllowed()` for all Database sheet writes.
- Consume `allowsSheetSync()` before sync status/cache mutation and external Google calls.

- [ ] Add failing matrix tests: sheet writes allowed on `sheet`/`migrasi`, rejected on `paralel`/`oasis_penuh`; sync runs on first three states, skips on `oasis_penuh`.
- [ ] Run tests and confirm failure.
- [ ] Replace old `assertWritable()` calls with policy-specific guards and preserve deduplicated skip logs.
- [ ] Ensure skip result shape remains command-compatible: `ok`, `status`, `branch`, `message`, `summary`.
- [ ] Run focused sheet and sync suites.
- [ ] Commit mirror policy.

### Task 4: Update operator runbook and changelog

**Files:**
- Modify: `docs/product/PRD_V3_CUTOVER_RUNBOOK.md`
- Modify: `docs/product/PRD_V3_IMPLEMENTATION_LOG.md`
- Create: `database/migrations/2026_11_23_000002_add_wp24_input_matrix_changelog.php`

- [ ] Add precondition before first `paralel`: verify production `OASIS_NEW_WORKSPACE_ENABLED=true`, deploy, clear config cache, and smoke-test Workspace input.
- [ ] Document status matrix and rollback action: move branch back one state with reason; do not run automatic cutover.
- [ ] Add idempotent Indonesian changelog entry with `version = null`.
- [ ] Run migration and documentation checks.
- [ ] Commit runbook/changelog.

### Task 5: Full verification

- [ ] Run `vendor/bin/phpunit --colors=never` and require 0 failures.
- [ ] Run `vendor/bin/pint --dirty --format agent`.
- [ ] Run `git diff --check`.
- [ ] Run `php artisan optimize:clear && php artisan view:cache`.
- [ ] Run `npm run build` if Blade/Tailwind assets change.
- [ ] Run `graphify update .` if installed.
- [ ] Inspect `git status --short --branch`; branch must be clean before push.
- [ ] Amend or add commits, push branch, and stop for review. Do not start Paket B.
