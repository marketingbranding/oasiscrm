# OASIS CRM Engineering and UI Standards

This is the primary instruction file for AI coding agents working in OASIS. Read it before planning or editing. It describes verified current behavior and clearly marked target direction.

## 1. Product

OASIS (Online & Offline Sales Integration System) is an internal workplace and CRM for a subsidized-housing developer. It is not a generic SaaS dashboard.

Current business domains include:

- sales leads and Buku Saku Sales;
- Work Planner tasks, agendas, and content;
- branch and housing-project operations;
- Google Sheets synchronization and local caches;
- consumer and KPR progress;
- Dana Talangan;
- Pengeluaran;
- operational reporting;
- user identity, invitations, organization assignments, and permissions;
- local notifications, presence, optimistic conflicts, comments, and mentions.

Preserve domain terminology and existing Indonesian labels unless the task explicitly changes them. Do not replace domain language with generic CRM/accounting/SaaS terminology.

## 2. Source of Truth

Before implementing a feature, inspect this hierarchy:

1. current routes in `routes/web.php` and `routes/auth.php`;
2. relevant models and relationships;
3. policies, middleware, and FormRequests;
4. `WorkspaceAccessService`;
5. `OrganizationScopeService`;
6. existing services for the module;
7. current migrations and database constraints;
8. existing views, JavaScript modules, CSS primitives, and Blade components;
9. focused tests;
10. this file.

Executable code is the final source of truth when documentation and code differ. When a conflict is found:

- do not guess;
- document the conflict;
- preserve production behavior until explicitly asked to change it;
- update this file only when documentation is in scope.

Never invent routes, role slugs, permission slugs, tables, services, or components. Verify them first with repository search and route inspection.

## 3. Current Tech Stack

| Area | Current implementation |
|---|---|
| Backend | PHP `^8.3`, Laravel Framework `^13.8` |
| Authentication | Laravel Breeze-derived email/password flow; public registration disabled |
| Server UI | Blade, anonymous Blade components, Alpine.js |
| Styling | Tailwind CSS, PostCSS, shared CSS in `resources/css/app.css` |
| Frontend build | Vite 8 through `laravel-vite-plugin` |
| Browser behavior | Alpine.js modules registered in `resources/js/app.js`; SortableJS for Work Planner |
| Production database | MySQL according to `.env.example` and deployment assumptions |
| Development default | SQLite through `config/database.php`; `database/database.sqlite` exists |
| Tests | SQLite `:memory:` through `phpunit.xml` |
| Session | Database-backed by default; array driver in tests |
| Cache and locks | Database-backed by default; array driver in tests |
| Queue | Database connection by default; sync in tests |
| Mail tests | Array mailer |
| Local notifications | Custom `user_notifications` table with polling; separate from invitation mail notifications |
| XLSX | PhpSpreadsheet is the implemented library |
| OpenSpout | Installed but currently unused in application code |
| Google integration | `google/apiclient`; service-account credentials |
| Realtime | Polling-based collaboration; no Redis, Reverb, or WebSocket requirement |

Do not introduce Redis, Supervisor, Reverb, queues, or a new frontend framework as a hidden prerequisite. If a task needs one, state and justify it explicitly.

### Environment distinctions

- Normal defaults use database-backed sessions, cache, locks, and queue. The backing database may be SQLite locally or MySQL in production.
- PHPUnit overrides cache/session to `array`, queue to `sync`, mail to `array`, and database to SQLite in-memory.
- Cache-lock behavior in tests is not identical to production database locks.
- No current application job implements `ShouldQueue`; invitation and import mail delivery is synchronous even though the queue infrastructure exists.

## 4. Commands

### Setup and development

- `composer setup` - install dependencies, create `.env` if absent, generate key, migrate, install npm packages, and build assets.
- `composer dev` - run Artisan server, queue listener, Pail, and Vite concurrently.
- `npm run dev` - Vite development server.
- `npm run build` - production Vite build. There is no npm test/lint/type-check script.

### Tests and validation

- `composer test` - clear config and run the complete Artisan test suite with a 512 MB memory limit.
- `php artisan test` - complete test suite.
- `php artisan test tests/Feature/DashboardTest.php` - focused test file.
- `vendor/bin/pint --dirty --test` - format check for changed PHP files.
- `vendor/bin/pint --test` - repository-wide format check. The repository currently has legacy formatting failures; report them instead of silently formatting unrelated files.
- `git diff --check` - whitespace validation.
- `php artisan route:list` - verify route names, ordering, and middleware.

### Cache and Blade

After controller, route, provider, middleware, or Blade changes:

1. `php artisan optimize:clear`
2. `php artisan view:cache`
3. focused tests
4. full tests where the risk warrants it
5. `npm run build` when frontend source or Tailwind-scanned markup changed

### Synchronization and cleanup

- `php artisan konsumen-progress:sync --branch=ID`
- `php artisan dana-talangan:sync --dry-run`
- `php artisan sheet:cleanup-meta --branch=ID --dry-run`
- `php artisan oasis:presence-cleanup`
- `php artisan oasis:presence-diagnostics`
- `php artisan oasis:notifications-cleanup --dry-run --days=N`
- `php artisan oasis:user-import-cleanup --dry-run`
- `php artisan schedule:list`

`sheet:cleanup-meta` removes every remote column whose header exactly matches an OASIS Database metadata header. It does not detect only stale columns. Always dry-run first, verify the selected branch sheets, and sync afterward when appropriate.

## 5. Architecture

### Main route protection

The primary CRM route group in `routes/web.php` currently uses:

```text
auth
active
verified
password.changed
sales.access
```

Exceptions exist and must be audited before changes:

- password-change routes omit `password.changed`;
- profile routes use a narrower middleware set;
- invitation activation, login, forgot-password, and reset-password are guest flows;
- email verification and logout have their own auth grouping.

Middleware aliases are registered in `bootstrap/app.php`:

| Alias | Responsibility |
|---|---|
| `role` | Legacy primary-or-supplemental role matching |
| `branch` | Branch middleware |
| `password.changed` | Forced password-change requirement |
| `active` | Account lifecycle enforcement |
| `permission` | Exactly one registered permission |
| `permissions.all` | Every listed permission is required |
| `sales.access` | Primary Sales route allowlist |
| `operational.maintenance` | Full protected-CRM maintenance blocking with permission-based bypass |

