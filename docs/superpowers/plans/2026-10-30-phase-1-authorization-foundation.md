# Phase 1 Authorization Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Establish one additive, testable authorization contract for Branch, Project, Kavling, User, Role/Permission, and Organization structure administration without broadening current production access.

**Architecture:** Add a small `SystemAdministrationAuthorizationService` as the authoritative owner for system-only organization administration areas. A matching middleware alias protects covered routes, controllers use the same owner instead of ad-hoc Superadmin checks, and `NavigationService` uses the same decision for discoverability. Existing `UserPolicy`, `UserAdministrationService`, `WorkspaceAccessService`, `OrganizationScopeService`, and organization graph services remain authoritative for scoped operational administration.

**Tech Stack:** PHP 8.3, Laravel 13.8, Blade, PHPUnit 12.5, SQLite test database, existing permission and middleware architecture.

**Spec:** `docs/superpowers/specs/2026-10-30-phase-1-authorization-foundation-design.md`

## Global Constraints

- Preserve current effective user access unless an explicit contradiction is resolved and reported.
- Do not grant additional access merely because a permission currently exists.
- Keep existing route URLs and route names stable.
- Do not remove `supervisor_user_id`, `sales_coordinator_sales`, `organization_assignments`, or compatibility projections.
- Do not begin Phase 2 organization graph migration or Phase 4 route restructuring.
- Do not refactor unrelated operational modules.
- Pusat remains operationally privileged but is not automatically a system administrator.
- Superadmin system privileges remain explicit and primary-role based.
- Existing branch, project, hierarchy, assignment, ownership, lifecycle, lock, and impersonation boundaries remain enforced.
- Add one concise Indonesian Oasis Changelog entry for the authorization behavior correction through an idempotent migration.

## Review Focus

- A non-Superadmin role with `branches.manage`, `projects.manage`, or `roles.view` must not gain system administration; test route, controller, and navigation denial.
- A supplemental Superadmin role must not escalate a primary non-Superadmin user; test direct URLs and navigation.
- A user with operational User/Organization permissions must retain only their existing branch, project, hierarchy, rank, and lifecycle scope; test same-scope success and cross-scope denial.
- JSON mutations must return authorization denial before domain mutation or validation side effects; test organization moves and bulk/mutation endpoints.
- System-only authorization must not suppress legitimate scoped User or Organization visibility; test Pusat, branch manager, and other seeded roles against existing policies/services.

---

### Task 1: Establish the authorization contract regression matrix

**Files:**
- Create: `tests/Feature/OrganizationAdministrationAuthorizationContractTest.php`
- Modify: `tests/Feature/ModulePermissionAccessTest.php`
- Modify: `tests/Feature/NavigationServiceTest.php`
- Modify: `tests/Feature/OrganizationWorkspaceAuthorizationTest.php`
- Modify: `tests/Feature/ProjectManagementTest.php` only if a missing direct-route/scope regression is found while composing the matrix

**Interfaces:**
- Consumes: seeded roles from `RoleSeeder`, permission definitions from `PermissionCatalog`, existing route names, `UserPolicy`, `UserAdministrationService`, and organization graph behavior.
- Produces: named regression tests that freeze the current effective allow/deny behavior before implementation changes.

- [ ] **Step 1: Add a role fixture helper that creates active, verified, password-changed users for all eleven relevant primary roles.**
  Use the existing `Role` records and `UserFactory`; do not attach supplemental roles when testing primary permission resolution except in dedicated escalation cases.

- [ ] **Step 2: Add the system-administration route matrix test.**
  Cover HTML view routes for branches, projects, Kavling, and roles; assert only primary Superadmin is allowed and every other relevant primary role receives 403. Include a separate user with a supplemental Superadmin role and assert 403.

- [ ] **Step 3: Add mutation and bulk-action coverage.**
  Cover branch update/assignment, project update/archive, Kavling bulk-store/bulk-delete, and role create/update/permission-update. Assert denied roles cannot mutate records and that allowed Superadmin mutations use valid minimal payloads.

- [ ] **Step 4: Add User and Organization structure matrix coverage.**
  Assert seeded permission-bearing roles retain existing User list/detail and Organization view/move behavior, while roles without the relevant permission receive 403. Reuse existing target setup and assert cross-branch/cross-project and higher-rank targets remain denied.

- [ ] **Step 5: Add route contract assertions.**
  Extend the existing route middleware contract test to require the current permission middleware plus the new system-only middleware for covered system routes, while preserving `not.impersonating`, maintenance, and account middleware.

- [ ] **Step 6: Add navigation assertions for every relevant primary role.**
  Confirm Branch, Project, Kavling, and Role/Permission destinations appear only for effective system administrators; confirm User and Organization destinations follow their existing capability checks.

