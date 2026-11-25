# WP-2.4 Paket A Branch Migration State Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add per-branch migration state, audited adjacent transitions, Superadmin management UI, read-only guards for `oasis_penuh`, and scheduler skip behavior without changing any branch state automatically.

**Architecture:** Use one `BranchMigrationState` model and `BranchMigrationStateService` as source of truth for fallback, transition validation, locking, audit, and read-only checks. Add guards at existing service/command boundaries; keep read paths and menus unchanged. Use existing permission, ActivityLog, Blade/Alpine, JSON error, and logging patterns.

**Tech Stack:** Laravel 13, PHP 8.3, MySQL/SQLite, Blade + Alpine.js, PHPUnit, existing ActivityLog, cache, scheduler, and Google Sheets services.

**Spec:** `docs/superpowers/specs/2026-11-23-wp-2-4-branch-migration-design.md`

## Global Constraints

- All branches default to `sheet`; migration backfill never overwrites an existing state.
- Agent and commands never change branch state automatically.
- Valid transitions only: `sheet -> migrasi -> paralel -> oasis_penuh`; backward only one stage; reason required.
- `oasis_penuh` means read-only + scheduler skip only; do not hide Database/Konsumen Progress menus.
- ActivityLog only records state changes; scheduler skips use deduplicated application logs.
- No data deletion.
- Superadmin authorization must hold for direct URL and JSON requests; non-Superadmin gets 403.
- Use named routes, visible labels, inline validation, keyboard-accessible confirmation, Escape-close dialog; never browser `confirm()`.
- Add one idempotent changelog migration for behavior/UI/schema change.
- Preserve unrelated untracked files.

## Review Focus

- Rerunning migration after an operator change must preserve state; test in Task 1.
- Concurrent/stale transitions must not overwrite newer state; test in Task 3.
- Missing state row/new branch must behave as `sheet`; test in Task 2.
- Service/command write paths must be blocked, not only HTTP UI; test in Task 5.
- Scheduler skip must not create ActivityLog or fake sync success; test in Task 6.

---

