# Phase 2.5 — Organization Parity Remediation Evidence

**Status:** NOT READY for graph cutover.  
**Production read mode:** `graph_mode=legacy`.  
**Safety boundary:** Do not execute the cutover, do not begin Phase 3, and do not run a global write backfill.

This report records the evidence-gated remediation work after the Phase 2 parity
baseline. It does not make the graph production-authoritative and does not silently
normalize legacy relationships.

## A. Initial Parity State

Phase 2 found legacy-only hierarchy relationships, critical module-access differences,
and four active Coordinator/supervisor conflicts. The development dry-run baseline was
`users_scanned=19`, `assignments_proposed=1`, `created=0`, `already_migrated=1`, and
`conflicts=4`, repeated twice without writes.

## B. Mismatches Investigated

Every comparison now carries severity, access direction, confidence, suspected source,
recommended resolution, affected modules, evidence, and remediation status. The
inventory distinguishes `SECURITY_CRITICAL`, `ACCESS_LOSS`, `STRUCTURAL`, `HISTORICAL`,
`EXPECTED`, and `UNKNOWN`, plus graph `EXPANSION` and legacy `LOSS`.

## C. Four Backfill Conflict Analysis

The four development conflicts are active, same-branch, same-workspace Sales cases:

| Sales user | Legacy supervisor | Coordinator mapping | Branch | State |
|---:|---:|---:|---|---|
| 11 | 13 | 12 | SOL | active dual parent; unresolved |
| 21 | 13 | 12 | SOL | active dual parent; unresolved |
| 22 | 13 | 12 | SOL | active dual parent; unresolved |
| 23 | 13 | 12 | SOL | active dual parent; unresolved |

The source data proves both relationships exist and are active. It does not prove that
Coordinator ownership should replace legacy supervisor ownership, so no precedence rule
or automatic write is permitted. User 20 already has a matching canonical assignment
to Coordinator 12 and is the idempotency reference.

## D. Canonical Assignments Added/Corrected

No production or global canonical assignment was added in Phase 2.5. The one
`MISSING_GRAPH_ASSIGNMENT` proposal is represented with exact user/parent, role,
branch/project, active-state, confidence, and module evidence. Any later repair must be
additive, audited, idempotent, and revalidated through `OrganizationGraphService`.

## E. Graph Logic Fixes

The parity layer now detects graph allow/legacy deny as `SECURITY_CRITICAL` expansion
and legacy allow/graph deny as `ACCESS_LOSS`. It does not change production read
ownership. No graph traversal rule is allowed to override branch, project, role, cycle,
or account-state validation.

## F. Legacy Anomalies Identified

The diagnostic vocabulary includes `LEGACY_STALE`, `LEGACY_ORPHANED`,
`LEGACY_CROSS_TREE`, `LEGACY_DUPLICATE`, `LEGACY_INVALID_PARENT`, and
`LEGACY_HISTORICAL_ONLY`. Existing legacy rows are retained and classified; none are
deleted or silently excluded from the evidence package.

## G. Sales Coordinator Parity

Current Coordinator mappings and graph parentage are compared independently. Sales,
team visibility, leads, agendas, reporting, and consumer handoff remain legacy-read
compatible. The four active dual-parent mappings are unresolved and block cutover;
historical mappings are not promoted into current team membership.

The unresolved classification is `SALES_COORDINATOR_MAPPING` with unknown business
precedence, not an automatic graph repair.

## H. Branch/Project Parity

The parity matrix covers one branch/one project, one branch/multiple projects, multiple
branch membership, primary branch fallback, historical membership, branch-only users,
project-only users, and unusual project/branch relationships. Graph connectivity alone
cannot grant another branch or project. Cross-branch and cross-project expansion are
cutover blockers.

## I. Module Access Parity

