# OASIS CRM RBAC Policy Foundation

This document describes the Phase 1 authorization foundation. It does not
describe Lead Targets, Daily Plans, the 420-minute planner, scheduled agenda
notifications, or mandatory phone changes as implemented features.

## Organization and roles

The canonical organization is:

```text
CABANG
└── BRANCH MANAGER
    ├── STAFF ADMIN SALES (SAS)
    ├── STAFF ADMIN MARKETING (SAM)
    └── SUPERVISOR
        ├── KOORDINATOR SALES
        │   └── SALES
```

Canonical roles are `superadmin`, `pusat`, `branch_manager`, `supervisor`,
`sales_coordinator`, `sales`, `sas`, and `sam`. Legacy compatibility roles
`manager`, `admin`, and `staff` remain available and are not renamed or
deleted.

SAS and SAM are distinct roles with identical Phase 1 permission mappings:
branch Buku Saku Sales monitoring and export only. Neither role receives role,
permission, Team membership, Lead Target, Daily Plan, or Superadmin actions.

## Account lifecycle

Account lifecycle remains separate from role, permission, and scope. Existing
statuses remain `pending_invitation`, `invited`, `active`, `suspended`,
`inactive`, and `anonymized`. No `pending_approval` status was added.

Suspended and inactive users remain denied by lifecycle middleware and policy
checks. Reassignment and lifecycle behavior are outside this foundation.

## Scope vocabulary

The authorization vocabulary is:

- `OWN`: records owned by the current user;
- `TEAM`: active Sales mapped to a Coordinator through `sales_coordinator_sales`;
- `ASSIGNED`: users or records within active project assignments;
- `PROJECT`: project-specific access, represented by the project scope in the
  permission catalog and assignment-aware project resolution;
- `BRANCH`: records in authorized active branches;
- `ALL`: all records covered by a registered permission. `ALL` is not inferred
  for Pusat.

Superadmin receives every **registered** permission. Unknown permission slugs
remain denied. Pusat receives only explicitly mapped permissions.

## Canonical ownership and Team authority

Sales Lead ownership is `sales_user_id`; `created_by` is audit metadata and is
never used to decide Sales Lead ownership.

Sales Agenda ownership is `owner_user_id`; `created_by` is audit metadata and
is not used to decide Agenda ownership.

Coordinator-to-Sales authority is the active, valid, date-effective
`sales_coordinator_sales` mapping. Both users must be active and have the
canonical `sales_coordinator` and `sales` primary roles. Inactive or expired
mappings are ignored. Reporting hierarchy through `users.supervisor_user_id`
does not create Coordinator TEAM authority.

## Authorization architecture

`CrmAuthorizationService` is a lightweight facade for policy-facing decisions.
It delegates workspace branch/project checks to `WorkspaceAccessService`,
organization scope resolution to `OrganizationScopeService`, and Coordinator
TEAM resolution to `SalesTeamScopeService`. It does not replace those domain
services.

The intended decision sequence is:

```text
ROLE → REGISTERED PERMISSION → SCOPE → POLICY → RECORD
```

`SalesLeadPolicy` applies permission, scope, branch/project access, and
operational ownership. Sales Agenda checks are delegated from
`ContentItemPolicy` to the dedicated `SalesAgendaPolicy`, so generic
`ContentItem` behavior is not changed for tasks, content, or other planner
items.

## Role behavior in Phase 1

- **Superadmin**: registered permissions with global scope.
- **Pusat**: explicitly mapped operational permissions only.
- **Branch Manager**: branch monitoring according to registered permissions.
- **Supervisor**: branch monitoring for Buku Saku Sales; Team membership is not
  administered here.
- **Sales Coordinator**: mapped TEAM Lead and Agenda monitoring; no Daily Plan
  implementation is included.
- **Sales**: own Lead and Agenda workflows using operational ownership fields.
- **SAS / SAM**: identical branch monitoring and report/export access.
- **Legacy Manager**: compatibility branch monitoring and exports without
  automatic role, permission, Team, or target management.
- **Legacy Admin / Staff**: existing behavior is preserved.

## Migration phases

Phase 1 establishes roles, permission mappings, the authorization facade, the
canonical Coordinator TEAM authority, and Sales Lead/Sales Agenda policies.

Future phases may add Lead Targets, Daily Plans, the 420-minute rule, Agenda
duration/start/end planning, scheduled reminders, and mandatory phone changes.
Those capabilities must reuse this authorization sequence and must not infer
authority from `created_by` or `users.supervisor_user_id`.
