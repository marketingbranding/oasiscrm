# OASIS CRM

OASIS (Online & Offline Sales Integration System) is an internal CRM and operational platform for a subsidized-housing developer. It manages sales work, branch operations, consumer progress, finance operations, collaboration, and administration.

This document describes implemented behavior at the current handoff baseline. Proposed work is marked explicitly.

## Overview

Implemented operational areas include:

- Buku Saku Sales: Leads, Sales Agenda, reports, Sales Fee, reminders, lifecycle support, and optional Agenda photo evidence.
- Promo management.
- Work Planner: tasks, agenda, content, calendar, imports, exports, comments, mentions, and presence.
- Database: branch spreadsheet-backed records and local cache/write integration.
- Konsumen Progress: Google-backed branch sheet snapshots and pipeline views.
- Dana Talangan.
- Pengeluaran and expense categories.
- Feedback reports.
- User administration, invitations, bulk XLSX onboarding, roles, permissions, branches, projects, and Kavling.
- Notifications, activity logs, presence, comments, optimistic conflicts, AI Chat read tools, system health, Changelog, PWA controls, and module/global maintenance mode.

## Technology Stack

Versions come from the manifests and lockfiles:

- PHP `^8.3`.
- Laravel Framework `^13.8`.
- Blade and Alpine.js `^3.4.2`.
- Tailwind CSS `^3.1.0` with Tailwind Vite integration `^4.0.0`.
- Vite `^8.0.0`, Laravel Vite Plugin `^3.1`.
- Composer and npm.
- SQLite for local development by default in `config/database.php` and for PHPUnit `:memory:` tests.
- MySQL for production deployments documented in `.env.example` and `HOSTINGER_DEPLOYMENT.md`.
- PhpSpreadsheet `^5.8` for XLSX workflows. OpenSpout is installed but unused by current application code.
- `google/apiclient ^2.19` for Google Sheets and Google Script integrations.
- SortableJS for Work Planner interactions.

No Redis, Reverb, WebSocket server, or frontend framework is required by the current application.

## Core Architecture

The application is a Laravel server-rendered application:

- Routes live in `routes/web.php`, `routes/auth.php`, and scheduled commands in `routes/console.php`.
- Controllers coordinate requests; services contain domain workflows and external integrations.
- Blade views use shared `x-crm.*` components and Alpine.js modules.
- Eloquent models and migrations define local state.
- Policies, middleware, permissions, workspace access, and organization scope protect routes and records.
- Local notifications and polling provide collaboration features; there is no realtime server requirement.
- Changelog entries are inserted by idempotent migrations into `changelogs`.

### Local data versus mirrors

Some modules are local-first and some retain Google-backed snapshot/cache behavior:

- Local operational records include users, branches, projects, leads, Work Planner items, expenses, Dana Talangan records, comments, notifications, Agenda evidence metadata/files, and audit records.
- `DatabaseSheetRecord` and `DatabaseSheetSyncStatus` are branch spreadsheet cache/status tables used by Database sync. `DatabaseSheetWriteService` performs immediate remote writes where supported.
- `KonsumenProgressSheetRow` and `KonsumenProgressSyncStatus` are replaceable branch sheet snapshot/status tables. Their row IDs and hashes are not durable collaboration identities.
- Dana Talangan uses a canonical Google tab (`Talangan`) with a local cache and immediate web writes.
- Buku Saku Sales lifecycle sync can optionally push/pull against branch workbooks when `SALES_LEAD_GOOGLE_SYNC_ENABLED` and related permissions/configuration allow it. Local lead save must remain the product workflow; external sync is not a reason to expose credentials in tests.

Future direction: prefer normalized local data for new migrations away from Google mirrors. This is a recommendation, not a completed migration.

## Main Modules

Module maintenance keys currently defined in `config/oasis_modules.php`:

| Key | Label | Main route patterns |
|---|---|---|
| `database` | Database | `database.*` |
| `sales_pocketbook` | Buku Saku Sales | `sales-pocketbook.*`, `sales-fee-reports.*`, `sales-leads.*`, `sales-agendas.*`, `coordinator-leads.*`, `sales-reminders.*` |
| `promo` | Promo | `promos.*` |
| `consumer_progress` | Konsumen Progress | `konsumen-progress.*` |
| `dana_talangan` | Dana Talangan | `dana-talangan.*` |
| `work_planner` | Work Planner | `content-calendar.*` |
| `feedback_reports` | Laporan Feedback | `feedback-reports.*` |
| `users` | Pengguna | `admin-users.*` |
| `branches` | Cabang | `branches.*` |
| `projects` | Proyek | `projects.*` |
| `kavling` | Kavling | `kavlings.*` |

