# Phase 1 Design: Authorization Foundation

## Intent

Establish one predictable, testable authorization contract for OASIS organization administration while preserving the current effective access behavior. This phase covers Branch administration, Project administration, Kavling administration, User administration, Organization structure administration, and their navigation/direct-route surfaces.

The design is additive. It does not migrate organization data, rename routes, broaden permissions, or restructure unrelated modules.

## Goals

- Document the effective authorization decision for every covered endpoint.
- Make system-only administration explicit without granting access merely because a permission exists.
- Give each covered action one authoritative authorization owner.
- Align route capability checks, controller authorization, policies/services, and navigation.
- Add regression coverage for relevant roles, direct URLs, JSON responses, mutations, bulk actions, exports, and scope boundaries.
- Preserve branch, project, hierarchy, assignment, and ownership restrictions.

## Non-goals

- No Phase 2 organization graph cutover or removal of legacy columns/projections.
- No route hierarchy or route-name restructuring.
- No permission remapping that grants new access.
- No broad role redesign or role renaming.
- No refactor of unrelated operational modules.
- No replacement of existing organization assignment services.

## Anti-goals

- Do not make route middleware the sole security boundary.
- Do not make navigation determine authorization.
- Do not replace a documented Superadmin-only rule with a broader permission check without explicit approval.
- Do not duplicate the same authorization rule across route, controller, policy, and service without a distinct responsibility at each layer.
- Do not weaken current scope behavior to simplify tests.

## Selected approach

### Additive authorization contract

The selected approach introduces or reuses a shared authorization owner for system administration and routes covered actions through the existing authorization services and policies. Existing effective rules remain the default:

| Area | Current effective rule to preserve initially |
|---|---|
| Branch administration | Primary Superadmin only, despite `branches.manage` route capability |
| Project administration | Primary Superadmin only, despite `projects.manage` route capability |
| Kavling administration | Primary Superadmin only, inherited from Project/Kavling controller checks |
| Role and permission administration | Superadmin only in effective seeded configuration; registered role permissions remain explicit and do not grant Pusat access automatically |
| User administration | Registered capability plus `UserPolicy`/`UserAdministrationService` visibility, rank, role-assignment, branch, project, and lifecycle rules |
| Organization structure | `organization.view`/`organization.move_user` plus graph, workspace, hierarchy, and request validation rules |

The implementation may replace repeated checks with a policy/service call, but it must return the same allow/deny result unless an intentional correction is recorded in the completion report and covered by tests.

## Authorization contract

For covered actions, authorization is evaluated in this order:

```text
User
→ account state: authenticated, active, verified, password changed
→ capability: registered route/action permission or explicit system capability
→ workspace access: branch/project membership and active workspace rights
→ organization scope: visible users, branches, projects, hierarchy, assignment windows
→ resource policy/service: target record and action authorization
→ action validation: lifecycle, rank, lock, impersonation, and domain invariants
→ allow or deny
```

Responsibilities:

- **Routes:** coarse request boundary, account middleware, capability middleware, maintenance, and impersonation restrictions.
- **System administration authorization owner:** explicit system-only decision for Branch, Project, Kavling, Role, Permission, and system configuration actions.
- **`UserPolicy` / `UserAdministrationService`:** User target visibility and user-management authority.
- **`OrganizationWorkspaceController` dependencies:** organization graph and workspace/scope decisions; request classes remain responsible for input validation and graph invariants.
- **`WorkspaceAccessService`:** branch/project membership and workspace rights.
- **`OrganizationScopeService`:** organization and module scope; no Phase 2 data-model cutover.
- **NavigationService:** presentation of the same executable access contract; never a security boundary.
- **Controllers:** orchestration only; no new ad-hoc role, branch, or project authorization logic.

The exact owner may be an existing policy/service or a small additive authorization method/facade, provided it is named, tested, and used consistently by the covered controllers and navigation.

## Covered endpoint inventory

### Branch administration

- `branches.index`
- `branches.edit`
- `branches.update`
- `branches.assign`
- `branches.assign-store`
- `branches.remove-admin`

### Project and Kavling administration

- `projects.*` resource routes
- `kavlings.index`
- `kavlings.bulk-import`
- `kavlings.bulk-store`
- `kavlings.destroy`
- `kavlings.bulk-destroy`

### User administration

- `admin-users.index`, `show`, `create`, `store`, `edit`, `update`
- invitations, status, lifecycle, reset-access, and bulk-reset-access actions
- user import preview, confirmation, history, result, template, and credential endpoints
- sales proposal endpoints only where they are part of the user-administration access contract
- impersonation is audited for direct-route authorization but is not broadened

### Organization structure

- `organization.index`
- `organization.move`
- `organization.move-unit`
- `organization.remove-structure`

### System administration distinction

Role/permission administration, maintenance, migrations, and system configuration are recorded as system-level capabilities. Pusat retains explicitly mapped operational capabilities and does not receive system-administrator access through wildcard or implied role behavior.

## Test design

Tests will be added incrementally before or alongside implementation. They will use the seeded role and permission catalog rather than role-name assumptions.

The matrix will cover, where an endpoint supports the action:

- view, create, update, delete/archive, manage;
- direct HTML URL access;
- JSON mutation and denial responses;
- bulk import/delete/reset actions;
- export/download actions where present;
- organization moves;
- cross-branch and cross-project targets;
- supplemental-role non-escalation;
- account-state denial where relevant.

Relevant roles are `sales`, `sales_coordinator`, `supervisor`, `manager`, `branch_manager`, `sas`, `sam`, `pusat`, `admin`, `staff`, and `superadmin`. The expected result for each role is derived from executable behavior, `PermissionCatalog`, policies, services, and existing focused tests.

Existing behavior that is already covered will be retained and expanded rather than replaced. New tests must distinguish:

- route denial caused by missing capability;
- controller/policy denial caused by system-only or target-scope rules;
- validation denial after authorization succeeds;
- successful action.

## Incremental implementation slices

1. Add the authorization contract and role/action regression tests without changing access.
2. Centralize Branch, Project, and Kavling system-only checks behind the selected owner; update their controllers and navigation to use it.
3. Align Role/Permission navigation and route/controller ownership without granting Pusat or other operational roles system administration.
4. Align User and Organization navigation with their existing policy/service access, preserving all scope and lifecycle checks.
5. Audit covered routes and run focused tests after each slice.
6. Run the required Phase 1 verification commands and inspect the final diff for scope creep.

## Safety and rollback

- Preserve all pre-existing worktree changes.
- No destructive commands, schema changes, organization-data changes, dependency changes, or route renames.
- Keep existing route names and URLs stable.
- If a contradiction requires a behavior change, isolate it, document it, add before/after tests, and report it explicitly.
- Rollback is limited to reverting additive code/tests/docs from this phase; no production data migration is introduced.

## Success criteria

- Every covered endpoint has a documented authorization owner.
- Covered routes, controllers/services/policies, and navigation agree on effective access.
- Superadmin system privileges remain explicit.
- Pusat remains operationally privileged but is not automatically a system administrator.
- Direct URL, JSON, bulk, export, cross-branch, cross-project, and organization-move behavior is regression-tested where applicable.
- No Phase 2 organization migration or Phase 4 route restructuring begins.
- Full tests pass; existing baseline build/Pint limitations are reported without unrelated cleanup.

## Review boundary

This document describes the approved Approach 1 implementation boundary. Application code changes should begin only after this design is reviewed and approved.