Do not pass multiple slugs to `permission:`. Use `permissions.all:` only for explicit AND semantics. For dynamic OR behavior, prefer a policy or a clearly named centralized ability rather than ambiguous middleware arguments.

### Current modules

- Dashboard
- Buku Saku Sales, sales leads, sales agendas, weekly reports, daily reminders
- Work Planner
- Database
- Konsumen Progress
- Dana Talangan
- Pengeluaran and Expense Categories
- User Administration and invitation onboarding
- Bulk user onboarding by XLSX
- Branch, project, Kavling, and lead-source administration
- Feedback reports
- System health and Changelog
- Full operational maintenance administration
- Internal Design System showcase for primary Super Admin
- Local notifications, presence, comments, and mentions
- AI Chat conversations and scoped read tools

AI Chat exists as a separate feature. Do not mix it into unrelated work.

### Important model families

| Domain | Models / tables |
|---|---|
| Identity | `User`, `Role`, `Permission`, `UserInvitation`, `UserImportBatch`, `UserImportRow` |
| Organization | `Branch`, `LeadMaster`, `ProjectUser`; pivots `branch_user`, `project_user`, `role_user`, `role_permission` |
| Sales | `SalesLead`, `ContentItem`, `UserDailyReminderDismissal` |
| Finance | `DanaTalangan`, `DanaTalanganSyncStatus`, `Expense`, `ExpenseCategory` |
| Google caches | `DatabaseSheetRecord`, `DatabaseSheetSyncStatus`, `KonsumenProgressSheetRow`, `KonsumenProgressSyncStatus` |
| Collaboration | `ActivityLog` (`activity_log`), `UserNotification`, `UserPresence`, `Comment`, `CommentMention`, `CommentRevision`, `CommentModeration` |
| Administration/system | `LeadSource`, `Kavling`, `SystemTaskRun`, `AiChatConversation`, `Changelog` |
| Operational maintenance | `OperationalMaintenanceSetting` |

Cast gotchas:

- `User.account_status` is an `AccountStatus` enum after hydration.
- `Expense.amount` is a `decimal:2` string cast; never use float storage for money.
- `Comment.lock_version` and `Expense.lock_version` are integers.
- `DatabaseSheetRecord` JSON fields and `KonsumenProgressSheetRow.row_data` are arrays.
- `ContentItem.pic_names` is an array.
- `DanaTalangan`, `Expense`, and `Comment` use soft deletes.

## 6. Identity and Authorization

### Account lifecycle

Current statuses:

- `pending_invitation`
- `invited`
- `active`
- `suspended`
- `inactive`
- `anonymized`

`account_status` is authoritative. `is_active` remains synchronized for compatibility. Only active, verified users may access protected CRM routes. Suspend/deactivate preserves historical data and invalidates database sessions. Anonymized accounts cannot log in, cannot be edited or reset-access, and keep all operational references while personal fields become tombstones.

Lifecycle hardening via `UserLifecycleService`:

- `users.anonymize` and `users.release_email` are permission-gated (superadmin + pusat) actions; `users.delete_permanently` is superadmin-only.
- Anonymization replaces `name`/`email`/`phone` with tombstones, revokes active invitations, clears sessions and `remember_token`, and records an audit event. Emails are released through a non-routable tombstone pattern `deleted+<id>+<random>@invalid.oasis.local`.
- Email release is allowed only for deactivated (`inactive`) accounts and frees the address for reuse while preserving identity.
- Permanent deletion is restricted to strictly safe drafts: `pending_invitation`, unverified email, never logged in, with no comments, notifications, invitations, import records, planner items, sales leads, expenses, dana talangan records, memberships, or reports. Any blocker denies deletion and suggests anonymization.
- `UserLifecycleService::revokeUserTokens()` runs inside every suspend/deactivate/anonymize transaction: deletes the target's sessions, `password_reset_tokens`, pending invitations, and personal access tokens if present, and rotates `remember_token`. Reactivate never restores old sessions or tokens; normal login is required.
- Critical-capability continuity is enforced transactionally: the last eligible active holder of `system.maintenance_manage`, `system.maintenance_bypass`, or `users.update` cannot be suspended, deactivated, or anonymized. Eligibility requires active status, verified email, completed password change, and a primary role grant (superadmin wildcard counts; supplemental roles do not).
- Last-active-superadmin, self-action, and rank-escalation protections remain enforced. Emergency recovery is documented in `docs/IAM_USER_LIFECYCLE_2_AUDIT.md`: prefer another active superadmin or bypass holder, then Tinker, then direct DB as last resort; keep `account_status`, `is_active`, `email_verified_at`, `password_changed_at`, and `password` synchronized, record an `emergency_access_restored` ActivityLog, and rotate credentials afterward.

Public self-registration is disabled. Internal onboarding uses `UserInvitationService`:

- raw invitation tokens are never stored;
- token hashes use SHA-256;
- invitations expire after 72 hours by default;
- resend revokes previous unused invitations;
- activation sets the user password, verifies email, activates the account, and regenerates session state;
- invitation mail is synchronous and recoverable through resend after failure.

Bulk onboarding is XLSX-only, database-staged, previewed, all-or-nothing for validation, limited to 500 user rows, and limited to 100 synchronous invitation sends per batch. Reuse the existing parser/execution services; do not build a second onboarding system.

### Current roles

Canonical organizational roles:

| Slug | Display label |
|---|---|
| `sales` | Sales |
| `sales_coordinator` | Koordinator Sales |
| `supervisor` | Supervisor |
| `manager` | Manager |
| `branch_manager` | Branch Manager |
| `pusat` | Tim Pusat |
| `superadmin` | Super Admin |

Legacy compatibility roles remain:

| Slug | Display label |
|---|---|
| `admin` | Admin |
| `staff` | Staff |

Do not delete or rename legacy roles without a data-migration plan.

### Permission architecture

- `users.role_id` is the primary role and the source of permission resolution.
- `role_user` remains a supplemental legacy relationship.
- `hasRole()` checks primary and supplemental roles.
- `hasPrimaryRole()` and `isSales()` use only the primary role.
- Supplemental roles do not grant permissions.
- Registered permissions come from `PermissionCatalog` and `role_permission`.
- Superadmin has a wildcard only for registered permissions.
- Unknown permission slugs return false.
- There are no per-user permission overrides.

