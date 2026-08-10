# IAM / User Lifecycle 2.0 Audit

This audit captures the current identity, authorization, and account-lifecycle implementation before the hardening pass. Executable code is the source of truth. This migration hardens lifecycle safety; it does not redesign authentication, invent roles, or broaden access.

## Architecture Map

### Users table (lifecycle-relevant columns)

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | stable, preserved through anonymization |
| `name` | string | personal field; anonymization target |
| `email` | string unique | unique index; anonymization/release target |
| `password` | hashed | `password_changed_at` tracks forced-change |
| `role_id` | FK users `set null` | primary role (authoritative for permissions) |
| `branch_id` | FK users `set null` | primary branch |
| `account_status` | string enum | `pending_invitation`, `invited`, `active`, `suspended`, `inactive` |
| `is_active` | boolean | synchronized with `account_status` in `User::booted` |
| `email_verified_at` | timestamp nullable | |
| `password_changed_at` | timestamp nullable | forced-password-change driver |
| `supervisor_user_id` | FK users `set null` | reporting hierarchy |
| `invited_at`, `activated_at`, `suspended_at`, `deactivated_at` | timestamps | lifecycle markers |
| `last_login_at`, `last_login_ip`, `last_login_user_agent` | | login audit |
| `created_by`, `updated_by` | FK users `set null` | actor tracking |
| `remember_token` | string | cleared on password change |

`account_status` is authoritative; `is_active` is synchronized by the `saving` hook (any non-`active` status sets `is_active=false`). The `active` middleware (`EnsureUserIsActive`) logs out any user whose `account_status !== active` and distinguishes suspended messaging.

### Roles and permissions

- `roles`: 9 canonical/legacy slugs (`sales`, `sales_coordinator`, `supervisor`, `manager`, `branch_manager`, `pusat`, `superadmin`, `admin`, `staff`), `is_superadmin` flag, `is_active`.
- `permissions`: registered from `PermissionCatalog::permissions()` (explicit slugs + generated `module.action_scope` variants + `module.configure` / `module.delete_permanently`).
- `role_permission`: primary-role pivot; **the only permission source**. No per-user overrides exist.
- `role_user`: supplemental/legacy pivot; **never grants permissions**.
- `User::hasPermission()`: `isRegistered` guard → superadmin wildcard → `role.permissions` exact-slug match. Supplemental roles never consulted.
- `User::hasRole()`: checks primary role + supplemental `role_user` (authorization code must not rely on it).
- `User::hasPrimaryRole()`: primary role only. `isSales()` uses it.
- `canViewAllBranches()`: superadmin or any `module.view_all` permission.

### Lifecycle state diagram (executable)

```
[created draft] --send--> [invited] --accept--> [active]
     |                    |                        |
     +--<--revoke/expire--+        suspend/deactivate
                                   |          |
                                   v          v
                               [suspended] [inactive]
                                   |          |
                                   +--reactivate--> [active]
```

- `pending_invitation`: draft; password is a random placeholder; no verified email.
- `invited`: usable invitation issued (72h default); resend revokes prior pending.
- `active`: invited-activation or reactivation; only state allowed through middleware.
- `suspended`: temporary; reversible via reactivate; sessions deleted; identity/email/role preserved.
- `inactive`: long-term deactivation; reversible via reactivate; sessions deleted; identity/email/role preserved.

### Session/token invalidation (current)

- `UserAccountService::suspend/deactivate/reactivate/changePassword` call `deleteOtherSessions()` (deletes `sessions` rows by `user_id`).
- `changePassword` regenerates `remember_token`; suspend/deactivate do **not** clear `remember_token` (documented gap).
- Password reset only completes for `active` accounts (`NewPasswordController`).
- Invitation tokens are SHA-256 hashes, 72h expiry, revocable.

## Role / Permission Drift Report

`PermissionCatalog::rolePermissions()` is the intended canonical mapping. Fresh databases seeded by migration `2026_07_28_000012` reflect it exactly. The deployed development database predates later catalog updates and is missing several exact slugs:

| Module | Missing deployed slug | Role(s) | Impact | Class |
|---|---|---|---|---|
| Dana Talangan | `bridge_fund.view`, `bridge_fund.manage` | staff | deployed: staff denied index/CRUD; catalog: staff has assigned-scope access | B overly restrictive |
| Dana Talangan | `bridge_fund.export` | (catalog supervisor) | catalog supervisor has `export_assigned` only; export still denied | D none |
| Konsumen Progress | `consumer_progress.view` | staff | deployed: staff denied index; catalog: staff can view | B overly restrictive |
| Konsumen Progress | `consumer_progress.sync` | supervisor | deployed: supervisor view-only; catalog: supervisor can sync assigned | B overly restrictive |
| Pengeluaran | `expenses.view`, `expenses.create`, `expenses.update`, `expenses.cancel`, `expenses.export` | supervisor, branch_manager | deployed: denied; catalog: intended access | B overly restrictive |

