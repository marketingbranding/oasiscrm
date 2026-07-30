# OASIS Full Maintenance Mode

This document records the audited architecture and implementation contract for OASIS full operational maintenance. `AGENTS.md` and executable code remain authoritative.

## Purpose

Full maintenance temporarily blocks the complete protected OASIS CRM for ordinary authenticated users while preserving access for explicitly authorized bypass users. It is an HTTP access-control state, not a deployment orchestrator, read-only mode, branch mode, or module-specific switch.

The feature must preserve sessions. A normal user who remains signed in receives the maintenance response on the next protected request and can continue normally when maintenance is disabled.

## Difference From Laravel Native Maintenance

OASIS full maintenance is database-backed and evaluated after authentication. It can therefore:

- evaluate registered OASIS permissions;
- let primary Superadmin and primary Pusat bypass through current permission resolution;
- preserve selected authentication lifecycle routes;
- return an OASIS HTML or JSON response;
- be managed and audited inside OASIS.

`php artisan down` remains the emergency fallback when Laravel cannot boot, migrations are incomplete, or the database/application middleware is unavailable. Native maintenance is not enabled, disabled, or bypassed by the OASIS feature.

## Audited Baseline

- Baseline commit: `339d8cd`.
- Main protected CRM middleware: `auth`, `active`, `verified`, `password.changed`, `sales.access`.
- Profile uses the narrower `auth`, `active` middleware set.
- Forced password change uses `auth`, `active`, `verified`.
- Guest login, password recovery, reset, and invitation activation are separate.
- Authenticated verification, password confirmation/update, and logout are separate lifecycle routes.
- Relevant IAM, lifecycle, navigation, System Health, production-hardening, and auth baseline: 67 tests, 377 assertions.
- There was no application-level maintenance state, permission, middleware, response, controller, or test before this implementation.

## Route Availability Audit

### A. Always available

Public and guest lifecycle:

- `login` GET/POST;
- `password.request` and `password.email`;
- `password.reset` and `password.store`;
- `invitations.show` and `invitations.store`.

Authenticated lifecycle:

- `verification.notice`, `verification.verify`, and `verification.send`;
- `password.confirm` GET/POST;
- `password.update`;
- `password.change` and `password.change.update`;
- `logout`.

These routes preserve their current lifecycle middleware and native response behavior. They are not general CRM bypass routes.

### B. Available to authenticated bypass users

Every route in the principal protected CRM group, including:

- Dashboard;
- Buku Saku Sales;
- Work Planner;
- Database and Konsumen Progress;
- Dana Talangan and Pengeluaran;
- comments and mentions;
- presence and notification polling;
- AI Chat;
- feedback;
- export, import, synchronization, status, and bulk endpoints;
- Changelog, System Health, Design System, organization administration, and IAM;
- maintenance administration.

Profile GET/PATCH is also bypass-only while maintenance is active because profile editing is not required recovery lifecycle.

### C. Blocked for ordinary authenticated users

The complete protected route sets above are blocked for GET, POST, PUT, PATCH, and DELETE before controller execution. Hidden navigation is not authorization.

### D. Technical decisions

- `/up` remains Laravel infrastructure health and does not report operational-maintenance state.
- Framework storage routes remain unchanged and are not treated as CRM controller execution.
- `/` retains its login/landing redirect; an authenticated ordinary user reaches maintenance on the landing route.
- scheduler, queue infrastructure, cleanup, scheduled synchronization, and console commands continue unchanged.

## Permission Model

Registered permissions:

- `system.maintenance_bypass`;
- `system.maintenance_manage`.

Default mapping:

| Primary role | Bypass | Manage |
|---|---:|---:|
| Superadmin | Registered-permission wildcard | Registered-permission wildcard |
| Pusat | Yes, explicit role pivot | No |
| Every other role | No | No |

Permission resolution remains primary-role-only. Supplemental Pusat or Superadmin roles do not grant bypass or management. No per-user permission override is introduced.

The additive permission migration creates both records and inserts only the Pusat bypass pivot. It does not delete custom role grants.

## Middleware Placement

Alias: `operational.maintenance`.

Principal CRM order:

```text
auth
active
verified
password.changed
operational.maintenance
sales.access
```

Profile order:

```text
auth
active
operational.maintenance
```

The middleware is deliberately omitted from forced-password-change and authentication lifecycle routes. It returns the maintenance response directly, so there is no maintenance-page redirect loop.

## Storage Plan

The authoritative table is `operational_maintenance_settings` with one fixed primary key: `global`.

Required state:

- `enabled`;
- `title`;
- `message`;
- nullable `estimated_end_at`;
- nullable `enabled_by` and `disabled_by` foreign keys;
- nullable `enabled_at` and `disabled_at`;
- `lock_version`;
- timestamps.

The database row is authoritative. No environment variable or application cache is used as maintenance state. The textual primary key prevents duplicate authoritative rows; the service never accepts another key.

## Transaction And Concurrency Contract

Enable and disable operations:

1. revalidate actor authorization;
2. start a database transaction;
3. lock the `global` row with `lockForUpdate()`;
4. verify the submitted `lock_version`;
5. revalidate lifecycle and bypass invariants;
6. update with a compare-and-swap version condition;
7. insert the curated ActivityLog in the same transaction;
8. commit and return the reloaded state.

The compare-and-swap is required because test SQLite does not provide MySQL-equivalent row locking. A stale request is rejected and cannot overwrite a newer state.

## Self-Lockout Prevention

Activation is rejected unless:

