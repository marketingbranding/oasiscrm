# Phase 2.6 — Organization Relationship Semantics Evidence

**Status:** NOT READY for graph cutover.  
**Production read mode:** `graph_mode=legacy`.  
**Safety boundary:** Do not execute graph cutover, do not begin Phase 3, and do not run a global/write backfill.

This report records the bounded Phase 2.6 decision and additive implementation. It
does not make canonical graph reads production-authoritative and does not remove
legacy relationship columns or compatibility writers.

## A. Supervisor Semantics

`supervisor_user_id` represents the formal reporting relationship. Its consumers are
`ReportingHierarchyService`, reporting descendants/ancestors, management hierarchy,
supervisor monitoring, organization administration, reporting/dashboard traversal, and
hierarchy validation. It is represented canonically as `reports_to`.

One active primary `reports_to` parent is allowed per user. It participates in formal
reporting traversal and cycle checks. It does not by itself grant Coordinator authority,
mutation permission, finance access, consumer administration, or system administration.

## B. Sales Coordinator Semantics

`sales_coordinator_sales` represents operational Sales-team membership. It is consumed
by `SalesTeamScopeService`, Coordinator lead and agenda monitoring, Sales Daily Plan
notifications/corrections, Coordinator Sales workflows, Sales-team reporting, and
related handoff metadata. It is represented canonically as `sales_team_member_of`.

One active current Coordinator is allowed per Sales user; one Coordinator may have many
Sales users. It participates in Sales-team traversal only. It does not replace formal
reporting and does not grant unrelated module permissions.

## C. Relationship Taxonomy

| Relationship type | Source | Target | Current source | Canonical type |
|---|---|---|---|---|
| Formal reporting | User | Supervisor/manager parent | `users.supervisor_user_id` | `reports_to` |
| Sales-team membership | Primary-role Sales | Primary-role Sales Coordinator | `sales_coordinator_sales` | `sales_team_member_of` |
| Branch membership | User | Branch | `branch_user` | Workspace assignment, not hierarchy |
| Project membership | User | Housing project | `project_user` | Workspace assignment, not hierarchy |
| Historical relationship | Either supported edge | Prior effective target | Ended dated rows | Historical typed edge, no current access |

No generic enterprise relationship framework was introduced.

## D. Relationship Cardinality Rules

- `reports_to`: one current primary parent per user; many children per parent; current
  edges must satisfy existing active-role, workspace, and cycle validation.
- `sales_team_member_of`: one current Coordinator per Sales user; many Sales users per
  Coordinator; source must be primary-role `sales`, target primary-role
  `sales_coordinator`, and primary branches must match.
- Both edge types may coexist for the same Sales user.
- Ended rows remain auditable history and are excluded from current access.
- Branch/project assignments remain separate workspace constraints.

## E. Four Conflict Decisions

The four Phase 2.5 cases are Decision **A: both relationships are valid simultaneously**:

| Sales user | Supervisor | Sales Coordinator | Decision |
|---:|---:|---:|---|
| 11 | 13 | 12 | Both active relationships are valid |
| 21 | 13 | 12 | Both active relationships are valid |
| 22 | 13 | 12 | Both active relationships are valid |
| 23 | 13 | 12 | Both active relationships are valid |

The repository evidence supports orthogonal consumers and does not support replacing
one relationship with the other. No precedence rule was introduced.

## F. Graph Model Changes

The existing `organization_assignments.relationship_type` column now has explicit
typed semantics for `reports_to` and `sales_team_member_of`. Reporting APIs keep their
existing `current()` behavior. New typed scopes and graph writers manage Sales-team
edges without routing them through reporting assignment or cycle traversal.

Compatibility writers dual-write canonical Sales-team edges while production reads
remain legacy. No legacy column or relationship table was removed.

## G. Reporting Traversal Behavior

`OrganizationGraphService`, `ReportingHierarchyService`, reporting descendants,
ancestors, formal supervisor monitoring, and reporting parity use only current
`reports_to` edges. A Sales-team edge cannot create a reporting descendant, ancestor,
parent, or cycle.

## H. Sales Team Traversal Behavior

`SalesTeamScopeService`, Coordinator lead/agenda monitoring, Coordinator policy paths,
Daily Plan targeting, and Sales-team parity use only current
`sales_team_member_of` edges in canonical mode. Legacy mode continues reading
`sales_coordinator_sales`. A reporting edge does not create Sales-team membership.