- [ ] **Step 7: Run the new and affected tests before implementation.**
  Run: `php artisan test --compact tests/Feature/OrganizationAdministrationAuthorizationContractTest.php tests/Feature/ModulePermissionAccessTest.php tests/Feature/NavigationServiceTest.php tests/Feature/OrganizationWorkspaceAuthorizationTest.php tests/Feature/ProjectManagementTest.php`
  Expected: existing behavior passes; any intended system-only route assertion that currently exposes a permission-only path is recorded as the failing contradiction for Task 2.

### Task 2: Add the explicit system-administration authorization owner

**Files:**
- Create: `app/Services/SystemAdministrationAuthorizationService.php`
- Create: `app/Http/Middleware/SystemAdministrationMiddleware.php`
- Modify: `bootstrap/app.php`
- Modify: `tests/Feature/OrganizationAdministrationAuthorizationContractTest.php`

**Interfaces:**
- Consumes: `App\Models\User::isSuperadmin()` and the system-area names defined by the Phase 1 contract.
- Produces: `SystemAdministrationAuthorizationService::canManage(User $actor, string $area): bool`, `SystemAdministrationAuthorizationService::assertCanManage(User $actor, string $area): void`, and the `system.admin:{area}` middleware alias.

- [ ] **Step 1: Add failing unit/feature assertions for the owner.**
  Assert primary Superadmin is allowed for `branches`, `projects`, `kavlings`, `roles`, and `permissions`; all other primary roles, supplemental-only Superadmin users, inactive users, and unknown areas are denied.

- [ ] **Step 2: Implement the service with a closed area allowlist.**
  Keep the system-only decision explicit and primary-role based through `isSuperadmin()`. Unknown areas fail closed. Do not inspect supplemental roles or add permission mappings.

- [ ] **Step 3: Implement `SystemAdministrationMiddleware::handle(Request $request, Closure $next, string $area): Response`.**
  Resolve the authenticated actor, call the service owner, abort with 403 on denial, and otherwise continue. The middleware protects direct HTML, JSON, and mutation requests before controllers execute.

- [ ] **Step 4: Register the `system.admin` middleware alias in `bootstrap/app.php`.**

- [ ] **Step 5: Run the owner and contract tests.**
  Run: `php artisan test --compact tests/Feature/OrganizationAdministrationAuthorizationContractTest.php`
  Expected: PASS, with no role-permission mappings changed.

### Task 3: Align Branch, Project, and Kavling routes/controllers

**Files:**
- Modify: `routes/web.php:415-436`
- Modify: `app/Http/Controllers/Crm/BranchController.php`
- Modify: `app/Http/Controllers/Crm/ProjectController.php`
- Modify: `app/Http/Controllers/Crm/KavlingController.php`
- Modify: `tests/Feature/BranchRenameTest.php` if its authorization setup needs the shared owner
- Modify: `tests/Feature/ProjectManagementTest.php`
- Modify: `tests/Feature/OrganizationAdministrationAuthorizationContractTest.php`

**Interfaces:**
- Consumes: `SystemAdministrationAuthorizationService::assertCanManage()` and `system.admin:{area}`.
- Produces: Branch, Project, and Kavling actions guarded by the same explicit system-only owner at route/controller boundaries, with unchanged URLs, names, validation, synchronization, audit, and mutation behavior.

- [ ] **Step 1: Add route middleware assertions for Branch, Project, and Kavling groups.**
  Require `system.admin:branches`, `system.admin:projects`, and `system.admin:kavlings` on their respective route groups while retaining existing permission, maintenance, and impersonation middleware.

- [ ] **Step 2: Inject the service into the three controllers.**
  Replace private `ensureSuperadmin()`/inline `Auth::user()->isSuperadmin()` guards with `assertCanManage()` calls using the correct area. Remove only now-unused `Auth` imports; keep all target validation and domain services intact.

- [ ] **Step 3: Cover denied direct HTML, JSON-capable mutation, cross-project, and bulk requests.**
  Assert a denied actor cannot update or delete another branch/project/Kavling and no database record changes. Assert Superadmin behavior remains green.

- [ ] **Step 4: Run focused tests.**
  Run: `php artisan test --compact tests/Feature/OrganizationAdministrationAuthorizationContractTest.php tests/Feature/BranchRenameTest.php tests/Feature/ProjectManagementTest.php`
  Expected: PASS.

### Task 4: Align Role and Permission administration

**Files:**
- Modify: `routes/web.php:87-94`
- Modify: `app/Http/Controllers/Crm/RoleAdministrationController.php`
- Modify: `resources/views/...` only if the existing role-navigation destination exposes an action unavailable to the current actor
- Modify: `tests/Feature/OrganizationWorkspaceAuthorizationTest.php`
- Modify: `tests/Feature/OrganizationAdministrationAuthorizationContractTest.php`
- Create: `database/migrations/2026_10_30_000001_add_phase_1_authorization_changelog.php`

**Interfaces:**
- Consumes: `SystemAdministrationAuthorizationService::assertCanManage($actor, 'roles')` and the existing `roles.*` capability middleware.
- Produces: Role/Permission routes and controller actions that remain Superadmin-only even if a non-Superadmin role is manually given `roles.view`, `roles.create`, `roles.update`, or `roles.assign_permissions`.