When adding a permission:

1. add it to `PermissionCatalog`;
2. define deliberate default role mappings;
3. deploy it through an idempotent migration;
4. add middleware/policy/controller/query/UI tests;
5. do not rely only on seeders.

`pusat` is not equivalent to superadmin. It receives only explicitly mapped operational permissions and must not inherit system configuration automatically.

### Standard authorization formula

A user may perform an action only when:

1. the account is active and verified;
2. the user has the required registered permission;
3. the user may enter the module;
4. the user is within the required branch/project/team/own scope;
5. the target record policy permits the action.

Navigation visibility never replaces backend authorization. Protect direct URLs, exports, AJAX, sync, bulk actions, and notification/deep-link destinations.

Protected CRM routes also enforce `password.changed`; primary Sales routes pass through the `sales.access` allowlist. Returned data must use the same organization scope for page views, exports, reports, aggregate/KPI queries, autocomplete, notifications, and background/sync actions.

### Sales restrictions

`RestrictSalesModuleAccess` applies to primary-role `sales`. Current allowed areas include:

- Buku Saku Sales and sales workflows;
- Work Planner;
- required presence and notification routes;
- comments;
- selected feedback routes;
- profile/password/logout technical flows outside the main group.

Do not broaden the allowlist accidentally when adding a route. Supplemental privileged roles must not bypass the primary Sales restriction.

## 7. Centralized Access and Data Ownership

### WorkspaceAccessService

Use `WorkspaceAccessService` for:

- accessible active branches;
- `can_view`, `can_edit`, `can_sync`, and `can_manage_members` branch rights;
- accessible projects;
- requested/default branch and project resolution;
- Sales project assignments and assignment date windows.

Current behavior:

- `canViewAllBranches()` is true for superadmin or a primary role with a configured module `view_all` permission. It is not hard-coded to `pusat`.
- Normal access comes from `branch_user.can_view` plus a limited legacy primary-branch fallback.
- Primary fallback does not grant member-management rights.
- Sales projects must be active, in an accessible branch, and covered by an active current `project_user` assignment.
- An explicitly requested unauthorized branch/project must result in denial, not a silent fallback.

### OrganizationScopeService

Use `OrganizationScopeService` for:

- visible user IDs;
- branch IDs;
- project IDs;
- direct reports and descendants;
- team/organization visibility intersections.

Supported modules currently include:

- `sales_pocketbook`
- `work_planner`
- `database`
- `consumer_progress`
- `bridge_fund`
- `expenses`

Supported scope suffixes are `own`, `team`, `assigned`, `branch`, and `all`. A coarse route permission such as `database.view` does not itself create an organization scope; the corresponding scoped permission is still required.

Do not duplicate branch, project, team, rank, or assignment-window rules in controllers or Blade templates. Raw role comparisons are appropriate only when the business rule truly depends on organizational role rather than permission/scope.

### Organization assignment services

Reuse:

- `BranchAssignmentService` for primary/additional memberships;
- `ProjectAssignmentService` for active/primary/date-window assignments;
- `ReportingHierarchyService` for supervisor rank, shared scope, and cycle prevention;
- `UserAdministrationService` for privilege boundaries.

Invariants:

- primary branch is a branch membership;
- primary project is an active assigned project;
- Sales requires an active project assignment;
- inactive branches/projects cannot receive new assignments;
- existing inactive branch memberships are preserved; project assignment updates currently synchronize the selected pivot set and may remove omitted historical project pivots;
- supervisor cannot be self, lower authority, inaccessible, inactive, or cyclic;
- users cannot grant themselves role/status/organization changes;
- the last active superadmin cannot be suspended or deactivated.

## 8. Data and Integration Rules

### Google Sheets architecture

Do not describe Google integration as a fixed count of service classes. Current responsibilities are separated across:

- `GoogleSheetsApiService`
- `DatabaseSheetSyncService`
- `DatabaseSheetWriteService`
- `KonsumenProgressSyncService`
- `KonsumenPipelineService`
- `DanaTalanganGoogleService`
- `DanaTalanganOptionService`
- `GoogleScriptService`
- `SyncLockService`
- `SyncResponseService`

Google credentials default to `storage/app/google/service-account.json`. `GoogleSheetsApiService` fails construction when credentials are missing, so tests must mock external-facing services before resolving dependent controllers/services.

### Database and Konsumen Progress are separate sync systems

- `database.sync` populates `database_sheet_records` and related metadata/status caches.
- `konsumen-progress.sync` populates `konsumen_progress_sheet_rows` and its own status table.
- Dashboard sync controls invoke Database sync, not Konsumen Progress sync.
- Do not merge their routes, status tables, or completeness rules.

Database sync:

- reads branch spreadsheets through Google;
- preserves formulas as noneditable fields;
- normalizes duplicate/blank headers;
- replaces the branch cache only after a complete response;
- supports immediate remote create/update/soft-delete through `DatabaseSheetWriteService`;
- uses OASIS metadata columns for stable sync identity/deletion.

Konsumen Progress required tabs are case-sensitive:

- `data_konsumen`
- `bi_checking`
- `PSJB`
- `pemberkasan`
- `proses_bank`
- `ppjb_dev`
- `akad`
- `bast`

`KonsumenProgressSheetRow` is a replaceable cache row. Its ID and `row_hash` are not stable collaboration identities. Do not attach comments or durable references to cache row IDs.

### Dana Talangan

- Canonical Google tab: `Talangan`.
- Canonical range: `A:Q`.
- Visible columns: A:N.
- Hidden metadata columns O:Q: `oasis_sync_id`, `oasis_deleted_at`, `oasis_deleted_by`.
- Historical tabs are reference-only for project inference.
- The local `dana_talangans` table is the application cache and uses soft deletes.
- Google canonical rows win during sync; web CRUD pushes immediately.
- `--dry-run` must not mutate local records/status or Google data.
- Cabang/Proyek come from OASIS; Kav options use branch `data_kav` cache with live Google fallback.
- Access follows permission and workspace scope, not a hard-coded superadmin/pusat list.