All drift is class B (overly restrictive) or D (no effective change). No security exposure exists (nothing is more permissive than the catalog). `role_user` supplemental pivots contain no permission-bearing grants; supplemental roles are descriptive only.

Approved fix: one idempotent insert-only synchronization migration that adds every catalog-defined pivot that is missing (`insertOrIgnore`), preserving any deliberate custom grants. This aligns deployed and fresh databases without deleting anything.

## Foreign-Key / Reference Audit (deletion safety)

References from other tables to `users`:

| Table | Column(s) | On delete | Hazard |
|---|---|---|---|
| `comments` | `user_id` | **restrict** | blocks deletion if any comment |
| `user_import_batches` | `uploaded_by` | **restrict** | blocks deletion |
| `sales_leads` | `sales_user_id` | **restrict** | blocks deletion |
| `expenses` | `created_by` | **restrict** | blocks deletion |
| `content_items` | `created_by` | **cascade** | deleting user deletes planner items (data loss) |
| `user_notifications` | `user_id` | cascade | notification loss |
| `user_presences` | `user_id` | cascade | presence loss |
| `user_invitations` | `user_id` | cascade | invitation loss |
| `branch_user`, `project_user`, `role_user` | `user_id` | cascade | membership loss |
| `activity_log` | `causer_id` | set null | audit actor link nulled |
| `comment_mentions` | `mentioned_user_id` | set null | mention link nulled |
| `comment_revisions` | `edited_by` | set null | |
| `user_notifications` | `actor_user_id` | set null | |
| `user_import_rows` | `created_user_id` | set null | |
| `content_items` | `owner_user_id`, `updated_by` | set null | |
| `sales_leads` | `created_by`, `updated_by` | set null | |
| `expenses` | `updated_by`, `cancelled_by` | set null | |
| `dana_talangans` | `created_by` | **no action** | FK violation if referenced |
| `users` | `created_by`, `updated_by`, `supervisor_user_id`, `role_id`, `branch_id` | set null | |

**Conclusion**: permanent deletion of any history-bearing user is unsafe (restrict FKs block, cascade FKs destroy data, no-action FK errors). Permanent deletion is only safe for a strictly verified draft: `pending_invitation`, unverified email, never logged in, with zero references across all tables above.

## Email Uniqueness

- Unique index on `users.email` (single-column).
- Email change on `update` clears `email_verified_at`.
- No anonymization or email-release flow exists today; an email can never be reused after a user is created.
- Approved: tombstone pattern `deleted+<id>+<random>@invalid.oasis.local` (non-routable `.invalid` TLD per RFC 2606; non-deliverable). Random suffix guarantees uniqueness. Release only via explicit permission-backed actions (`users.release_email`, `users.anonymize`).

## Self-Lockout and Critical-Account Safety (current)

- `UserAdministrationService::assertCanManage`: blocks acting on self, blocks non-superadmin acting on superadmin, blocks rank escalation.
- `assertNotLastActiveSuperadmin`: prevents suspend/deactivate of the last active superadmin.
- Gaps: no check exists for maintenance-bypass/manage capability holders; no protection against deactivating all pusat simultaneously. Documented; not added in this pass (preserves current behavior; emergency recovery is via a superadmin performing a direct DB reactivation).

## Sensitive Actions and Audit (current)

`AccountAuditService` records actor, target, old/new safe values, and reason for: create, update, status changes, invitation send/resend/revoke/activate, login, password events, role-permission changes, bulk onboarding. `safeValues` strips passwords/tokens. Missing today: anonymize, email-release, safe-deletion events (added by this migration).

## Approved Implementation Scope

1. Add `anonymized` lifecycle state (`AccountStatus::Anonymized`) + `users.anonymized_at` timestamp.
2. New `UserLifecycleService`:
   - `anonymize(target, actor, reason)` — revoke invitation, delete sessions + clear remember token, replace name/email/phone with tombstones, set `anonymized_at` + status `anonymized`, audit.
   - `releaseEmail(target, actor, reason)` — replace email with tombstone for deactivated accounts, audit (identity/name preserved).
   - `deletionBlockers(target)` — reference check across all tables above.
   - `permanentlyDeleteDraft(target, actor, reason)` — strict draft boundary; transaction; audit; deny if any blocker.