## Roles and Access Model

Canonical primary role slugs are:

- `superadmin`: system administration and all registered permissions.
- `pusat`: explicitly mapped cross-branch operational permissions; not equivalent to Superadmin.
- `branch_manager`: branch leadership permissions.
- `manager`: manager monitoring and assigned operational permissions.
- `supervisor`: supervisor monitoring and assigned operational permissions.
- `sales_coordinator`: operational Sales team monitoring.
- `sales`: own Sales workflows and assigned workspace.
- `admin`: legacy operational administration compatibility role.
- `staff`: legacy compatibility role.

`users.role_id` is the primary permission source. Supplemental `role_user` roles can support legacy `hasRole()` checks but do not grant permissions. Superadmin wildcard access applies only to registered permissions. Navigation visibility never replaces backend authorization.

Protected CRM routes generally require `auth`, `active`, `verified`, `password.changed`, and `sales.access`, with documented exceptions for password, profile, invitation, login, and logout flows.

### Scope services

- `WorkspaceAccessService`: accessible active branches/projects and branch view/edit/sync/member rights.
- `OrganizationScopeService`: visible IDs, branch/project/team/assigned/own/all scope intersections for supported modules.
- `ReportingHierarchyService`: managerial reporting relationships, descendants, authority, and cycle prevention.
- `SalesTeamScopeService`: current operational Sales team resolution. Coordinator → Sales membership comes from current `sales_coordinator_sales` rows with active dates and valid primary roles.

Do not use generic reporting descendants as a substitute for Coordinator operational membership. `users.supervisor_user_id` describes organizational/managerial hierarchy where applicable; `sales_coordinator_sales` is the operational Coordinator → Sales authority.

## Local Development Setup

### Requirements

Install PHP 8.3+, Composer, Node.js/npm, SQLite support for PHP, and a web browser. GD with WebP support and ZipArchive are required for Agenda Evidence processing and archive work. MySQL client/server is required only when developing against MySQL.

### First setup

Unix-like shells:

```bash
git clone <repository-url> oasiscrm
cd oasiscrm
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Windows PowerShell uses `Copy-Item .env.example .env`; Git Bash can use `cp` as above.

For safe local development, use SQLite:

```bash
# Git Bash / Unix
mkdir -p database
touch database/database.sqlite
```

PowerShell equivalent:

```powershell
New-Item -ItemType Directory -Force database
New-Item -ItemType File -Force database/database.sqlite
```

Set local `.env` values to SQLite, for example:

```dotenv
APP_ENV=local
APP_DEBUG=true
DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite
```

Then migrate and start processes in separate terminals:

```bash
php artisan migrate
php artisan serve
npm run dev
```

The Composer setup shortcut performs install, local `.env` creation, key generation, migration, npm install, and production build:

```bash
composer setup
```

Run both application and frontend processes with the configured concurrent workflow when appropriate:

```bash
composer dev
```

## Environment Configuration

`.env.example` is the source for available variables. Never copy production secrets into Git or tests.

### Application

`APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL`, `APP_VERSION`, `APP_COMMIT_SHA`, locale values, and `APP_MAINTENANCE_DRIVER`.

### Database, cache, session, queue

Production defaults in `.env.example` use MySQL, database sessions, database cache, and database queue. PHPUnit overrides these with SQLite `:memory:`, array session/cache, and synchronous queue. Local SQLite is recommended for development.

### Mail

Hostinger SMTP values are placeholders in `.env.example`. Invitation mail is synchronous in current code; ordinary comments use local database notifications and do not automatically send email.

### Google

`GOOGLE_SCRIPT_WEBHOOK_URL`, `GOOGLE_SCRIPT_TIMEOUT`, `GOOGLE_SCRIPT_VERIFY_SSL`, `GOOGLE_SHEETS_CREDENTIALS_PATH`, cache staleness, TLS/timeouts, `DANA_TALANGAN_SHEET_ID`, `DANA_TALANGAN_SHEET_NAME`, and `SALES_LEAD_GOOGLE_SYNC_ENABLED` control integrations. Keep credential files outside version control.

### Optional integrations

Feedback Discord and AI Chat variables are feature/configuration controlled. AI provider keys must remain in environment configuration and must never be logged or sent as data context.

## Database Setup and Cache Reset

Use migrations for schema:

```bash
php artisan migrate
php artisan migrate:status
```

Safe local reset after stopping processes and confirming the database is local-only:

```bash
php artisan optimize:clear
php artisan migrate:fresh
```

For a corrupted SQLite file, stop PHP/Vite/queue processes, rename `database/database.sqlite`, create a fresh ignored file, run `php artisan migrate`, clear caches, and rerun tests. Typical symptom:

```text
database disk image is malformed
```

This procedure is **local development only**. Never delete, rename, or reset a production database as troubleshooting.

Stale Laravel configuration can produce misleading behavior:

```bash
php artisan optimize:clear
```

Production cache commands, run only after checking configuration and migrations:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Google Integration and Test Isolation

`GoogleSheetsApiService` constructs only when the configured service-account file exists. The expected local/test state is that `storage/app/google/service-account.json` is absent.

Tests use service-container fakes/mocks before resolving Google-dependent services. `tests/TestCase.php::fakeGoogleSheets()` provides a reusable `GoogleSheetsApiService` mock. Individual feature tests also bind mocks where construction or external calls would otherwise occur. Missing production credentials must be fixed in production configuration; they must not be “fixed” by committing a credential or making unrelated tests depend on Google.

Google remains an integration boundary, not a universal source of truth. Inspect the specific module service before assuming whether a record is local, cached, replaceable, or remotely synchronized.

## Testing

Canonical commands:

```bash
php artisan test
composer test
```

`composer test` clears config and runs Artisan tests with a 512 MB PHP memory limit. PHPUnit uses SQLite `:memory:` and test-specific array/sync drivers.

Focused examples:

```bash
php artisan test tests/Feature/SalesAgendaEvidenceAuthorizationTest.php
php artisan test --filter=SalesAgendaEvidenceCleanup
php artisan test tests/Feature/AdminBranchSalesMonitoringTest.php
```

At this handoff baseline, the full suite passes. Snapshot only, not a permanent invariant: 843 tests and 7,296 assertions, with 9 deprecations.

Format changed PHP files before commit:

```bash
vendor/bin/pint --test path/to/changed.php
vendor/bin/pint --test
vendor/bin/pint
```

## Frontend Build and Security Audits

```bash
npm install       # or npm ci for lockfile-reproducible install
npm run dev
npm run build
npm audit
composer audit
```

Handoff snapshot: npm reports 0 vulnerabilities and Composer reports no security advisories. Do not run `npm audit fix --force` blindly; dependency upgrades require review.

On Windows, Vite/Rolldown native dependencies can produce `EPERM`. Stop Node/Vite processes, remove and recreate `node_modules`, then reinstall. Do not force dependency upgrades to bypass a file-lock problem.

## Buku Saku Sales Architecture

Buku Saku Sales is local-first where current workflows are implemented. Sales users work on their own leads and Agenda; Coordinator users monitor current operational Sales assignments; Supervisor users monitor their existing canonical scope; Admin Cabang monitors authorized branch data read-only. Branch/project access is always checked in addition to role and permission.

Sales Agenda is a `ContentItem` with `item_type=agenda` and `agenda_type=buku_saku_sales`. Lead lifecycle sync, where enabled, is a separate integration path with explicit permissions and branch workbook contracts. Do not describe or reintroduce the old assumption that all Sales workflows are Google pulls.

Lead sync status values currently used include `pending_create`, `synced`, `pending_update`, and `sync_failed`; external sync failures must not silently broaden local authorization.

## Agenda Photo Evidence

Evidence is optional for every Sales Agenda category. Current rules:

- maximum two photos per Agenda;
- JPEG, PNG, or WebP input;
- 10 MB per upload ceiling;
- valid image dimensions and a 40 million pixel safeguard;
- resized without upscaling to a maximum longest side of 1,600 pixels;
- converted to WebP quality 75;
- image metadata is not carried into the generated output;
- private local disks `agenda_evidence` and `agenda_evidence_archives`;
- no public direct URL; GET access goes through authorization and a private response;
- evidence deletion is restricted before Agenda completion.

Access is read-only except for the Sales owner mutation path:

| User | Evidence access |
|---|---|
| Sales owner | Upload, view, delete before Done |
| Coordinator | View only for current `sales_coordinator_sales` team, branch, and project scope |
| Supervisor | View only within existing Supervisor scope |
| Admin Cabang | View only within authorized branch scope; archive access where authorized |
| Superadmin | View; explicit Agenda cleanup capability |

Coordinator, Supervisor, and Admin Cabang cannot upload/delete evidence or purge evidence. Evidence authorization must match the Agenda monitoring scope; a visible Agenda must not produce a private-stream 403 for an authorized user.

### Archive lifecycle

`php artisan agenda-evidence:archive-weekly {--week=}` archives all branches for the prior week by default. The scheduler runs it weekly on Sunday at 02:00 with overlap protection.

Archives are grouped by branch/week, written as ZIP files to the private archive disk, and include `manifest.json`. Build and purge verify ZIP contents and SHA-256 checksums. Phase 1 stores archives on the same OASIS server; this is not disaster backup. Local evidence is eligible around 60 days after creation, subject to verified archive state. Purge is manual, Superadmin-only, requires `days >= 60`, and retains metadata after local file removal.

Archived or purged evidence blocks explicit Agenda cleanup with controlled conflict response, preserving the ZIP/archive record.

## Superadmin Cleanup and Impersonation

Normal impersonation follows the target user’s authority. A Superadmin does not receive a generic authorization bypass while impersonating.

A narrow explicit action exists on Sales Agenda detail: **Hapus sebagai Superadmin**. It requires:

- trusted server-side impersonation state;
- authenticated target still matching the trusted target session ID;
- original actor resolves to an eligible primary Superadmin;
- a reason and explicit confirmation;
- an ActivityLog event with the original Superadmin as causer.

The action deletes the selected canonical Sales Agenda and associated local evidence only when no evidence is archived or purged. It never changes general policies, role resolution, or target-user authority.

Start and stop routes are handled by `ImpersonationService`. Stop impersonation through the UI control or `POST /impersonation/stop` route. If trusted session state fails validation, the service fails closed and invalidates the session.

## Module Maintenance Mode

Current module keys are listed in the module table above. `EnforceModuleMaintenance` returns a branded HTTP 503 view for browser requests and a module-aware JSON 503 for JSON requests. A future estimated end can produce `Retry-After`; the value is calculated from current state, not a fixed cache TTL. Superadmin bypass behavior is determined by `ModuleMaintenanceService`; module management writes are blocked while impersonating.

Global operational maintenance is enforced separately by `EnforceOperationalMaintenance`. It has higher authority than module maintenance and returns branded HTML/JSON 503 responses. Authorized bypass is permission-based. Navigation may remain visible with maintenance indicators; hidden navigation is not an authorization control.

## Background and Scheduled Jobs

Current scheduler entries in `routes/console.php`:

- Konsumen Progress sync: every 10 minutes.
- Dana Talangan sync: every 10 minutes.
- Sales lead lifecycle sync: every 10 minutes.
- Agenda Evidence weekly archive: Sunday 02:00.
- Presence cleanup: hourly.
- Notification cleanup: weekly.
- Expired user-import cleanup: daily.

All scheduled jobs use overlap protection. Hostinger must invoke Laravel Scheduler every minute.

## Deployment to Hostinger

Read `HOSTINGER_DEPLOYMENT.md` and `docs/PRODUCTION_ACCEPTANCE.md` before release. Production uses MySQL, PHP 8.3+, HTTPS, writable `storage` and `bootstrap/cache`, database sessions/cache/queue defaults, and a cron entry similar to:

```cron
* * * * * /usr/bin/php /home/uXXXXX/oasis-crm/artisan schedule:run >> /dev/null 2>&1
```

Preferred SSH/VPS release order:

```bash
git status
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
php artisan optimize:clear
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Build assets with `npm ci && npm run build` only where Node is part of the deployment environment. Otherwise build in a controlled environment and deploy the generated `public/build` assets according to the existing Hostinger layout. Do not copy local `.env`; configure production values separately. Never force-reset production, commit secrets, or copy a Google service-account JSON into Git.