### Work Planner and Sales Agenda

`ContentItem` / `content_items` represents:

- `task`
- `agenda`
- `content`

`scheduled_date` is the shared reminder/calendar date. Sales agendas also use `ContentItem` with `agenda_type=buku_saku_sales`, `owner_user_id`, and `sales_project_id`. General Work Planner policy and Sales Agenda access are not interchangeable; preserve the subtype-specific sales scope.

Visibility is permission- and organization-scope-based, then constrained by creator/assignee/team/branch rules. Do not restore old raw `superadmin/pusat` visibility shortcuts.

Work Planner writes use module-aware branch management through `WorkspaceAccessService`: `work_planner.manage_all` permits management in every active branch, while narrower roles still require the applicable active branch and `branch_user.can_edit` or the verified legacy primary-branch fallback. Supplemental roles never grant this permission.

### Pengeluaran

- Expenses are operational records, not an accounting ledger.
- Money is stored as `decimal(15,2)` and formatted only in UI/export.
- Cancellation preserves records and audit history; normal workflows do not permanently delete.
- Categories remain historical when inactive and cannot be selected for new entries.
- Expense optimistic locking includes `lock_version`.

### XLSX

PhpSpreadsheet is the canonical current implementation. OpenSpout is installed but unused; do not add a parallel XLSX architecture without justification.

Current patterns differ:

- Work Planner and Dana Talangan legacy importers load the active sheet synchronously and have fewer hardening limits.
- Bulk user onboarding is the strict reference for untrusted XLSX uploads: size/row limits, exact headers, ZIP checks, formula rejection, staging, preview, critical revalidation, and confirmation.
- Exports should use explicit text cells for untrusted strings, native numeric/date cells where applicable, formula-injection protection, `tempnam()`, worksheet disconnect, and `deleteFileAfterSend(true)`.

Do not install another spreadsheet package for normal OASIS XLSX work.

### Collaboration

Current collaboration infrastructure includes:

- optimistic conflict dialog and lock services;
- page/record presence;
- custom database notifications and 60-second polling;
- universal comments and mentions;
- activity logs;
- sync and conflict notifications.

Universal comment aliases are allowlisted:

- `sales-lead`
- `sales-agenda`
- `planner-item`
- `expense`
- `bridge-fund`

Comments are plain text, one reply level, soft-deleted, revisioned, mention-scoped, and optimistic-lock protected. Do not accept arbitrary morph class names. Konsumen Progress is intentionally unsupported because its cache identity is unstable.

Local collaboration notifications use `user_notifications`; invitation email uses Laravel mail notifications. Do not create a second notification center or add email delivery to ordinary comments without explicit scope.

### AI Chat

AI Chat sends scoped context to an external provider and can invoke internal read tools. Preserve conversation ownership, branch/project scope, and tool-level authorization. Never send secrets, credentials, hidden records, or broader organization data than the current user may view. Treat tool output and model responses as untrusted text, keep provider API keys in environment configuration, and do not add write-capable AI tools without explicit product scope, confirmation behavior, audit logging, and authorization tests.

## 9. Changelog

Every completed change that affects application behavior, UI, workflow, data, authorization, integration, configuration, or user-visible error handling must include one concise Oasis Changelog entry in the same change set.

Rules:

- Deploy entries through an idempotent migration in `database/migrations/`.
- Use `DB::table('changelogs')->updateOrInsert(...)` with stable identity, normally `version` + `title`.
- The database has no unique constraint on `version + title`; idempotency depends on this pattern.
- Use Indonesian user-facing title/description.
- Categories are `added`, `fixed`, `changed`, or `removed`; this is application validation, not a database constraint.
- `created_by` is `null` for system release entries.
- Do not invent a release version.
- There is no automatic release-version source in the repository. Use an explicitly supplied release version; otherwise keep `version` null.
- `down()` removes only the exact entry introduced.
- Group closely related outcomes into one entry.
- Verify the migration runs, exactly one row exists, and the Changelog page renders it.

No application changelog entry is required for pure tests, documentation, formatting, agent instructions, build artifacts, or behavior-neutral refactors.

## 10. OASIS UI Direction

**Target direction:** Modern operational CRM with a restrained retro workstation identity.

OASIS is not being redesigned into a generic rounded SaaS product. The retro identity is an accent system, not a requirement that every element look old.

Preserve:

- OASIS yellow;
- black as a strong structural accent;
- square or minimally rounded geometry;
- compact enterprise density;
- intentionally established Helvetica and Times typography;
- clear grids and workstation references;
- recognizable module accents.

Modernize:

- information hierarchy and progressive disclosure;
- spacing and grouping;
- navigation clarity;
- readable content density;
- responsive behavior;
- action hierarchy;
- accessibility;
- loading, empty, error, unavailable, and conflict states.

Avoid:

- glassmorphism;
- large rounded SaaS cards;
- excessive gradients or shadows;
- neon cyberpunk styling;
- decorative pixel art on every page;
- heavy black borders around every minor element simultaneously;
- displaying every module color together;
- literal copies of Lark, Notion, Linear, Salesforce, or another product.

### Current visual implementation

Current CRM pages extend `resources/views/layouts/crm.blade.php`. The layout contains the topbar, sidebar, notification dropdown, toast stack, conflict dialog, feedback bubble, AI widget where allowed, and footer.

Current module accents include:

| Module | Current accent |
|---|---|
| Dashboard page | `#8c9ae0` (sidebar currently uses `#9ab6c8`) |
| Database | `#d77a7a` |
| Work Planner | `#b3bd95` |
| Work Planner task / agenda / content | `#9ab6c8` / `#e6915d` / `#8c9ae0` |
| Buku Saku Sales | `#fcc20f` |
| Dana Talangan | `#f1c40f` |
| Pengeluaran | `#b3bd95` |
| User Administration | `#8c9ae0` |
| Danger | `#c0392b` |

The foundational token layer now exists in `resources/css/app.css`. It defines page and surface hierarchy, primary/muted/disabled text, border levels, OASIS yellow, semantic states, focus, a 4px spacing scale, typography roles, restrained radius/shadow/motion, shell dimensions, and finite module accents.

