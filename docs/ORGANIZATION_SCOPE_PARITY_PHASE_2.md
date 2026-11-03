# Phase 2 — Organization & Scope Parity Evidence

**Status:** Diagnostic baseline in progress; production reads remain legacy.  
**Phase boundary:** Do not perform production cutover. Do not run a write backfill.

This document records the organization data sources, scope contract, parity evidence,
and migration-preparation findings for Phase 2. Phase 2.5 remediation evidence is
recorded separately in `docs/ORGANIZATION_PARITY_REMEDIATION_PHASE_2_5.md`. It is
intentionally separate from the organization graph UI and does not authorize or expose
diagnostics to CRM users.

## A. Organization Data Sources

| Source | Purpose | Readers | Writers | Fallback behavior | Status |
|---|---|---|---|---|---|
| `organization_assignments` | Current and historical `reports_to` graph edges, effective dates, primary state, source, and audit metadata | `OrganizationGraphService`, canonical branch of `SalesTeamScopeService`, graph workspace flows, parity diagnostics | `OrganizationGraphService`, `ReportingHierarchyService`, `OrganizationBackfillService` | No graph edge means no graph parent/descendant; legacy compatibility remains available in production | Canonical direction; not the production default |
| `users.supervisor_user_id` | Existing direct supervisor relationship and compatibility projection | `ReportingHierarchyService` legacy traversal, Sales legacy team traversal, user filters/forms, compatibility code | `OrganizationGraphService` synchronizes it when assignments change; user administration flows consume the field | Null means no legacy parent; dangling IDs are an integrity finding | Legacy read source and compatibility projection |
| `sales_coordinator_sales` | Sales Coordinator-to-Sales mapping, including current and historical periods | `SalesTeamScopeService`, sales scope queries, user administration and Sales workflows | Sales Coordinator assignment service and graph projection logic | Duplicate active mappings are ambiguous and excluded by current legacy query rules | Legacy/compatibility mapping |
| `branch_user` | Current and historical branch membership plus `can_view`, `can_edit`, `can_sync`, and member-management rights | `WorkspaceAccessService`, organization/user administration, scope services | `BranchAssignmentService`, `User` compatibility save hook, administration flows | `users.branch_id` may provide a limited primary-branch fallback when membership is absent | Workspace access source |
| `project_user` | Active project assignment, primary flag, and assignment date window | `WorkspaceAccessService`, `OrganizationScopeService`, `SalesTeamScopeService`, project/user administration | `ProjectAssignmentService`, user administration flows | No current date-valid assignment means no Sales project access; non-Sales users use branch workspace rules | Operational assignment source |
| `users.branch_id` | Primary branch identity and compatibility fallback | `WorkspaceAccessService`, `SalesTeamScopeService`, user administration, record scopes | User administration and organization unit moves; `User` save hook synchronizes compatibility membership/unit state | Active primary branch can be visible when no matching `branch_user` membership exists | Legacy compatibility fallback |
| `organization_units` and `users.organization_unit_id` | Central/branch organization tree placement and unit moves | `OrganizationGraphService`, organization workspace/controller, user administration | Organization unit migration and `OrganizationGraphService::moveToUnit` | Missing unit falls back to `pusat` or branch unit for graph validation where available | Canonical structural metadata |
| Historical branch membership | Preserve prior branch visibility for read/reporting cases | `WorkspaceAccessService::historicalBranchIds`, `OrganizationScopeService`, reports | Branch assignment lifecycle | Only `can_view=true` and `is_current=false` memberships qualify | Compatibility/historical access |
| Historical Sales Coordinator mapping | Preserve prior Sales relationships for historical reporting | `SalesCoordinatorSales::currentOrHistorical`, `SalesTeamScopeService`, canonical historical branch | Sales Coordinator assignment lifecycle | Historical mapping is retained only when it does not conflict with an active mapping | Compatibility/historical access |
| `OrganizationScopeService` | Resolves visible users, branches, projects, teams, and module scope tokens | Sales, Work Planner, Database, Consumer Progress, Dana Talangan, Expenses, authorization queries | None; it reads the sources above | `graph_mode=canonical` changes selected hierarchy calls; default remains legacy | Production scope façade; parity must not call it as both sides |
| `OrganizationGraphService` | Validates and mutates graph assignments; traverses current graph | Organization workspace, reporting hierarchy, canonical Sales scope, backfill | Organization workspace, reporting hierarchy, backfill | Cycle-safe traversal stops on visited nodes; invalid moves throw validation errors | Canonical writer/traversal |
| `ReportingHierarchyService` | Legacy-compatible supervisor assignment and descendant lookup | User administration, hierarchy reporting, scope service | Delegates writes to graph and preserves legacy projection | `legacy` mode reads bounded `supervisor_user_id`; `shadow` logs graph mismatch but returns legacy | Compatibility façade |
| `WorkspaceAccessService` | Branch/project workspace access and historical/primary fallbacks | Controllers, policies, scope services, graph validation | None; consumes memberships/assignments | Primary branch fallback is limited; explicit unauthorized requests return no selection | Workspace boundary owner |
| `CrmAuthorizationService` | Permission, organization scope, record visibility, and module authorization composition | Controllers/policies/module queries; `visibleTo()` scopes | None | Inactive users and missing permissions deny; scope still requires module-specific permission | Authorization composition |
| `SalesTeamScopeService` | Sales hierarchy and Coordinator mapping presentation/querying | Sales controllers, Sales scope service, dashboards/reports | None; reads legacy or graph depending on mode | Legacy is default; canonical mode uses graph descendants; historical mode preserves valid mappings | Module-specific compatibility façade |
| `visibleTo()` model scopes | Apply module record visibility to SalesLead, ContentItem, DanaTalangan, and related records | Sales, Work Planner, Dana Talangan, Dashboard, reminders, AI read tools | None | Each model scope delegates to module authorization/scope rules; parity evaluates representative consequences separately | Record-level consumers |