Shadow decisions cover Sales, Consumer/KPR, Work Planner, Database, Dana Talangan,
Pengeluaran, Reporting, and Organization Administration. Each record is compared as
legacy `allow`/`deny` versus graph `allow`/`deny`. Graph-only access is the highest-risk
class; access loss and unknown differences also block the gate.

## J. Backfill Results

The backfill remains diagnostic and supports `organization:backfill --dry-run`. Proposal
evidence is separate from conflict/anomaly details. The first and second dry runs must
remain equivalent, and isolated write tests must prove that repeating an approved repair
does not create a duplicate active assignment.

## K. Integrity Contract

Future canonical writes require no active self-parent, no active cycle, no duplicate
equivalent active primary assignment, valid source and target users, valid branch and
project context, compatible role hierarchy, active shared workspace, and deterministic
active reporting relationships where the business rule requires one. Existing data is
diagnosed before any restrictive database constraint is considered.

## L. Cutover Gate Results

The diagnostic gate exposes `READY` or `BLOCKED` and explicit counts for unknown
mismatches, critical access mismatches, expansion, access loss, unresolved reporting
conflicts, unresolved Sales Coordinator conflicts, unclassified anomalies,
cross-branch/project expansion, cycle safety, and backfill idempotency. Current status:
**BLOCKED** because missing graph representation, critical module differences, and the
four unresolved dual-parent conflicts remain.

## M. Files Changed

Phase 2.5 remediation touches the parity filter/service, backfill evidence service,
the guarded graph remediation service, parity/backfill/documentation/command tests, the
idempotent Changelog migration, this report, and the execution ledger. Existing Phase
1/2 files and unrelated worktree artifacts remain preserved.

## N. Tests Added / Modified

Coverage includes access expansion, access loss, assessment fields, diagnostic gate,
missing graph assignment, proposal evidence, active dual-parent conflict evidence,
guarded repair validation and idempotency, historical relationships, branch/project
boundaries, cycles, duplicate edges, supplemental roles, command JSON, and dry-run
idempotency. The complete suite is 1,529 tests and 11,498 assertions.

## O. Full Verification Results

Phase 2.5 final verification passed:

- PHPUnit: **1,529 passed, 11,498 assertions, 0 failures**;
- `php artisan optimize:clear`: passed;
- `php artisan view:cache`: passed;
- `php artisan migrate --force`: passed, including the Phase 2.5 migration;
- Phase 2.5 Changelog row: exactly one row with title `Remediasi Paritas Organisasi dengan Gate Cutover`;
- `git diff --check`: passed;
- `vendor/bin/pint --dirty --test`: passed;
- organization route audit: passed, with existing four organization routes unchanged;
- `npm run build`: passed.

The two required `organization:backfill --dry-run` executions were equivalent:
`users_scanned=19`, `assignments_proposed=1`, `created=0`, `already_migrated=1`, and
`conflicts=4`; no graph rows were written.

## P. Remaining Unknowns

The business meaning of the four active Coordinator/supervisor dual-parent mappings is
unknown. Whether Coordinator mapping is team ownership, supplemental assignment, or a
transition artifact requires an explicit business decision. These unknowns are not
silently resolved.

## Q. CUTOVER RECOMMENDATION

**NOT READY.** Unknown or unresolved ownership intent, access differences, and missing
canonical representation prevent graph cutover.

## R. Controlled Cutover Plan

Only after this gate is clear: resolve every mismatch with evidence, rerun shadow
comparisons, review the full role/module matrix, enable a guarded opt-in for a limited
cohort, compare production decisions, retain a legacy rollback checkpoint, and obtain
separate approval. This report does not authorize those steps.

## S. Rollback Plan

Keep `graph_mode=legacy`, preserve `supervisor_user_id`, `sales_coordinator_sales`,
compatibility writers, and historical graph rows. If a later isolated repair is
approved and causes a problem, disable graph reads and reverse only the audited
canonical assignment through the existing graph service. Do not delete legacy data.