Database 2.0 is the first completed operational Design System migration. It uses canonical page, toolbar, state, status, button, control, sync, presence, and modal primitives while retaining its lazy sheet tabs, dynamic metadata fields, wide table, frozen identity columns, skeleton, and sync-refresh orchestration as Database-specific behavior. It still has no project filter, domain filter, pagination, export/import, bulk action, detail route, or comments integration.

Buku Saku Sales 2.0 is the second completed operational migration. It uses canonical page, toolbar, field, action, status, state, modal, pagination, presence, and table primitives while retaining URL-backed Leads/Agenda/Laporan views, primary-Sales versus monitoring hierarchy, daily reminder behavior, lead stages, Sales Agenda subtype/actions, weekly/custom metrics, drilldowns, and exports. Leads use one responsive record DOM; there is no backend search, chart, Kanban, new stage, or client-side report calculation.

Work Planner 2.0 is the third completed operational migration. It uses canonical page, toolbar, field, action, status, state, presence, and table primitives while retaining URL-backed Hari Ini/Kalender/Tugas/Agenda/Konten/Semua views, Today grouping, server-built calendar grid, task/agenda/content status workspaces, routed create/edit/import pages, detail JSON, Sortable status updates, comments, mentions, presence, optimistic conflicts, bulk endpoints, and XLSX import/export mappings. It adds no new item type, status, assignment rule, project relationship, calendar dependency, route, permission, or import/export architecture.

Work Planner 2.1 adds only focused local improvements: compact Calendar density, a read-only URL-backed Gantt tracking view, and reactive Sortable empty-state/count synchronization. It does not add routes, permissions, statuses, date mutation, Gantt editing, dependencies, or new business calculations.

Adoption remains incremental elsewhere. Many operational Blade views still repeat arbitrary colors and legacy utility combinations; do not claim those modules are already migrated. Module accents identify modules and never override semantic error/warning/success meaning. Use `docs/DESIGN_SYSTEM.md` for implemented APIs and `docs/DESIGN_SYSTEM_AUDIT.md` for the migration map.

## 11. Navigation Architecture

### Current

`NavigationService` builds server-rendered, permission-aware groups for Dashboard, Aktivitas, Sales, Operasional, Keuangan, Laporan, and Administrasi. Empty groups are omitted. `layouts.crm` renders this definition through shared navigation icons and shell components.

Desktop supports explicit expanded and collapsed states; navigation never depends on hover expansion. Mobile uses a labelled modal drawer. Shell preferences use `oasis.sidebar.collapsed` for the desktop width state and `oasis.sidebar.groups` for expanded group state. These keys are local-only UI preferences and must remain backward-compatible when shell behavior changes.

Do not describe the following grouping as already implemented.

### Target direction

Prefer 5-7 top-level groups:

| Group | Intended contents |
|---|---|
| Dashboard | Personal or organization overview |
| Aktivitas | Work Planner, agenda, notifications, activity feed where implemented |
| Sales | Buku Saku Sales, Database, Konsumen Progress, related sales workflows |
| Operasional | Dana Talangan and future project/consumer operations |
| Keuangan | Pengeluaran, contextual category management, related finance workflows |
| Laporan | Monitoring, review reports, exports, cross-module reports |
| Administrasi | Users, branches, projects, roles/permissions, system health, Changelog |

Rules:

- Never expose every route as a top-level link.
- Do not show an empty group.
- Configuration pages are contextual children, not peers of daily operational modules.
- Active group and active child must be clear.
- Do not add a top-level link without documenting why it is frequent, independent, and relevant to a meaningful user group.
- Build future navigation from centralized definitions plus permission/scope checks; do not duplicate an entire sidebar per role.
- Profile/logout belongs at the bottom or account menu. Changelog/System Health belongs under administration/system utilities.
- Persisted navigation state must not cause a layout flash or hide content before Alpine initializes.
- Do not duplicate the same destination in topbar and sidebar without a documented usability reason.

### Role principles

- Sales: Buku Saku, Work Planner, notifications, profile, comments, and explicitly allowed technical routes.
- Coordinator/Supervisor: personal work, team sales/planner, authorized monitoring.
- Manager/Branch Manager: assigned branch/project operations, reports, and team functions according to permissions.
- Pusat: explicit cross-branch operational permissions, not automatic system administration.
- Superadmin: all registered operational and system permissions.

### Information architecture decisions

A feature belongs in global navigation only when it is frequent, represents a major business domain, has an independent workflow, and is relevant to a meaningful authorized user group.

Use contextual/submenu navigation when the feature configures a parent module, is a specialized view of the same records, or is used infrequently. Use a page action when it creates or changes data in the current module but is not a separate destination. Put user, organization, permission, category, system, and infrastructure configuration under Administrasi.

### Sidebar standards

Desktop:

- support explicit collapsed/expanded control;
- icons in collapsed mode, labels in expanded mode;
- preserve accessible `title`/`aria-label` for collapsed items;
- controlled nested group expansion;
- active group may remain expanded;
- sidebar scrolls independently;
- important navigation cannot depend only on hover;
- pin/collapse is keyboard-operable.

Mobile:

- use a full or near-full-width labeled off-canvas drawer;
- default closed;
- backdrop and Escape close;
- destination selection closes the drawer;
- control body scroll while open;
- minimum 44px touch targets;
- never reuse desktop icon-only collapsed mode as the mobile experience.

### Topbar

The topbar owns global UI only:

- mobile navigation trigger;
- OASIS identity;
- future global search/command trigger;
- future controlled quick-create;
- existing notifications;
- account/profile access.

Module actions belong in the page header or module toolbar. Reuse the existing notification system; do not create module-specific notification centers.

## 12. Page and Component Standards

### Page shell

Major pages should use:

1. optional breadcrumb;
2. one page title;
3. concise description where useful;
4. one dominant contextual action;
5. tabs for stable views of the same module;
6. search/filter/action toolbar;
7. main content;
8. inline loading/empty/error state.

Do not repeat the same action in header and toolbar. Filters are not page-header actions. Tabs represent stable views, not submitted filters.

### Button hierarchy

- Primary: one dominant action per context, using the module accent or OASIS yellow when semantically appropriate.
- Secondary: neutral surface and strong border for supporting actions.
- Tertiary: low-weight text/link action.
- Danger: red destructive action with explicit label and confirmation.