### Source precedence currently observed

1. Production defaults to `config('organization.graph_mode') = legacy`.
2. `supervisor_user_id` is the legacy reporting read source unless canonical mode is explicitly enabled.
3. `sales_coordinator_sales` is the legacy Sales Coordinator mapping source unless canonical mode is explicitly enabled for the relevant query.
4. Branch workspace reads use current `branch_user` membership and limited `users.branch_id` fallback.
5. Sales project reads require active, date-valid `project_user` assignments in an accessible branch.
6. Historical branch/mapping relationships are read-only compatibility behavior and must not be silently treated as current access.

## B. Legacy Scope Contract

- **`own`:** the viewer's user ID; for read-only scope resolution, historical branch IDs may extend branch/project visibility.
- **`team`:** bounded descendants from `users.supervisor_user_id`; Sales Coordinator mappings are separately resolved through `sales_coordinator_sales`.
- **`assigned`:** users currently assigned to the viewer's date-valid active projects through `project_user`.
- **`project`:** active project IDs assigned to the viewer, constrained to accessible workspace branches.
- **`branch`:** active users/projects in current viewable branch memberships plus the limited primary-branch fallback.
- **`descendants`:** legacy supervisor traversal with a depth limit and visited-ID protection.
- **`organization-wide`:** the existing `all` permission behavior, bounded for branch-scoped operators and broader for users with global compatibility access.
- **`system-wide`:** not an organization scope; it is controlled by system authorization and is excluded from graph parity.
- **`historical`:** non-current branch memberships and valid historical Sales Coordinator mappings used for read/reporting behavior.

`OrganizationScopeService` owns the production combination of permission scopes,
workspace boundaries, project windows, and module rules. This phase does not replace
that contract.

## C. Canonical Graph Scope Contract

- Current graph descendants and ancestors come from active, primary, date-valid
  `organization_assignments` rows with `relationship_type=reports_to`.
- `OrganizationGraphService` prevents self-parenting and cycles on writes and uses
  visited sets during traversal so malformed data cannot recurse indefinitely.
- Graph team membership is the descendant set, then role/account/workspace filters
  determine whether a person is a usable module member.
- Canonical Sales Coordinator membership is a direct graph parent relationship to a
  `sales_coordinator`; historical compatibility mappings remain relevant for historical
  reporting and are not inferred away.
- Branch and project scope remains a workspace/assignment concern; graph membership
  alone must never grant cross-branch or cross-project access.
- Organization-wide and system-wide are not inferred from graph connectivity.

## D. Legacy vs Graph Parity Results