### Task 1: Schema, model, constants, and default backfill

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_create_branch_migration_states_table.php`
- Create: `app/Models/BranchMigrationState.php`
- Modify: `app/Models/Branch.php`
- Test: `tests/Feature/BranchMigrationStateTest.php`

**Interfaces:**
- Produces `BranchMigrationState::STATES`, `BranchMigrationState::STATE_SHEET`, `BranchMigrationState::STATE_MIGRASI`, `BranchMigrationState::STATE_PARALEL`, `BranchMigrationState::STATE_OASIS_FULL`.
- Produces `Branch::migrationState(): HasOne` and model casts for `changed_at`.
- Migration creates `branch_migration_states(branch_id unique, state, changed_by nullable, changed_at nullable, notes nullable, timestamps)` and inserts missing branch rows as `sheet` without overwrite.

- [ ] Write tests for empty migration default, rerun idempotence, and preservation of an existing non-`sheet` row.
- [ ] Run focused tests and confirm RED before implementation.
- [ ] Implement migration/model/relation using existing project naming and additive migration style.
- [ ] Run focused tests and confirm GREEN.
- [ ] Run `vendor/bin/pint --dirty --format agent` and `git diff --check`.
- [ ] Commit schema/model/tests.

### Task 2: Resolver and state service read contract

**Files:**
- Create: `app/Services/BranchMigrationStateService.php`
- Modify: `app/Models/Branch.php` if relation/query helper needs it
- Test: `tests/Feature/BranchMigrationStateTest.php`

**Interfaces:**
- `public function stateFor(Branch|int $branch): string`
- `public function recordFor(Branch|int $branch): BranchMigrationState`
- `public function isOasisFull(Branch|int $branch): bool`
- Missing row resolves to `sheet` and does not write during read.
- `public function assertWritable(Branch|int $branch): void` throws domain exception with HTTP 403 mapping and Indonesian read-only message when state is `oasis_penuh`.

- [ ] Add tests for existing states, missing row fallback, and `assertWritable` on `sheet` versus `oasis_penuh`.
- [ ] Run focused tests RED.
- [ ] Implement minimal resolver and exception/error rendering integration following `bootstrap/app.php` JSON behavior.
- [ ] Run focused tests GREEN.
- [ ] Commit resolver/guard contract.

### Task 3: Locked adjacent transitions and ActivityLog audit

**Files:**
- Modify: `app/Services/BranchMigrationStateService.php`
- Create or modify: `app/Http/Requests/Crm/BranchMigrationStateRequest.php`
- Test: `tests/Feature/BranchMigrationStateTest.php`

**Interfaces:**
- `public function transition(User $actor, Branch $branch, string $expectedState, string $targetState, string $reason): BranchMigrationState`
- Requires active Superadmin, trimmed non-empty bounded reason, adjacent forward/backward target, and exact expected current state.
- Uses transaction + `lockForUpdate`; stale expected state fails without write.
- No-op and multi-step transitions fail without ActivityLog.
- Audit: subject `Branch`, event `branch_migration_state_changed`, properties `branch_id`, `from_state`, `to_state`, `reason`, `actor_id`, `changed_at`.

- [ ] Add tests for all valid forward/backward transitions, invalid jumps, no-op, blank reason, stale expected state, inactive/non-Superadmin actor, and exact ActivityLog properties.
- [ ] Run RED.
- [ ] Implement validation and locked transaction.
- [ ] Run GREEN, Pint, diff check.
- [ ] Commit transition/audit implementation.

### Task 4: Permission, route, controller, and Superadmin UI

**Files:**
- Modify: `app/Support/PermissionCatalog.php`
- Create: idempotent permission/changelog migration under `database/migrations/`
- Modify: `routes/web.php`
- Create: `app/Http/Controllers/Crm/BranchMigrationStateController.php`
- Create: `resources/views/crm/branch-migration-states/index.blade.php`
- Modify: existing Superadmin navigation view/service only if required
- Test: `tests/Feature/BranchMigrationStateTest.php`

**Interfaces:**
- Named routes under existing admin convention: index and update; exact names follow verified sibling route conventions.
- Index lists active and inactive branches with current state and audit metadata.
- Update accepts expected state and reason, confirms through existing Alpine dialog, then calls service.
- Permission registered and mapped only to Superadmin; policy/middleware rejects `pusat` and all non-Superadmin roles.

- [ ] Add direct URL and JSON 403 tests for non-Superadmin; Superadmin success test; validation/redirect and JSON error tests.
- [ ] Run RED.
- [ ] Implement permission migration, routes/controller/view, no menu hiding for Database/Konsumen Progress.
- [ ] Run focused tests GREEN; run `npm run build` because Blade/Tailwind markup changed.
- [ ] Commit UI/authorization.

### Task 5: Database and Konsumen Progress read-only guards

**Files:**
- Modify: `app/Services/DatabaseSheetWriteService.php`
- Modify: `app/Services/DatabaseSheetImportService.php` if direct write path bypasses writer
- Modify: `app/Services/ConsumerEntryService.php`, `ConsumerOperationalService.php`, `ConsumerNupService.php`, and other verified consumer mutation boundaries only where branch context exists
- Modify: relevant HTTP controllers for early branch guard where needed
- Test: `tests/Feature/BranchMigrationStateTest.php` and existing module tests

**Interfaces:**
- Every Database/Konsumen Progress mutation for an `oasis_penuh` branch calls `assertWritable` before external/local write.
- `sheet`, `migrasi`, and `paralel` retain existing behavior.
- No delete behavior added or changed.

- [ ] Trace all actual write entry points before editing; add tests for representative HTTP and direct service paths.
- [ ] Run RED.
- [ ] Add guards at service boundaries, with controller handling delegated to existing error renderer.
- [ ] Run focused existing and new tests GREEN.
- [ ] Commit read-only guard.

### Task 6: Scheduler branch filtering and deduplicated skip logging

**Files:**
- Modify: verified Database sync command/service entry point
- Modify: `app/Console/Commands/SyncKonsumenProgress.php` / service
- Modify: `app/Console/Commands/SyncSalesLeadLifecycle.php` / service
- Create: small existing-pattern logging helper only if needed
- Test: `tests/Feature/BranchMigrationStateTest.php` and sync command/service tests

**Interfaces:**
- Branch-aware sync entry points skip `oasis_penuh`, including explicit `--branch`.
- Skip output reports `skipped`; integration service is not called; existing sync status/last-successful fields remain unchanged.
- Log message includes branch ID/name, sync type, state, reason; cache key is branch + sync type + application timezone date; duplicate same-day skip logs suppressed.
- No ActivityLog for skips.

- [ ] Add mocked integration tests for `sheet` called and `oasis_penuh` skipped across Database, Konsumen Progress, lifecycle lead; test dedup and unchanged status.
- [ ] Run RED.
- [ ] Implement filtering at verified command/service boundary.
- [ ] Run focused tests GREEN.
- [ ] Commit scheduler guards.

### Task 7: Package A verification and push

**Files:**
- Modify only if verification exposes defects.

- [ ] Run focused Paket A suite.
- [ ] Run `composer test`.
- [ ] Run `vendor/bin/pint --dirty --format agent`, `git diff --check`, `npm run build`, and `php artisan optimize:clear && php artisan view:cache`.
- [ ] Inspect diff, confirm no branch state was changed by tests/commands and unrelated untracked docs remain untouched.
- [ ] Commit any required changelog/documentation update.
- [ ] Push branch `prd-v3/wp-2.4-a-branch-migration-state`.
- [ ] Stop for human review. Do not begin Paket B or merge before explicit approval.