## I. Module Access Impact

Relationship edges remain subordinate to permission, workspace, organization scope, and
target policy checks. Tests cover lead visibility, Sales Agenda monitoring, Work Planner
scope, Consumer/KPR scope, reporting/dashboard/export/notification paths, approvals,
and direct-resource policy boundaries.

Sales-team membership does not grant Consumer administration, Finance, organization
administration, or system administration. Reporting membership does not grant
Coordinator mutation authority. Cross-branch and cross-project scope remains enforced.

## J. Backfill Mapping

Backfill is now deterministic by source:

1. `users.supervisor_user_id` → `reports_to`;
2. current `sales_coordinator_sales` → `sales_team_member_of`;
3. ended rows remain historical evidence;
4. multiple active same-type Coordinators, invalid roles, inactive/missing targets,
   cycles, cross-scope relationships, and canonical same-type disagreements remain
   conflicts and are not guessed.

The command remains dry-run capable and no production/global write backfill was run.

## K. Parity Results Before / After

**Before Phase 2.6:** Phase 2.5 reported 19 users, 133 comparisons, 4 differences,
4 access-loss mismatches, 0 unknowns, and a `BLOCKED` gate. The four cases were
incorrectly represented as a single unresolved parent conflict in backfill evidence.

**After Phase 2.6 implementation:** parity compares reporting and Sales-team dimensions
independently. The four cases are no longer treated as a single parent-choice conflict;
missing typed edges remain separate diagnostic mismatches until an approved repair or
controlled migration is performed. Exact final diagnostic counts are recorded in the
verification update below after the required commands run.

## L. Cutover Gate Result

**BLOCKED.** Production remains `graph_mode=legacy`; `production_cutover_performed=false`.
The implementation does not authorize cutover. The gate must still require zero unknowns,
zero access expansion, zero unresolved critical access loss, zero cross-branch/project
leakage, zero unresolved reporting/Sales-team mismatches, zero cycle failures, and zero
backfill idempotency failures.

## M. Files Changed

- `app/Models/OrganizationAssignment.php`;
- `app/Services/OrganizationGraphService.php`;
- `app/Services/SalesCoordinatorAssignmentService.php`;
- `app/Services/SalesTeamScopeService.php`;
- `app/Services/OrganizationBackfillService.php`;
- `app/Services/OrganizationParityService.php`;
- `app/Services/OrganizationGraphRemediationService.php`;
- Phase 2.6 organization relationship tests and existing organization regression tests;
- this Changelog migration and evidence report.

Existing Phase 1/2/2.5 and unrelated dirty worktree changes were preserved.

## N. Tests Added / Modified

Added typed-edge coexistence, independent traversal, writer dual-write, canonical vs
legacy source, validation, historical behavior, independent backfill, parity separation,
guarded typed remediation, and module access-separation coverage. Existing graph,
backfill, parity, Coordinator monitoring, scope, Work Planner, branch monitoring, and
historical access tests were updated to preserve their contracts under typed semantics.

## O. Verification Results

Task-level verification completed:

- typed graph suite: 28 tests, 141 assertions;
- compatibility/traversal suite: 51 tests, 428 assertions;
- backfill suite: 32 tests, 171 assertions;
- parity/remediation suite: 30 tests, 144 assertions;
- module access-separation suite: 93 tests, 594 assertions.

The full verification commands required by the Phase 2.6 completion checklist remain
to be recorded after the final suite, cache/view compilation, Pint, diff check, Vite
build, parity diagnostic, and two dry-run executions.

## P. Remaining Business Ambiguities

No remaining ambiguity exists about the four relationship dimensions: both Supervisor
and Coordinator relationships may coexist. Remaining operational work is evidence and
rollout safety: canonical edge population for existing data, exact final parity counts,
and whether the cutover gate can ever become ready without an approved migration window.

## Q. Cutover Recommendation

**Do not cut over.** Keep production reads on legacy mode, review the final parity and
module shadow outputs, and obtain an explicit migration approval before any canonical
read switch or global backfill.

## R. Proposed Next Phase

Phase 2.7 should be a controlled canonical-edge population and shadow-validation phase:
review dry-run proposals, approve only evidence-backed repairs, run a scoped reversible
population under audit, rerun all module shadows, and re-evaluate the gate. It must not
be treated as Phase 3 and must not switch production reads without a separate approval.