Do not give every button a distinct color, use module accents for destructive actions, or style a primary action as an underlined table link. Icon-only controls need accessible labels and tooltips.

### Cards, sections, and KPIs

Cards group related information; they are not decoration around every value. Avoid nested card-on-card layouts and black title bars on every minor section.

A KPI block should contain a concise label, primary value, optional comparison/context, and an optional textual status indicator. Do not rely on color alone or use oversized KPI typography in compact operational views.

### Dashboard

A dashboard should answer:

1. what happened;
2. what needs action;
3. how performance is progressing.

Prefer primary KPIs, action-required queue, operational progress, recent activity, and relevant sync/system health. Do not display every available metric or give every metric equal weight. Use warning/danger colors only for genuine state and urgency.

Dashboard invariants:

- `$selectedBranchId` is initialized and passed to the view; this is covered behavior, not an unresolved bug.
- Superadmin with no selected branch aggregates appropriate data but does not receive a branch Database sync action.
- Normal users resolve a primary/accessible branch rather than silently receiving global scope.
- Dashboard sync posts to `database.sync`.

### Actual reusable Blade components

CRM anonymous components under `resources/views/components/crm/`:

- `add-button`
- `alert`
- `bulk-bar`
- `button`
- `card`
- `click-sort-th`
- `date-field`
- `detail-modal`
- `empty-state`
- `export-import`
- `field`
- `feedback-bubble`
- `filter-chip`
- `icon-button`
- `input-error`
- `loading-state`
- `modal`
- `notif-section`
- `page-header`
- `page-presence`
- `pagination`
- `account-menu`
- `nav-icon`
- `notification-menu`
- `sortable-th`
- `section`
- `status-badge`
- `sync-control`
- `sync-status-panel`
- `time-field`
- `toolbar`
- `topbar`

Other shared components:

- `resources/views/components/comments/panel.blade.php`
- `resources/views/components/conflict-dialog.blade.php`
- Breeze/generic components under `resources/views/components/` for auth/non-CRM layouts.
- Dashboard-specific KPI, section, quick-action, and timeline components under `resources/views/components/dashboard/`; these are not generic CRM primitives.

Current JavaScript modules registered in `resources/js/app.js` include pickers, custom select, bulk actions, presence, conflict, notifications, sync, toast, sales reminder, and comments.

Rules:

- Extend an existing component when its behavior matches.
- Create a reusable component after the same pattern appears in roughly three places.
- Do not create premature abstraction for one isolated view.
- Do not copy large markup blocks across modules.
- Do not assume a component is accessible merely because it exists; audit its current behavior.

### Target reusable primitives

The repository does not yet have canonical components for every pattern. Remaining future standard candidates include:

- navigation group/item;
- advanced-filter modal;
- month field;
- KPI block;
- canonical action cell.

Label these components as target direction until implemented. The behavior standards elsewhere in this document still apply to new pages even when no shared component exists; use an existing strong module pattern and extract a component only when reuse justifies it.

## 13. Forms, Tables, Search, and Feedback

### Forms and pickers

- Use server-side FormRequest validation and inline field errors.
- Labels remain visible; placeholders do not replace labels.
- Mark required fields clearly.
- Group long forms into logical sections.
- Destructive changes require confirmation.
- Primary/save and cancel actions must be visually clear.

Date fields:

- Use `.date-wrapper`, `.date-display`, `.date-text`, `.date-arrow`, and a visually hidden native date input.
- Behavior lives in `resources/js/crm-datepicker.js`.
- Prefer `x-crm.date-field` when it fits.
- Do not add another calendar library or duplicate picker logic in a view.

Month fields:

- Use `.month-wrapper`, `.month-display`, `.month-text`, `.month-arrow`, and a hidden month input.
- Behavior lives in `resources/js/crm-monthpicker.js`.
- No shared month Blade component exists yet.

Time fields:

- Use `x-crm.time-field` and `resources/js/crm-timepicker.js`.
- Preserve exact minute selection, keyboard operation, `Sekarang`, explicit confirmation, dynamic initialization, and viewport positioning.

Pickers share `resources/js/crm-picker-position.js`. Extend existing modules for dynamic behavior; do not create page-local calendars.

Current accessibility caveat: date/month pickers are not yet fully keyboard/ARIA complete. Treat better keyboard semantics and focus management as target direction, not existing capability.

### Tables

Use `.crm-table-scroll` and `.crm-data-table` from `resources/css/app.css`. Database is the canonical current reference.

Required canonical behavior:

- separated-border model, zero spacing, single-sided 2px cell borders;
- sticky black uppercase headers;
- compact Helvetica headers and Times data;
- zebra rows and yellow hover;
- horizontal scrolling;
- correct paginated row numbers;
- direct-click sorting with active indicator;
- frozen identity columns where useful;
- `.crm-boolean-box` for booleans;
- truncation with full-value `title`;
- predictable final action column.

Do not switch canonical tables to collapsed borders; sticky/frozen borders disappear in Chromium. Do not hide important desktop columns merely to avoid mobile scrolling. For critical mobile workflows, add a deliberate compact summary/detail alternative rather than weakening the desktop table.

Actions:

- Edit: blue `#0000ee`, bold, underlined.
- Hapus/destructive: red `#c0392b`, bold, underlined, confirmation required.
- Generate URLs with Laravel routes; never hardcode application paths.
- Bulk actions show selected count and remain permission-aware.
- Loading/empty/failure states appear inside the table region.

`x-crm.click-sort-th` follows direct-click sorting. `x-crm.sortable-th` still uses a dropdown and is legacy; do not copy it into new tables.

`title` may supplement truncated text for pointer users, but it is not the only accessible disclosure. Important full values must also be available through visible detail, an accessible label, or a keyboard/touch-accessible control.

### Search, filters, tabs, actions

Search:

- Keep meaningful search always visible.
- Search is separate from domain filters.

Filters:

- At most two simple inline filters.
- More than two domain filters use one advanced-filter modal.
- Show active filter count and user-readable chips.
- Provide `Hapus semua filter`.
- Preserve search when applying/resetting filters.
- Preserve active search/filter state through sorting, pagination, and export.
- Branch change clears an incompatible project.
- All-branch project labels include branch context; do not deduplicate distinct project IDs by name.