Required runtime capabilities for Agenda Evidence include PHP GD with WebP support and ZipArchive. Composer/Laravel also require common extensions including PDO, fileinfo, mbstring, ctype, filter, hash, openssl, session, tokenizer, JSON, and cURL for Google HTTP integration. Validate the actual Hostinger PHP build before release.

### Deployment safety

- Check `git status` before pull.
- Use `--ff-only`.
- Back up the production database and persistent storage before risky migrations.
- Keep `APP_DEBUG=false` and production `.env` outside the public web root.
- Verify writable storage/cache paths.
- Run `php artisan schedule:list`; this proves registration, not cron execution.
- Check System Health after deployment.

### Post-deploy smoke test

Login, role navigation, Buku Saku Sales, Agenda create/update, optional evidence upload, Coordinator evidence viewing, Admin evidence viewing, Supervisor monitoring, explicit Superadmin cleanup behavior in a safe test record, Lead save, Promo, Work Planner, Maintenance Mode, critical reports, and scheduler/system health.

## Common Troubleshooting

| Symptom | Check / recovery |
|---|---|
| `MissingAppKeyException` | Local only: run `php artisan key:generate`. Never generate or replace production keys casually. |
| `database disk image is malformed` | Stop local processes, rename the local SQLite file, create a fresh ignored file, migrate, clear cache. Never do this to production. |
| `vite: command not found` | `node_modules` is incomplete or npm is unavailable. Run `npm ci` or `npm install` from repository root. |
| Windows npm `EPERM` | Stop Node/Vite, remove/recreate `node_modules`, reinstall. Avoid `--force` upgrades. |
| Google credential missing | Expected in local/test verification. Bind a test mock; configure the real credential only in the environment that needs Google. |
| Scoped module returns 403 | Check active account, primary role, registered permission, branch membership, project assignment/date window, `sales_coordinator_sales` for Coordinator, and both Workspace/Organization scope. `branch_id` alone is not proof of access. |
| Stale routes/config/views | Run `php artisan optimize:clear`; recache only after configuration is correct. |
| Evidence processing unavailable | Verify GD includes WebP support and ZipArchive is enabled. |