- the actor has `system.maintenance_manage`;
- the actor has `system.maintenance_bypass`;
- the actor is active;
- the actor is verified;
- the actor has completed the required password change;
- at least one lifecycle-eligible primary-role bypass user exists at commit time;
- the submitted singleton version is current.

Primary Pusat remains inside OASIS during maintenance but cannot manage it by default. A custom role with manage but no bypass may inspect the disabled state but cannot activate maintenance.

## Centralized Service Contract

`OperationalMaintenanceService` owns:

- authoritative state reads;
- active-state determination;
- safe public response data;
- eligible bypass-user query;
- actor/lifecycle/self-lockout validation;
- transactional enable/disable;
- optimistic version checks;
- curated activity logging;
- fail-safe read handling.

Controllers, middleware, and views do not query the setting directly.

## Fail-Safe Decision

The request-blocking read path fails open:

- a missing table;
- a missing `global` row;
- a database read exception;
- malformed unavailable state

are treated as operational maintenance disabled. A sanitized internal error is written through Laravel logging, with no database exception exposed publicly.

Management writes fail closed, roll back, and show a bounded user-facing error. Fail-open maintenance does not make an unavailable application database usable; it only avoids creating an additional accidental lockout.

## Administration Flow

Routes use `/admin/maintenance` and require `system.maintenance_manage` in addition to the normal CRM lifecycle middleware.

The page provides:

- active/inactive status;
- safe title and public message;
- optional estimated completion time;
- enable/disable actor and timestamps;
- accessible preview;
- typed enable and disable confirmation in canonical `x-crm.modal` dialogs.

Activation confirmation phrase:

```text
AKTIFKAN MAINTENANCE
```

Disable confirmation phrase:

```text
NONAKTIFKAN MAINTENANCE
```

No native `confirm()` is used. Server FormRequests remain authoritative.

## Maintenance Response

HTML responses use a standalone OASIS page that does not extend `layouts.crm` or `layouts.guest`. It uses inline CSS and no external font, Vite bundle, Alpine module, notification polling, presence heartbeat, comments polling, AI widget, or operational view composer.

Safe content only:

- OASIS identity;
- public title and message;
- optional estimated completion time;
- retry guidance;
- authenticated logout action.

The page never exposes actor data, permission slugs, database details, paths, migrations, exceptions, commit identifiers, or environment values.

JSON contract:

```json
{
  "message": "OASIS sedang dalam pemeliharaan.",
  "maintenance": true,
  "estimated_end_at": null
}
```

Both response formats use HTTP 503. `Retry-After` is included when an estimated end is configured.

## Activity Logging

Transition events:

- `operational_maintenance_enabled`;
- `operational_maintenance_disabled`.

Curated properties contain actor ID, singleton key/version, previous enabled state, public title, a bounded safe message summary, estimated end, and enabled duration where applicable. The full request, credentials, tokens, sessions, cookies, emails, and exceptions are never stored.

Blocked requests do not generate ActivityLog rows, preventing log floods.

## Scheduler, Queue, And Console

Operational maintenance is HTTP-only. It does not stop or modify:

- Laravel scheduler;
- existing scheduled sync commands;
- cleanup commands;
- queue configuration;
- direct console commands.

Use `php artisan down` for deeper infrastructure maintenance and `php artisan up` for native emergency recovery.

## Implementation Plan

1. Commit this audited architecture and route contract.
2. Add registered permissions, additive Pusat bypass mapping, singleton storage, model, and centralized service.
3. Add the centralized middleware and exact route-group placement.
4. Add management FormRequests, controller, routes, canonical administration page, and permission-aware navigation.
5. Add the standalone OASIS HTML response and stable JSON response.
6. Add focused permission, concurrency, blocking, lifecycle, response, audit, navigation, fail-safe, and regression tests.
7. Update verified architecture documentation, add one idempotent Changelog entry, run migrations, full tests, and production build.

## Manual Acceptance Checklist

### Superadmin

- open maintenance administration;
- enable maintenance;
- retain normal CRM access;
- disable maintenance.

### Primary Pusat

- retain CRM access during maintenance;
- remain unable to manage maintenance by default.

### Normal user

- active session receives HTML 503;
- direct Dashboard receives 503;
- writes do not mutate data;
- AJAX receives JSON 503;
- logout remains reachable;
- access returns immediately after disable without a new login.

### Sales

- Buku Saku is blocked;
- the standalone page creates no reminder/polling requests;
- quick-input writes are blocked before controller execution.

### Mobile

- readable at 360px, 390px, and 430px;
- no horizontal page scroll;
- logout remains reachable;
- title/message wrap safely.

### Failure

- storage read failure follows fail-open logging behavior;
- invalid or past estimated end is rejected;
- missing bypass user prevents activation;
- no redirect loop occurs.

## Rollback Plan

- Disable OASIS maintenance before reverting application code.
- Revert feature commits in reverse order.
- The Changelog migration removes only its exact entry.
- Permission rollback removes only the two exact registered permissions and cascading role pivots.
- Storage rollback drops only the dedicated singleton table; it contains no operational records.
- If application recovery is required while OASIS cannot boot, use the native Artisan maintenance commands rather than editing database state blindly.

## Known Limitations

- PHPUnit stale-version tests cannot prove production MySQL row-lock timing.
- A database outage may still break the requested controller after maintenance fails open.
- Persistent PHP workers may need restart after new permission deployment because registered permission slugs use process-local caching.
- No automatic schedule, expiry, branch scope, module scope, read-only mode, or deployment orchestration is provided.
- Browser verification is reported only when actually performed.