Dana Talangan and Pengeluaran are current strong toolbar references. Not every existing module has migrated to this standard.

Tabs:

- Tabs represent stable module views.
- Do not inject hidden domain filters when changing tabs.
- Tabs remain usable on mobile through scrolling/compact treatment.
- Use a dedicated validated return parameter such as `return_view`; do not reuse form data as redirect filters.

Actions:

- Keep list actions in the same bordered toolbar as search/filter where practical.
- One dominant primary action uses the current module accent.
- Export/import and other secondary actions use neutral surfaces.
- Danger actions use red, never module accent.
- Do not give every button a unique color.
- Icon-only controls require accessible labels and tooltips.

### Borders, surfaces, spacing, typography

Use 2px black borders for major tables, primary workstation panels, dialogs, selected navigation, and important action controls. Use lighter 1px neutral borders for passive/nested/secondary containers.

Do not put a heavy black rectangle around every KPI, label, button, and nested section simultaneously. Establish hierarchy with spacing, typography, grouping, and restrained surfaces.

Use a 4px spacing rhythm:

- related controls: 4-8px;
- toolbar groups: 8-12px;
- section padding: 12-20px;
- major section gaps: 20-32px;
- mobile may increase spacing for touch usability.

Typography roles:

- Helvetica/system sans: navigation, controls, labels, headers, tables;
- Times New Roman: intentional OASIS data/editorial accent;
- Arial Black: currently used for major CRM headings;
- uppercase: small labels, table headers, system sections, not body paragraphs.

Avoid arbitrary font mixing within one control.

### Status colors

- Red: error, destructive, overdue/critical.
- Yellow: warning, pending, OASIS brand accent when not confused with warning.
- Green: success, active, complete.
- Blue: information or navigation selection.
- Gray: neutral, inactive, secondary.

Every status also needs text/icon/label. Never rely on color alone. Module accents do not override semantic states.

### Feedback

- Transient CRUD outcomes use the shared toast stack in `layouts.crm`.
- Server redirects flash `success`, `warning`, or `error`.
- AJAX uses `window.oasisToast(...)` or the `oasis:toast` event.
- Field validation, duplicate warnings, assignment guidance, confirmations, empty states, and presence remain inline.
- HTTP 409 uses the shared `oasis-conflict` dialog, not a toast.
- Do not expose secrets or hidden record details in feedback.
- Distinguish no data from temporary integration failure.
- Do not create a page-specific toast/banner system when a shared primitive applies.

Required state coverage where relevant:

- loading;
- empty;
- success;
- warning;
- error;
- denied;
- integration unavailable;
- stale/conflict;
- offline/network failure.

## 14. Responsive and Accessibility

Accessibility is mandatory:

- keyboard-operable controls;
- visible focus states;
- semantic links/buttons;
- bound labels for inputs;
- `aria-label` for icon-only controls;
- Escape close for modal/drawer/dropdown;
- outside click is never the only close method;
- `x-cloak` for Alpine overlays;
- status not conveyed only by color;
- sufficient contrast;
- reduced-motion compatibility when animation is added;
- no hover-only critical content.

Every new modal dialog/drawer must provide:

- `role="dialog"` where appropriate;
- `aria-modal` for modal overlays;
- labelled heading relationship;
- initial focus;
- focus trap;
- focus restoration;
- viewport-safe scrolling.

Current caveat: some legacy/filter/detail dialogs and picker controls do not yet meet this complete standard. Improve them when in scope; do not claim they already do.

Use `x-crm.modal` for new generic CRM dialogs. Existing Sales, Comments, and conflict dialogs retain their verified specialized behavior until migrated deliberately; do not copy an inaccessible legacy modal. Native `confirm()` may remain in untouched legacy workflows, but new multi-step or sensitive workflows should use the canonical modal or a justified specialized dialog.

### Responsive requirements

Desktop:

- compact operational workspace;
- sidebar/main may scroll independently;
- wide tables scroll horizontally.

Tablet:

- navigation never obscures content;
- toolbars may wrap;
- filter/dialog flows remain usable.

Mobile:

- full labeled off-canvas navigation;
- intentional one-column sections, not blind desktop stacking;
- minimum 44px touch targets;
- primary actions reachable without horizontal scrolling;
- secondary actions may enter an overflow menu;
- forms, status summaries, and navigation never require horizontal scrolling;
- tables may scroll horizontally;
- modal content scrolls within viewport;
- sticky action bars cannot cover content/browser controls;
- no hover dependency;
- users must complete the workflow, not only view it.

Check approximately 360px, 390px, 430px, 1024px, and 1366px when browser verification is available.

Motion target:

- 100-200ms for dropdown/drawer/state transitions;
- no decorative bounce or motion that delays work;
- polling must not cause layout jumps;
- respect reduced motion.

### Iconography

- Reuse the existing inline SVG approach until a shared icon component is introduced.
- Do not mix random icon libraries.
- Icons support labels; they do not replace unclear concepts.
- Avoid emoji as permanent UI icons unless intentionally used as a retro/system indicator.
- A new icon dependency requires explicit justification.

## 15. Testing

### Current test environment

- PHPUnit Feature and Unit suites.
- SQLite `:memory:` from `phpunit.xml`.
- Most database feature tests use `RefreshDatabase`; not every test requires it.
- User is the only model with a factory; create other models directly.
- Cache/session use array; queue sync; mail array.

Authenticated CRM test users usually need:

- active account state;
- `email_verified_at`;
- `password_changed_at`;
- a primary role with registered permissions;
- relevant branch memberships/project assignments/scoped permissions.

Mock Google services before resolving dependent controllers; credentials are checked during service construction.

### Required authorization matrix

Choose roles by permission/scope, not old labels such as only “superadmin versus branch admin.” Where relevant test:

- superadmin;
- primary `pusat`/view-all permission holder;
- branch-scoped user;
- team/assigned/own scope;
- primary Sales;
- denied/no-permission user;
- supplemental role non-escalation;
- direct URL, export, AJAX, sync, and bulk endpoints;
- requested inaccessible branch/project;
- active/inactive assignments and date windows.
- inactive, suspended, unverified, and forced-password-change account states when authentication lifecycle is relevant.