The diagnostic service and `organization:parity` command produce one machine-readable
comparison for each viewer, module/scope request, and dimension. Each comparison
contains `legacy_ids`, `graph_ids`, `only_legacy`, `only_graph`, `equivalent`,
`classification`, `reason`, `severity`, `access_direction`, `confidence`,
`suspected_source`, `recommended_resolution`, `affected_modules`, `evidence`,
`remediation_status`, and raw `details`.

Required dimensions:

- visible user IDs;
- team member IDs;
- branch IDs;
- project IDs;
- reporting descendants;
- Sales Coordinator-to-Sales membership;
- Supervisor-to-Coordinator membership;
- representative module record decisions as `allow` or `deny`.

Results are sorted for deterministic comparison, but raw duplicate/stale/conflicting
source rows remain in `details`.

### Development diagnostic sample

An unfiltered production-read diagnostic was not used for cutover. A scoped sample
(`--role=supervisor --scope=descendants --only-differences --json`) scanned one viewer
with `graph_mode=legacy` and found six legacy-only descendant IDs, classified as
`MISSING_GRAPH_ASSIGNMENT`; `production_cutover_performed` was `false`. A second
sample for `--module=work_planner` found three hierarchy differences and no unknown
mismatch. These are evidence of missing graph representation, not permission or read
cutover decisions.

## E. Mismatch Classification Summary

Allowed classifications are:

`EXPECTED_COMPATIBILITY_DIFFERENCE`, `MISSING_GRAPH_ASSIGNMENT`,
`STALE_LEGACY_RELATION`, `PRIMARY_BRANCH_FALLBACK`, `HISTORICAL_ACCESS`,
`ROLE_SPECIFIC_RULE`, `SALES_COORDINATOR_MAPPING`,
`PROJECT_ASSIGNMENT_DIFFERENCE`, `GRAPH_TRAVERSAL_DIFFERENCE`,
`DATA_INTEGRITY_PROBLEM`, `IMPLEMENTATION_BUG`, and `UNKNOWN`.

An unexplained mismatch remains `UNKNOWN`. No comparator may mark it equivalent by
discarding one side, flattening duplicate mappings, or silently applying a fallback.

## F. Role × Scope Matrix

The parity fixture matrix covers every seeded primary role:

| Role | Organization relationships to exercise | Expected diagnostic focus |
|---|---|---|
| `sales` | own project, Coordinator mapping, one/multiple branches | assigned/project and Sales mapping |
| `sales_coordinator` | mapped Sales, supervisor relationship, historical mapping | team and Coordinator membership |
| `supervisor` | direct Coordinator descendants and branch/project workspace | descendants/team |
| `manager` | deep descendants across allowed workspace | graph traversal and branch boundaries |
| `branch_manager` | multiple current branches and project assignments | branch/project intersection |
| `sas` | branch-scoped operational relationships | role-specific and workspace rules |
| `sam` | branch-scoped operational relationships | role-specific and workspace rules |
| `pusat` | global compatibility behavior without system authorization | organization-wide compatibility difference |
| `admin` | branch-scoped operator fallback | branch membership/fallback |
| `staff` | own/no assignment/disconnected cases | denied or empty scopes |
| `superadmin` | organization-wide/system separation | system-wide exclusion and broad scope |

Each role is tested with single branch, multiple branch, primary fallback, project
assignment, supervisor relationship, Coordinator relationship, no graph assignment,
and historical association where the schema supports it. Supplemental roles are
tested to ensure they do not alter permission resolution or graph scope unexpectedly.

## G. Module Access Impact Analysis

The shadow evaluator compares representative `allow`/`deny` decisions for:

- Sales / `sales_pocketbook`;
- Consumer/KPR / `consumer_progress`;
- Work Planner / `work_planner`;
- Database / `database`;
- Dana Talangan / `bridge_fund`;
- Pengeluaran / `expenses`;
- reporting and dashboard reads;
- user and organization administration.

Graph-only membership is never sufficient for a module decision. A difference that
would expose a record in another branch or project is a critical leakage finding.
Production controllers and model scopes continue to use their current authorization
path.

## H. Backfill Analysis

`OrganizationBackfillService` selects a legacy parent as follows:

1. current valid `sales_coordinator_sales` mapping for a Sales user;
2. otherwise `users.supervisor_user_id`;
3. a Coordinator/supervisor disagreement is a conflict and is not inferred;
4. multiple active Coordinator mappings are ambiguous and are not inferred;
5. missing parents, cycles, inactive parents, invalid role relationships, and
   cross-scope relationships are reported rather than written.

