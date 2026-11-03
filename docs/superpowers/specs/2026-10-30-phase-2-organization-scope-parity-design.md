# Phase 2 — Organization & Scope Parity Design

**Status:** Proposed — awaiting approval before implementation  
**Date:** 2026-10-30  
**Scope:** Non-destructive parity, observability, and migration preparation only

## Objective

Measure whether the existing production organization behavior can be represented by
the organization graph without changing production reads. For each supported viewer,
scope request, and representative module, compare an explicit legacy evaluation with
an explicit graph evaluation and retain differences with a classification and reason.

Phase 2 must not switch `config('organization.graph_mode')`, remove compatibility
projections, remove legacy columns, execute a write backfill, alter routes, alter
permissions, or change navigation.

## Chosen Approach

Implement a reusable `OrganizationParityService` and a safe `organization:parity`
Artisan command. The service will use separate legacy and graph adapters rather than
calling the production scope method twice. This prevents a future canonical-mode
implementation from hiding the legacy baseline.

The command will support:

- `--user=` for one viewer;
- `--branch=` and `--role=` filters;
- `--scope=` for one scope vocabulary item;
- `--only-differences`;
- `--json` for machine-readable output.

No parity result will be exposed through normal CRM routes or stored as user-facing
application data. JSON output is the diagnostic record; optional structured warnings
may be emitted only by the diagnostic command/service.

## Explicit Scope Vocabulary

The comparator will use these exact scope labels:

| Scope | Legacy basis | Graph basis | Initial consumers |
|---|---|---|---|
| `own` | viewer identity and legacy historical branch rules | viewer identity plus graph-derived record boundary | all scoped modules |
| `team` | `supervisor_user_id` traversal; Sales Coordinator mappings where applicable | current `organization_assignments` traversal | Work Planner, Sales, organization |
| `assigned` | active, date-valid `project_user` rows | active, date-valid project assignments associated with graph users | Database, Consumer Progress, Dana Talangan, Expenses |
| `project` | viewer's active project assignments and workspace branches | graph users constrained to the same active project assignments | project-scoped modules |
| `branch` | current `branch_user` view memberships plus primary-branch fallback | graph users constrained by the same workspace boundary; assignment differences are reported separately | branch-scoped modules |
| `descendants` | bounded legacy supervisor traversal | cycle-safe current graph traversal | reporting and team comparisons |
| `organization-wide` | existing `all` permission behavior, including Pusat compatibility behavior | graph result is bounded by the same explicit workspace/permission request, never inferred from graph membership | monitoring/reporting comparisons |
| `system-wide` | not an organization scope; only system authorization | not an organization scope; only system authorization | excluded from graph parity |
| `historical` | `is_current=false` branch memberships and historical Sales Coordinator mappings | historical graph assignments plus preserved compatibility projections where defined | read-only/reporting comparisons |

`all` remains the application's permission scope token. The diagnostic vocabulary
uses `organization-wide` to make clear that it is not automatically equivalent to
system-wide access.

## Comparison Result Contract

Each result will have a stable machine-readable shape:

```json
{
  "viewer_id": 123,
  "viewer_role": "supervisor",
  "module": "work_planner",
  "scope": "team",
  "legacy_ids": [10, 11],
  "graph_ids": [10, 12],
  "only_legacy": [11],
  "only_graph": [12],
  "equivalent": false,
  "classification": "MISSING_GRAPH_ASSIGNMENT",
  "reason": "Legacy supervisor relationship has no current reports_to assignment."
}
```

The service will compare, where applicable:

- visible user IDs;
- team and descendant IDs;
- branch IDs;
- project IDs;
- Sales Coordinator-to-Sales membership and supervisor-to-coordinator membership;
- representative module record decisions as `allow`/`deny`.

IDs will be sorted and deduplicated only for comparison presentation. Raw source
facts, including duplicate or stale rows, remain available in the diagnostic details
and are never silently normalized away.

## Mismatch Taxonomy

The first implementation will classify mismatches as:

`EXPECTED_COMPATIBILITY_DIFFERENCE`, `MISSING_GRAPH_ASSIGNMENT`,
`STALE_LEGACY_RELATION`, `PRIMARY_BRANCH_FALLBACK`, `HISTORICAL_ACCESS`,
`ROLE_SPECIFIC_RULE`, `SALES_COORDINATOR_MAPPING`,
`PROJECT_ASSIGNMENT_DIFFERENCE`, `GRAPH_TRAVERSAL_DIFFERENCE`,
`DATA_INTEGRITY_PROBLEM`, `IMPLEMENTATION_BUG`, or `UNKNOWN`.

Classification is deterministic and conservative. If existing evidence cannot explain
a difference, the result remains `UNKNOWN`; it is not marked equivalent.

## Backfill Analysis

The existing `organization:backfill --dry-run` command and
`OrganizationBackfillService` remain the only backfill path. Phase 2 will:

1. inspect and document its parent-selection precedence;
2. run it only with `--dry-run` against the available development database;
3. test legacy supervisor, Sales Coordinator, missing-parent, conflict, cycle,
   inactive-parent, multi-branch, and existing-assignment cases;
4. run isolated fixture dry-runs twice to verify stable/idempotent proposals;
5. report relationships it cannot infer safely.

No non-dry-run backfill will be executed.

## Integrity and Cycle Safety

Phase 2 will add diagnostic/tests, not database constraints. It will verify:

- no self-parent assignment;
- direct and indirect cycle rejection;
- duplicate active primary assignment detection;
- missing parent and dangling user diagnostics;
- role-level and shared-workspace validation;
- deep, disconnected, and duplicate-edge traversal termination.

Existing production data will be measured before any future constraint proposal.

## Cutover Readiness Output

The Phase 2 report will define readiness gates for a later phase, but will not satisfy
or execute them. A future cutover requires zero unknown mismatches, zero critical
cross-branch/project leakage, confirmed reporting and Sales Coordinator parity,
idempotent backfill, survivable integrity rules, and a tested legacy-read rollback.

## Verification Plan

Add focused tests for all seeded primary roles, supplemental-role non-escalation,
branch/project membership, primary fallback, historical access, supervisor and Sales
Coordinator relationships, missing assignments, cycles, cross-branch/project denial,
representative module decisions, command filters, JSON shape, and dry-run idempotency.

Production authorization remains on its pre-Phase-2 legacy path throughout.