### Test expectations by change

- New permission: mapping, middleware/Gate, policy, supplemental-role denial.
- New route: middleware/order/direct access.
- Scoped query: allowed and forbidden branch/project/team records.
- XLSX: typed cells, formula safety, filters, authorization, workbook structure.
- UI behavior without JS tests: rendered markup and source-contract tests plus browser verification when available.
- Optimistic write: stale request returns 409 and does not overwrite.
- Notification: recipient isolation, access recheck, safe excerpt/action URL.
- Changelog: exact one row and rendered page.

Do not claim tests or browser verification that were not run.

## 16. Deployment and Completion Checklist

### Scheduler

Production must invoke Laravel scheduler every minute. Current schedules in `routes/console.php` include:

- Konsumen Progress sync every 10 minutes;
- Dana Talangan sync every 10 minutes;
- presence cleanup hourly;
- notification cleanup weekly;
- expired user-import cleanup daily.

All use overlap locks. Database cache/lock tables must be available.

Before production deployment, review `HOSTINGER_DEPLOYMENT.md` and `docs/PRODUCTION_ACCEPTANCE.md` together with current code because those documents may lag newer schedules. Confirm backup/restore readiness, production environment values, writable storage/cache paths, migration order, asset deployment, scheduler availability, and rollback decision points before applying data-bearing migrations.

### Completion checklist

Before substantial work:

1. audit routes and authorization;
2. inspect models/services/policies/migrations;
3. identify shared patterns;
4. inventory affected pages and role/scope impact;
5. write a compact information-architecture proposal for significant UI work;
6. define unchanged behavior and responsive states.

During work:

- preserve routes and behavior unless explicitly changing them;
- keep controllers thin and services centralized;
- use shared components;
- add safe reversible migrations;
- keep commits focused;
- never revert unrelated worktree changes.

After work:

1. run migrations;
2. clear caches and cache Blade when relevant;
3. run focused tests;
4. run the full suite where appropriate;
5. run Pint for changed PHP files;
6. build frontend assets when required;
7. run `git diff --check`;
8. inspect route middleware/order;
9. verify changelog uniqueness/rendering when required;
10. inspect final status/diff/log before committing.

Manual verification matrix where relevant:

- representative allowed roles/scopes;
- denied direct URL;
- desktop/tablet/mobile;
- search/filter/sort/pagination state;
- empty/loading/error/conflict states;
- sidebar item parity with route access.

### Visual regression checklist

- active navigation and active group;
- collapsed/expanded desktop sidebar;
- mobile drawer and backdrop;
- topbar and notification dropdown;
- page title and one primary action;
- search/filter/action toolbar;
- modal/dialog focus and close behavior;
- sticky/frozen table headers/columns;
- horizontal table scrolling;
- pagination;
- empty/loading/error states;
- inline validation;
- toast;
- conflict dialog;
- text contrast and keyboard focus;
- 1366px desktop, 1024px tablet, approximately 390px mobile.

Do not state visual/browser verification was completed unless it was actually performed.

### Rollback and data safety

- Existing data and legacy roles/memberships must be preserved.
- Do not add non-null columns without defaults/backfill.
- Review FK delete behavior against audit-history requirements.
- Do not casually rollback lifecycle, permission, invitation, comment, expense, or import migrations after production data exists.
- Keep notification/comment/import growth and retention in deployment planning.
- Do not include secrets or environment values in code, logs, changelogs, tests, or this file.

## 17. Known Gotchas and Prohibited Patterns

### Known gotchas

- `canViewAllBranches()` is permission-based and broader than a single module; use target policy/query scope as well.
- `role:` can see supplemental roles; permission resolution cannot.
- Coarse module permissions do not automatically create organization scope.
- Main CRM routes require active/verified/password-changed and pass through Sales allowlist.
- Dashboard Database sync and Konsumen Progress sync are separate.
- Konsumen Progress cache row IDs are unstable across sync.
- Dana Talangan uses one canonical global sheet and immediate web writes.
- `PSJB` tab casing is significant.
- Database metadata cleanup removes all matching metadata columns.
- Google credentials may fail during service construction.
- `x-trap` is not supported unless Alpine Focus is explicitly installed/registered; prefer verified local focus management.
- The layout has broad mobile control-size overrides; test compact overlays/tables carefully.
- Some legacy views still use `alert()`/native `confirm()` and incomplete dialog semantics. Do not copy those as preferred patterns.
- `x-crm.sortable-th` is legacy dropdown sorting; prefer direct-click sorting.
- OpenSpout is installed but unused.
- Lead Source CRUD is currently inside the protected CRM group but has no dedicated permission or policy. Treat this as a legacy compatibility gap, not a pattern to copy; audit and explicitly design authorization before changing it.
- Branch, Project, and Kavling routes use registered manage permissions, but their controllers also retain superadmin checks. Granting the route permission alone does not currently make those controllers available to another role.

### Prohibited engineering patterns

- No public self-registration.
- No authorization based only on hidden navigation.
- No raw role comparisons when a registered permission/policy expresses the rule.
- No duplicated branch/project/team scope logic in controllers or views.
- No trusting browser-provided role IDs, morph classes, branch/project access, preview data, or monetary formatting.
- No hardcoded application URLs; use named Laravel routes.
- No plaintext password/invitation/reset token logging or storage.
- No permanent delete for audited operational data unless explicitly designed.
- No arbitrary external model identity based on sheet row number.
- No new dependency when existing services/libraries solve the problem.
- No second notification, toast, conflict, picker, XLSX, or access architecture.

### Prohibited UI patterns

- No Bootstrap or full UI-library migration without approval.
- No React/Vue rewrite.
- No second Tailwind configuration.
- No new date/time picker library.
- No page-specific toast system.
- No duplicated role-specific sidebars.
- No raw user HTML or `x-html` for untrusted content.
- No generic rounded SaaS redesign or literal Lark clone.
- No broad removal of OASIS retro identity.
- No hiding authorization failures only through navigation.
- No inline style duplication when a shared token/component exists.
- No top-level menu item for every CRUD/configuration page.
- No module accent on destructive actions.
- No inaccessible hover-only navigation or content.

When a task intentionally changes one of these standards, document the reason, migration path, compatibility impact, and tests in the same change set.