Only `organization:backfill --dry-run` is permitted in Phase 2. Repeated isolated
dry-runs must produce stable proposal/conflict counts and no database writes. A
non-dry-run backfill remains outside this phase.

The development database dry-run was executed twice with identical results:
`users_scanned=19`, `assignments_proposed=1`, `created=0`, `already_migrated=1`, and
`conflicts=4`. No graph rows were written.

## I. Graph Integrity Findings

The diagnostic/test matrix checks self-parent edges, direct/indirect cycles, duplicate
active primary assignments, dangling user IDs, missing parents, inactive targets,
role-incompatible relationships, cross-workspace parents, deep hierarchies, and
disconnected users. No database constraint is proposed until existing violations are
measured.

## J. Cycle Safety Results

`OrganizationGraphService` traversal uses visited IDs and a frontier, while legacy
descendant traversal uses a depth limit and visited IDs. Phase 2 tests must prove
termination for self-reference, direct cycle, indirect cycle, duplicate edge, deep
hierarchy, missing parent, and disconnected node fixtures.

## K. Files Changed

Phase 2 added or changed:

- `app/Support/OrganizationParityFilter.php`;
- `app/Services/OrganizationParityService.php`;
- `app/Services/OrganizationBackfillService.php` (non-destructive anomaly classification only);
- `app/Console/Commands/OrganizationParity.php`;
- `database/migrations/2026_10_30_000002_add_organization_scope_parity_changelog.php`;
- `tests/Feature/OrganizationParityDocumentationTest.php`;
- `tests/Feature/OrganizationParityServiceTest.php`;
- `tests/Feature/OrganizationParityCommandTest.php`;
- `tests/Feature/OrganizationBackfillTest.php`;
- `docs/ORGANIZATION_SCOPE_PARITY_PHASE_2.md` and its Phase 2 design/plan documents.

Phase 1 files and pre-existing untracked artifacts were preserved and were not
restructured by Phase 2.

## L. Tests Added / Modified

Focused parity coverage is 23 tests and 169 assertions across documentation, service,
command, and backfill suites. It covers all seeded roles, current/historical branch
relationships, project assignments, supervisor and Coordinator mappings, supplemental
roles, malformed graph edges, module allow/deny decisions, command filters, and
dry-run safety.

## M. Full Test Results

Final verification completed:

- PHPUnit: **1,513 passed, 11,336 assertions, 0 failures**;
- `php artisan optimize:clear`: passed;
- `php artisan view:cache`: passed;
- `git diff --check`: passed;
- `vendor/bin/pint --dirty --test`: passed;
- organization route audit: passed, with existing four organization routes unchanged;
- `npm run build`: passed.

## N. Remaining Organization Debt

The development sample has known legacy-only hierarchy relationships that are not yet
represented in `organization_assignments`, plus four existing Coordinator/supervisor
conflicts reported by the backfill dry-run. The scoped parity samples reported zero
`UNKNOWN` classifications, but module shadow output reported critical access
differences (`critical_access_mismatches_zero=false`) because graph team assignments
are incomplete. Unknown mismatches remain blockers rather than being normalized away.

## O. Cutover Readiness Assessment

**Not ready for cutover.** Known missing graph assignments and critical module access
differences remain. Phase 2 records evidence only, and
`production_cutover_performed` remains `false`.

## P. Proposed Controlled Cutover Plan

Any future Phase 3 process must first resolve the known mismatches, then use shadow
comparison → review → guarded opt-in → staged read comparison → rollback checkpoint.

## Q. Rollback Plan

Restore legacy reads/configuration, retain compatibility projections, and do not delete
graph or legacy data. A rollback must be possible by leaving `graph_mode=legacy` and
disabling any future guarded graph-read flag without reversing historical data writes.

Future cutover readiness requires, at minimum:

1. every supported relationship representable canonically;
2. all mismatches explained;
3. zero unknown mismatches;
4. zero critical cross-branch and cross-project leakage differences;
5. reporting hierarchy parity;
6. Sales Coordinator parity;
7. idempotent backfill;
8. survivable integrity rules;
9. a tested legacy-read rollback.

Phase 2 stops after evidence collection. It does not perform Phase 3, does not switch
production reads, and does not execute an irreversible migration.