- [ ] **Step 1: Add a failing contradiction test.**
  Create a non-Superadmin role with the registered role-administration permissions and assert its direct route and navigation access is denied by the system-only contract. Keep the existing primary Superadmin success test.

- [ ] **Step 2: Add `system.admin:roles` to the Role/Permission route group.**
  Keep the existing per-action permission middleware and `not.impersonating` checks so the route continues to distinguish capability from system authority.

- [ ] **Step 3: Add controller defense-in-depth through the shared owner.**
  Inject the service into `RoleAdministrationController` and assert the `roles` area before index/store/update/permission-update; do not alter role validation, wildcard Superadmin behavior, or audit logging.

- [ ] **Step 4: Add the changelog migration.**
  Use `DB::table('changelogs')->updateOrInsert(...)` with a stable null-version/title identity, Indonesian title/description, category `changed`, and `down()` removal limited to that exact entry. Describe the explicit separation of system administration from operational permissions.

- [ ] **Step 5: Run focused role and navigation tests.**
  Run: `php artisan test --compact tests/Feature/OrganizationAdministrationAuthorizationContractTest.php tests/Feature/OrganizationWorkspaceAuthorizationTest.php tests/Feature/NavigationServiceTest.php`
  Expected: PASS and exactly one changelog row for the new entry.

### Task 5: Align navigation and verify User/Organization ownership

**Files:**
- Modify: `app/Services/NavigationService.php`
- Modify: `tests/Feature/NavigationServiceTest.php`
- Modify: `tests/Feature/OrganizationWorkspaceAuthorizationTest.php`
- Modify: `tests/Feature/IdentityAccessManagementTest.php` only where a missing authorization contract regression is identified
- Modify: `tests/Feature/OrganizationAdministrationAuthorizationContractTest.php`

**Interfaces:**
- Consumes: `SystemAdministrationAuthorizationService::canManage()` for system-only destinations; existing `UserPolicy`, `UserAdministrationService`, `WorkspaceAccessService`, and organization graph rules for scoped destinations.
- Produces: Navigation entries whose visibility matches executable access for the covered administration modules without changing route destinations or shell structure.

- [ ] **Step 1: Inject the system authorization owner into `NavigationService`.**

- [ ] **Step 2: Replace Branch, Project, Kavling, and Role/Permission hard-coded permission/role combinations with the shared system-only decision.**
  Keep module maintenance markers, active-route patterns, labels, and route names unchanged. Do not replace User or Organization capability checks unless tests show a genuine contradiction.

- [ ] **Step 3: Add role-by-role navigation assertions.**
  Cover all eleven relevant primary roles, Pusat, and a supplemental Superadmin case. Assert no dead administrative links and preserve existing User/Organization links for legitimate scoped roles.

- [ ] **Step 4: Add cross-boundary regression assertions.**
  Confirm branch/project/Kavling direct targets cannot cross system boundaries for denied roles, User detail/update cannot cross visible scope, and Organization moves remain constrained by graph/workspace/request validation.

- [ ] **Step 5: Run focused authorization and navigation tests.**
  Run: `php artisan test --compact tests/Feature/OrganizationAdministrationAuthorizationContractTest.php tests/Feature/NavigationServiceTest.php tests/Feature/OrganizationWorkspaceAuthorizationTest.php tests/Feature/IdentityAccessManagementTest.php`
  Expected: PASS.

### Task 6: Phase 1 verification and scope audit

**Files:**
- Modify only files already listed above if verification exposes a Phase 1 regression.

- [ ] **Step 1: Clear application caches and compile Blade views.**
  Run: `php artisan optimize:clear` then `php artisan view:cache`.

- [ ] **Step 2: Run the complete PHPUnit suite.**
  Run: `php artisan test --compact`
  Expected: 0 failures; record the final test/assertion counts.

- [ ] **Step 3: Run whitespace, formatting, and frontend verification.**
  Run: `git diff --check`; `vendor/bin/pint --dirty --test`; `npm run build`.
  Expected: diff check passes. Pint must report only pre-existing unrelated legacy findings if any; do not broaden formatting scope. If the build still cannot resolve declared `@tailwindcss/vite`, investigate lockfile/node_modules installation state and report the environment blocker without changing application architecture or dependencies.

- [ ] **Step 4: Inspect route middleware and final diff.**
  Run: `php artisan route:list --except-vendor -v` and `git diff --stat`, `git diff --name-only`.
  Confirm no route names/URLs, organization schema, unrelated modules, or pre-existing untracked files were changed.

- [ ] **Step 5: Prepare the Phase 1 completion report.**
  Report sections must include: Authorization Contract Before, Authorization Contract After, Files Changed, Tests Added/Modified, Role × Action Matrix, Contradictions Resolved, Intentional Behavior Changes, Behavior Preserved, Remaining Authorization Debt, Test Results, and Recommended Phase 2 Plan. Stop after Phase 1; do not execute Phase 2.