3. Permissions `users.anonymize`, `users.release_email` in catalog + role_permission for superadmin and pusat only.
4. Routes `admin-users.anonymize` (PATCH), `admin-users.release-email` (PATCH), `admin-users.destroy` (DELETE) with matching permission middleware.
5. Insert-only catalog role_permission sync migration (fixes class-B drift).
6. Admin user show view: canonical header, lifecycle status badge, accessible confirmation modal (x-crm.modal + reason field, focus trap, Escape, no native confirm) for suspend/deactivate/reactivate/anonymize/release/delete.
7. Guard: anonymized users cannot be edited or reset-access; update path aborts for anonymized targets.
8. Focused tests + documentation + idempotent changelog.

## Account Activation Modes

User administration supports two distinct onboarding contracts:

1. **Invitation activation** remains the normal path. The user starts as `pending_invitation`, receives a hashed, expiring invitation token, and becomes verified and `active` only after setting a password through the invitation flow.
2. **Direct activation** is an exceptional operator-assisted path restricted to an actor whose **primary role** is `superadmin`. A supplemental superadmin role, a wildcard-equivalent permission set, or another administrative role must not authorize it.

Direct activation must receive `must_change_password` as an explicit boolean. Its safe default is `false`; omission must never silently opt a user into forced-password behavior. Direct activation is permitted only when the submitted value is `true`, making administrator intent explicit and guaranteeing a mandatory password change on first login. The created user is immediately `active`, has `is_active=true`, `email_verified_at` and `activated_at` set, no active invitation, and `password_changed_at=null`. Existing `password.changed` middleware therefore permits authentication only into the mandatory password-change flow and blocks protected CRM access until the user replaces the initial credential.

Role, branch, project, supervisor, and other organization assignments must commit in the same database transaction as user creation and activation. Any validation, authorization, assignment, or persistence failure rolls back the entire operation; no partially activated or partially assigned account may remain.

Direct activation must not create an invitation, send invitation or credential email, or record plaintext passwords, temporary credentials, invitation tokens, or reset tokens in application logs, audit properties, notifications, URLs, or database fields. Passwords remain one-way hashes. Initial credential delivery, when operationally required, uses an approved out-of-band process outside OASIS.

After direct activation, account recovery follows the existing authenticated **reset-access active path**. It must not issue or resurrect an invitation for an already active account. Reset access revokes applicable sessions/tokens, establishes a new hashed credential through the existing protected flow, leaves the account active, and restores the mandatory-change boundary until the user changes that credential.

### Google Login Phase 2 compatibility

Google Login remains Phase 2 authentication convenience, not provisioning or activation. It may authenticate only an existing eligible OASIS account and must preserve the account's activation mode, primary role, assignments, lifecycle state, and forced-password-change boundary. OAuth callback success must not activate pending/invited users, bypass `password.changed`, create assignments, consume invitations, or convert direct activation into invitation activation. No Google token may appear in logs, invitation/reset flows, or user-facing URLs.

## Behavior Preserved (Explicit)

- Primary-role permission resolution; supplemental-role non-escalation; superadmin wildcard.
- All existing routes/names; invitation, reset-password, verification, forced-password-change, active-middleware behavior.
- Suspend/deactivate/reactivate semantics; `is_active` synchronization; session deletion.
- Operational ownership references (created_by/updated_by FKs) never intentionally nulled by this pass.
- No new business roles; no auth/SSO/OAuth/MFA/HR/payroll features.
- Indonesian terminology.

## Known Gaps (documented honestly)

- No protection against deactivating all pusat or the last maintenance-capable account simultaneously (only last-active-superadmin is guarded). Documented; recovery is a superadmin DB reactivation.
- No per-user permission overrides; none added.
- Emergency recovery procedure: connect to the production DB and `UPDATE users SET account_status='active' WHERE id=<superadmin>` via an authenticated operator; verify `is_active` syncs on next middleware pass.

# IAM Session & Recovery Hardening (follow-up audit)

## Token / Session Audit

| Token type | Storage | Revoke on suspend | Revoke on deactivate | Revoke on anonymize | Class |
|---|---|---|---|---|---|
| Web sessions | `sessions` table (database driver), `user_id` | YES (delete target rows) | YES | YES | A/B/C |
| `remember_token` | `users.remember_token` | YES (rotate) | YES (rotate) | YES (rotate) | A/B/C |
| Password reset tokens | `password_reset_tokens` keyed by `email` | YES (delete by current email) | YES | YES (delete by old email before replacement) | A/B/C |
| Invitation tokens | `user_invitations.token_hash` (SHA-256) | revoked if pending (defensive; active users normally have none) | same | YES (explicit revoke) | A/B/C |
| Email verification links | none stored (Laravel signed URLs, stateless) | remains valid by design; account status blocks login | same | `email_verified_at` nulled | D |
| Personal access tokens | `personal_access_tokens` table | guarded delete if table exists (not present in this app) | same | same | E not present |
| API tokens | none | n/a | n/a | n/a | E not present |

