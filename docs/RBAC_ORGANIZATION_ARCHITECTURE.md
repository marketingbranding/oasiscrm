# RBAC and Organization Architecture

## Scope

OASIS authorization is the intersection of account state, primary role permissions, data scope, workspace membership, and record policy. This refactor is evolutionary: existing domain workflows and legacy organization columns remain available during migration.

## Authorization Model

`users.role_id` is the primary role and the source of registered permissions. `role_user` is supplemental legacy compatibility and never grants permissions. Permission slugs are registered in `App\Support\PermissionCatalog`; unknown slugs fail closed. Superadmin wildcard access applies only to registered permissions.

The access decision is:

```text
active and verified account
  + registered permission
  + own/team/assigned/branch/all scope
  + WorkspaceAccessService branch/project intersection
  + policy or domain record authorization
```

Navigation is presentation only. Direct HTML, JSON, export, sync, bulk, and deep-link requests require backend authorization.

## Organization Graph

`organization_assignments` is the canonical historical graph for `reports_to` relationships. A row records the user, parent, effective dates, branch/project context, source, actor, reason, active state, and optimistic `lock_version`.

The graph service is `OrganizationGraphService`. It provides current parent, children, descendants, ancestors, history, cycle checks, role-rule checks, scope checks, transactional moves, row locking, audit events, and temporary legacy projections.

Every move closes the previous row and creates a new row. It does not change `users.role_id`. The service also projects the current reporting line to `users.supervisor_user_id` and, for Sales to Coordinator edges, to `sales_coordinator_sales` during the compatibility period.

## Roles and Reporting Rules

`roles.authority_level` is configurable metadata for IAM delegation. It is not a reporting graph. `role_reporting_rules` explicitly determines allowed parent-role to child-role relationships. Numeric authority no longer defines the permanent reporting structure.

Role and parent are separate concepts:

- Changing a reporting line does not change a user's role.
- Changing a role does not silently change a reporting line.
- Dragging a node changes only the `reports_to` assignment.

## Scope Boundaries

The organization graph answers who reports to whom. `branch_user` and `project_user`, resolved by `WorkspaceAccessService`, answer where a user may work. `OrganizationScopeService` intersects permission scope and workspace membership. A descendant is not automatically visible outside the actor's authorized branch/project scope.

## Migration and Compatibility

The transition is controlled by `ORGANIZATION_GRAPH_MODE`:

- `legacy`: existing readers use legacy hierarchy data; new writes project to both structures.
- `shadow`: reserved for parity comparison before reader cutover.
- `canonical`: reporting and Sales team readers use `OrganizationGraphService`.

`php artisan organization:backfill --dry-run` reports proposals, already migrated rows, conflicting legacy sources, multiple active Coordinator assignments, cycles, invalid role rules, inactive parents, missing users, and cross-scope anomalies. The write command is idempotent and never deletes legacy rows.

Ambiguous legacy data is reported, not guessed. `users.supervisor_user_id` and `sales_coordinator_sales` remain until parity is proven and a separate removal plan is approved.

## Organization Workspace

`organization.index` is permission-gated by `organization.view`. The workspace renders a scoped desktop tree with pan/zoom, search, node details, and drag/drop. Mobile uses a hierarchical list. The detail panel provides the keyboard/touch alternative `Pindahkan ke...`.

Moves use `organization.move_user`, repeat all backend validation, require the target and parent to be in actor scope, reject inactive users, invalid role pairs, self-links, cycles, and incompatible workspace context, and send the assignment id/version loaded by the client. A stale assignment returns HTTP 409 with:

> Struktur organisasi telah berubah. Muat ulang lalu coba lagi.

## Audit

Each graph change records `organization_assignment_changed` in `activity_log` with actor, target, old/new parent and assignment IDs, effective date, source, reason, and branch context. Legacy reporting writes also retain the compatibility `supervisor_assignment_changed` event.

## Remaining Cutover Prerequisites

Before setting canonical mode in a deployment, run the dry-run and shadow comparisons for every active branch, resolve all ambiguity reports, align policy and export consumers, add role/rule administration screens, and complete direct-route authorization regression tests. Do not drop legacy columns or tables as part of this phase.

## Deferred Frontend Dependency Advisories

The full `npm audit` currently reports five high-severity advisories in the Tailwind CSS 3 development/build chain: `tailwindcss@3.4.19` through `chokidar@3.6.0`/`braces@3.0.3`, `fast-glob@3.3.3`/`micromatch@4.0.8`, and their shared `braces` dependency. `npm audit --omit=dev` is clean, so these packages are not runtime production dependencies. The available remediation is a Tailwind 4 migration, which is intentionally deferred to a separate scoped project and is not silently treated as fixed in this branch.
