# Phase 2.7 — Persiapan Cutover Terkendali Graph Organisasi

**Status:** NOT READY untuk cutover produksi
**Tanggal evidence:** 2026-10-30
**Batas:** persiapan dan pembuktian terisolasi saja; tidak ada perubahan production read source.

## A. Remaining Four Loss Analysis

Diagnostic `organization:parity --only-loss --json` memindai 19 viewer dengan 133 perbandingan. Terdapat empat mismatch access loss, seluruhnya berada pada cabang SOL dan tidak ada access expansion:

| Viewer | Dimensi | Legacy-only | Graph-only | Klasifikasi |
|---:|---|---|---|---|
| 12 | `sales_team_membership` | 11, 20, 21, 22, 23 | — | `SALES_COORDINATOR_MAPPING` |
| 13 | `team_members` | 11, 12, 20, 21, 22, 23 | — | `MISSING_GRAPH_ASSIGNMENT` |
| 13 | `reporting_descendants` | 11, 12, 20, 21, 22, 23 | — | `MISSING_GRAPH_ASSIGNMENT` |
| 13 | `supervisor_coordinator_membership` | 12 | — | `MISSING_GRAPH_ASSIGNMENT` |

Tidak ada loss yang diklasifikasikan sebagai kesalahan traversal graph. Relasi reporting dan Sales-team tetap diperlakukan sebagai dua edge yang sah dan independen.

## B. Ten Backfill Proposal Inventory

Dry-run menghasilkan 10 proposal deterministik, semuanya `MISSING_GRAPH_ASSIGNMENT`, `HIGH` confidence, dan `PROPOSED`:

| User | Parent | Relationship | Branch |
|---:|---:|---|---|
| 11 | 13 | `reports_to` | SOL |
| 11 | 12 | `sales_team_member_of` | SOL |
| 12 | 13 | `reports_to` | SOL |
| 20 | 12 | `sales_team_member_of` | SOL |
| 21 | 13 | `reports_to` | SOL |
| 21 | 12 | `sales_team_member_of` | SOL |
| 22 | 13 | `reports_to` | SOL |
| 22 | 12 | `sales_team_member_of` | SOL |
| 23 | 13 | `reports_to` | SOL |
| 23 | 12 | `sales_team_member_of` | SOL |

The proposal source remains the legacy projection (`supervisor_user_id` and `sales_coordinator_sales`). No row-number, name, phone, or unrelated identity matching is introduced.

## C. Controlled Backfill Results

The guarded command and service were proven in isolated PHPUnit SQLite transactions. The direct evidence run against the current workspace was dry-run only:

- `organization:backfill --dry-run`: 19 users scanned, 10 proposals, 0 created, 1 already migrated, 0 conflicts.
- Controlled writes require `--write`, `--confirm-isolated`, explicit `--user` and/or `--branch`, local/testing environment, independent isolation and non-production markers, and a verified non-production database identity.
- Missing selection, missing confirmation, unsafe environment, or production database identity fails before mutation.
- The controlled test fixture proves first-write creation, second-write zero duplicates, third dry-run zero equivalent proposals, and transactional write scope.
- Controlled writes perform a complete preflight and reject the whole selected batch when any selected proposal is invalid; source rows are locked and revalidated during the write transaction.
- **No global backfill** and no production/global write were executed for this report.

## D. Post-Backfill Parity

No post-backfill production-like write was executed because the current evidence remains blocked and the workspace database was not treated as an explicitly isolated write target. Current parity remains:

| Metric | Value |
|---|---:|
| Comparisons | 133 |
| Mismatches | 4 |
| Unknown | 0 |
| Access expansion | 0 |
| Access loss | 4 |
| Critical | 0 |
| Conflicts | 1 |
| Cross-branch expansion | 0 |
| Cross-project expansion | 0 |

The health gate therefore remains `BLOCKED`; no claim of zero post-backfill mismatch is made.

## E. Graph Mode Architecture

`OrganizationGraphModeService` is the centralized resolver. Internal modes are `legacy`, `shadow`, and `graph`. The existing `canonical` value is a backward-compatible alias for internal `graph` semantics, while raw mode metadata remains observable for diagnostics.

Invalid or missing modes, malformed allowlists, unsafe environments, and missing activation context fail closed to `legacy`. The resolver does not inspect roles or grant permissions.

## F. Legacy Mode Behavior

`legacy` remains authoritative for reporting hierarchy, Sales-team scope, and organization hierarchy IDs. Legacy compatibility columns and mappings remain preserved. The production/default configuration remains `graph_mode=legacy`.

## G. Shadow Mode Behavior

`shadow` evaluates canonical equivalents at existing read seams, records aggregate mismatch counters, and returns the legacy result. Shadow diagnostics do not log complete names, emails, leads, consumers, financial records, or raw payloads.