## Repository Conventions

- Use `x-crm.*` anonymous Blade components and existing CSS primitives.
- Keep business workflows in services; keep controllers thin.
- Use named routes and existing route middleware/policies.
- Use migrations for schema and idempotent migration entries for Changelog records.
- Use ActivityLog for privileged/security-sensitive events.
- Add focused Feature tests for authorization, scope, direct routes, exports, sync, and mutation behavior.
- Preserve Indonesian domain labels and established OASIS terminology.
- Do not introduce a second access, notification, modal, XLSX, sync, or frontend architecture without explicit scope.

## Developer Handoff Checklist

Before changing code:

- Read `AGENTS.md` and this README.
- Check `git status`, branch, and baseline.
- Trace route → middleware → controller → policy/service → query.
- Identify local versus external data ownership.
- Confirm branch/project/team scope.

Before commit:

- Add/adjust focused tests when behavior changes.
- Run changed-file Pint and relevant tests.
- Run `git diff --check`.
- Run `npm run build` when frontend or scanned Blade changes.
- Run `npm audit` and `composer audit` for release work.
- Check no secrets or generated artifacts are staged.

Recommended future roadmap — not implemented:

1. Add GitHub CI for tests, build, Pint, and audits.
2. Migrate remaining Database Google mirror paths toward normalized local data.
3. Build a normalized Proses Konsumen workflow after validating current Konsumen Progress boundaries.

## Further Reading

- `docs/DEVELOPER_HANDOFF.md` — deeper architecture, boundaries, gotchas, and technical debt.
- `AGENTS.md` — engineering and UI standards.
- `HOSTINGER_DEPLOYMENT.md` — existing Hostinger deployment details.
- `docs/PRODUCTION_ACCEPTANCE.md` — production release acceptance.
- `docs/FULL_MAINTENANCE_MODE.md` — maintenance behavior and operational details.
- `docs/BUKU_SAKU_SALES_LOCAL_FIRST_ARCHITECTURE.md` — Sales local-first architecture.
- `docs/DATABASE_2_AUDIT.md` and `docs/KONSUMEN_PROGRESS_2_AUDIT.md` — current mirror/snapshot modules.
- `docs/PWA_1_INSTALLABLE_ANDROID.md` — PWA contract.

## License

OASIS CRM is an internal application. Consult repository ownership and deployment policy before redistribution.
