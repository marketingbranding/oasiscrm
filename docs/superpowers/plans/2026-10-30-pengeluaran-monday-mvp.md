# Pengeluaran Monday MVP Implementation Plan

## Goal

Make Pengeluaran operationally usable for Tim Pusat without changing the finance architecture, global RBAC model, Buku Saku Sales, Dana Talangan, or organization graph behavior.

## Audit Classification

### PASS

- Permission-protected list, create, show, edit, update, cancel, project-options, and XLSX export routes exist.
- Pusat can access the workspace; Superadmin retains access; branch roles are denied by server-side policy/middleware behavior.
- Expense records retain project, branch, category, creator, notes, status, cancellation, activity history, and optimistic locking.
- Active-category loading, project/branch validation, filters, summary totals, pagination, and filtered XLSX export exist.
- Decimal money storage uses `decimal(15,2)` with an `Expense` `decimal:2` cast.
- Existing tests cover access, CRUD, cancellation, scope tampering, filters, summaries, export, and precision.

### BROKEN / MONDAY BLOCKERS

- New and updated expenses allow `project_id` to be null even though Project is the required reporting dimension for this MVP.
- The create/edit branch query does not select `is_active`, so active branches render with the misleading `(Tidak Aktif)` label.

### MISSING

- No evidence/upload field or relationship exists on Expense; no new upload architecture will be introduced for this MVP.

### NOT REQUIRED FOR MONDAY

- General ledger, double-entry accounting, budgeting, approvals, cost centers, charts, and broad finance redesign.
- Hard delete; existing cancellation/soft-delete semantics remain authoritative.

## Implementation Tasks

### Task 1: Enforce project-first expense input

- Add failing feature coverage for create/update rejection when `project_id` is absent.
- Require an active or preserved historical project according to existing branch/project validation.
- Mark the project control required and remove the misleading `Tanpa Proyek` create option while preserving inactive historical project visibility on edit.
- Correct the active-branch dropdown data so active branches do not display as inactive.
- Add an idempotent Indonesian Changelog migration.

### Checkpoint 1

- Focused Expense tests pass.
- Pusat create/update request tests pass.
- Unauthorized role tests remain green.

### Task 2: Browser operational verification

- Verify Pusat desktop create, project/category selection, save, reopen, edit, filter, summary, and export.
- Verify Pusat mobile at approximately 390x844 with no horizontal overflow.
- Verify manager/supervisor/admin direct Pengeluaran access remains forbidden.
- Capture console and network evidence for normal flow.

### Checkpoint 2

- Browser desktop/mobile and direct-denial checks pass.
- Code review covers correctness, scope, money safety, and architecture.

### Task 3: Final verification and focused commit

- Run optimize/view cache, focused tests, full suite, Pint, diff check, and frontend build.
- Commit only the Pengeluaran MVP files; preserve unrelated organization worktree changes.

## Explicit Non-Goals

- Do not modify Buku Saku Sales unless a regression is discovered.
- Do not start Dana Talangan.
- Do not resume organization graph work.
- Do not add a new evidence storage system or reporting framework.