## H. Graph Mode Behavior

`graph` returns canonical reporting, Sales-team, and hierarchy results only for an explicitly activated safe context. It never unions legacy and canonical IDs. Active canonical edges are revalidated for current users, roles, workspace, branch, and cycle safety; invalid edges fall back out of graph reads. Branch-context reads filter canonical results to that evaluated branch. An unsafe or unmatched context falls back to legacy for that evaluation.

## I. Scoped Activation Design

Outside tests, graph activation requires a valid explicit user or branch allowlist. Matching is OR. A branch allowlist applies only to the branch context currently being evaluated; it does not widen another branch. Empty allowlists fail closed. Testing fixtures may activate graph mode explicitly without production activation.

## J. Compatibility Writer Verification

Existing graph writers continue to maintain the canonical assignment representation while existing legacy columns/mappings remain available to compatibility readers and writers. The verification suite covers supervisor changes, Sales Coordinator changes, moves, branch/project assignment behavior, historical rows, and permission preservation. No permission redesign was introduced.

## K. Transaction Safety

Controlled backfill validates all selected proposals before mutation and executes selected writes inside a transaction. A guarded failure does not create partial typed assignments. Existing graph assignment and organization-assignment tests cover stale, ambiguous, invalid-role, cycle, and transaction-oriented failure behavior.

## L. Security Regression Results

The graph mode workflow suite proves that graph relationships do not grant capability. Permission checks remain independent. Cross-branch/project expansion remains a parity blocker, direct organization read seams preserve relationship separation, and the existing module authorization suite passed without graph-only privilege expansion.

## M. Rollback Test Results

The isolated workflow test proves `legacy → graph → legacy`: graph mode can intentionally return a canonical empty result when the legacy edge has not been backfilled, and restoring legacy returns the original legacy scope. Rollback changes read configuration only; canonical assignments are not deleted.

## N. Cutover Health Gate

The read-only health command returns `BLOCKED` for the current workspace evidence:

- `parity_access_loss`: 4;
- `parity_reporting_conflict`: 1;
- `parity_sales_team_conflict`: 1;
- `backfill_proposals`: 10;
- `controlled_evidence_incomplete`: 1.

Health output is machine-readable with stable `READY`/`BLOCKED` status, reason codes, parity, backfill, integrity, writer-evidence, mode, and shadow sections. Missing selected users, stale/ambiguous edges, unavailable writer evidence, and invalid configuration block readiness. `production_read_source=legacy` and `production_cutover_performed=false` remain explicit.

## O. Files Changed

- `config/organization.php` and `OrganizationGraphModeService` for fail-safe mode and allowlists;
- `OrganizationBackfillService` and `organization:backfill` for guarded isolated writes;
- `OrganizationCutoverHealthService` and `organization:cutover-health` for the gate;
- reporting, Sales-team, and organization-scope read seams for mode resolution;
- focused feature tests for mode, backfill, health, reads, workflow, security, and rollback;
- this report and the idempotent Changelog migration.

## P. Tests Added/Modified

- `OrganizationGraphModeServiceTest`;
- `OrganizationBackfillTest` and `OrganizationBackfillCommandTest`;
- `OrganizationCutoverHealthServiceTest`;
- `OrganizationGraphModeReadsTest`;
- `OrganizationGraphModeWorkflowTest`;
- `OrganizationGraphCutoverDocumentationTest`;
- existing organization graph, parity, relationship, permission, and assignment suites.

## Q. Full Verification Results

Task-focused verification passed through Task 5, including 84 tests/580 assertions for the workflow/security slice and 70 tests/346 assertions for graph/read/parity seams. The complete suite, cache/view compilation, frontend build, and final formatting remain required before completion. No browser verification is applicable to this backend/configuration change.

## R. Production Cutover Recommendation

`NOT READY`. Keep production and the default read source on `graph_mode=legacy`. Do not perform cutover while the four access losses, ten deterministic proposals, and incomplete controlled evidence remain unresolved. A fresh health process without durable shadow evidence is intentionally blocked.

## S. Proposed Production Cutover Procedure

Only after an approved isolated evidence package reaches `READY`: freeze the exact allowlist, verify parity and integrity again, activate one explicitly selected safe context, monitor shadow/health counters, expand in small reversible cohorts, and retain legacy writers/read rollback. This procedure is proposed only; it was not executed.

## T. Immediate Rollback Procedure

Set `ORGANIZATION_GRAPH_MODE=legacy`, remove graph user/branch activation allowlists, clear configuration cache, restart application workers if applicable, rerun the health/parity diagnostics, and confirm `production_read_source=legacy`. Do not delete canonical assignments or legacy compatibility data during rollback. **Phase 3 did not begin.**