`reactivate` never recreates sessions and never restores remember tokens; the account returns to `active` and must log in normally.

## Access Invalidation (centralized)

`UserLifecycleService::revokeUserTokens(User)` deletes target sessions, deletes `password_reset_tokens` for the current email, revokes pending invitations, deletes personal-access tokens if present, and rotates `remember_token`. It is invoked inside the lifecycle transaction by `UserAccountService::suspend` / `::deactivate` and by `UserLifecycleService::anonymize` (which deletes reset tokens by the old email before replacing it). Other users' sessions and tokens are never touched.

## Critical Capability Guards

Effective primary-role permission resolution defines three critical capabilities:

| Capability | Permission | Business feedback |
|---|---|---|
| Maintenance management | `system.maintenance_manage` | "…satu-satunya akun aktif yang dapat mengelola maintenance." |
| Maintenance bypass | `system.maintenance_bypass` | "…satu-satunya akun aktif yang dapat melewati maintenance." |
| IAM administration | `users.update` | "…satu-satunya akun aktif yang dapat mengelola pengguna." |

A user is an eligible capability holder only when: `account_status = active`, `is_active = true`, `email_verified_at` set, `password_changed_at` set, and the primary role grants the permission (or the role is superadmin for the wildcard). Supplemental `role_user` roles never count.

`UserLifecycleService::assertCriticalCapabilityContinuity(User $target)` runs inside each lifecycle transaction after `lockForUpdate()`. If the target holds any critical capability and no other eligible holder remains (count excludes the target), the action throws `DomainException` with the business-language message. The controller converts it to a warning flash. `activeCapabilityHolderCount()` uses the same eligibility query with `lockForUpdate()` on MySQL so concurrent transitions serialize.

Today, with the current role matrix, every actor capable of suspend/deactivate/anonymize (pusat or superadmin) is itself an eligible holder, so the guard primarily acts as defense-in-depth and as a safety net if role mappings change. The last-active-superadmin protection (`assertNotLastActiveSuperadmin`) remains and covers the superadmin-only `system.maintenance_manage` case.

## Concurrency

Lifecycle transitions run in a database transaction with `lockForUpdate()` on the target and on the eligible-holder count query (MySQL). SQLite in tests has no real row locking; the guard logic is verified serially (transitioning the final two holders in sequence cannot remove both).

## Recovery Procedure (safer, documented)

1. **Preferred — another active Superadmin**: any other active, verified superadmin (or eligible `users.update`/`users.reactivate` holder) signs in and reactivates the locked account through the standard `admin-users` reactivate flow. This is the only path that preserves full audit history.
2. **Maintenance bypass recovery**: if maintenance is enabled and blocks login, an active `system.maintenance_bypass` holder signs in normally (bypass holders are not blocked); do not toggle maintenance off until IAM access is restored.
3. **Console / Tinker recovery**: if the application boots, run via `php artisan tinker`:
   ```php
   App\Models\User::where('email', 'operator@example.com')->first()
       ->update(['account_status' => 'active', 'is_active' => true, 'email_verified_at' => now(), 'password_changed_at' => now()]);
   ```
   Keep the five fields synchronized: `account_status='active'`, `is_active=true`, `email_verified_at` set, `password_changed_at` set, `password` set. Reset the password immediately afterward (`changePassword`) so the operator logs in with a known credential.
4. **Direct database recovery (last resort)**: `UPDATE users SET account_status='active', is_active=1, email_verified_at=CURRENT_TIMESTAMP, password_changed_at=CURRENT_TIMESTAMP WHERE id=<user>;` via an authenticated DB operator. Set a fresh `password` hash in the same transaction.
5. **Required audit entry**: after any manual recovery, record an `ActivityLog` (subject = recovered user, causer = operator or system, event = `emergency_access_restored`, properties = reason and operator id). No tokens, passwords, or session IDs are logged.
6. **Credential rotation**: force a password change and rotate `remember_token` after recovery; verify `is_active` syncs on the next middleware pass.

No unauthenticated recovery route or public backdoor exists. Manual recovery requires authenticated operator access to the application or database.

## Remaining Permission Drift (final review)

After the insert-only catalog sync, deployed `role_permission` matches `PermissionCatalog::rolePermissions()` for all roles (verified by `PermissionDriftSyncTest::test_catalog_mapping_is_fully_synced_to_deployed_pivots`). Remaining items are non-security backlog only:

- Scoped variants (`module.manage_assigned`/`export_*` for consumer_progress etc.) exist in pivots but have no module routes behind them — documentation-only.
- No exposure drift exists; no role has a pivot the catalog does not define.
- `system.maintenance_manage` has no non-superadmin pivot by design; only the superadmin wildcard grants it.
