# OASIS CRM Developer Handoff

Status: documentation snapshot for main baseline `4a36963b1c3865bccde49433a2edb5198d79b197`.

This file is deeper engineering context. Use `README.md` for first-day setup and command quick reference. Current code, tests, migrations, and configuration remain authoritative if this document drifts.

## 1. Current Architecture

OASIS is a Laravel 13 server-rendered internal CRM for housing-sales operations. Blade and Alpine.js provide the UI. Eloquent models and migrations own local application state. Services hold domain rules and integrations. Authorization is layered:

1. account lifecycle (`active`, verified, password changed);
2. registered permission;
3. module entry permission;
4. WorkspaceAccessService branch/project rights;
5. OrganizationScopeService supported scope;
6. record policy or service-level target authorization.

The main protected CRM group uses `auth`, `active`, `verified`, `password.changed`, and `sales.access`, with explicit exceptions for guest, invitation, password, profile, email verification, and logout flows.

No realtime server is required. Presence, notifications, comments, mentions, conflict handling, and activity logs use database-backed/polling workflows.

## 2. Data Source Boundaries

### Local operational data

Users, roles, permissions, invitations, branches, projects, assignments, leads, ContentItem Work Planner/Sales Agenda records, expenses, Dana Talangan local records, comments, notifications, activity logs, collaboration state, and Agenda Evidence metadata/files are local database/application records.

### Snapshot/cache data

`DatabaseSheetRecord` is a replaceable branch cache populated by `DatabaseSheetSyncService`. `DatabaseSheetSyncStatus` tracks that operation. Database views can use remote Google data and `DatabaseSheetWriteService` handles supported immediate writes.

`KonsumenProgressSheetRow` is a replaceable row snapshot. `KonsumenProgressSyncService` owns its sync and `KonsumenProgressSyncStatus` tracks it. Row IDs and `row_hash` values are not durable identities for comments or external references.

### Remaining Google-backed paths

`GoogleSheetsApiService` is still used by Database, Konsumen Progress, Dana Talangan, and optional Sales lifecycle flows. `SalesLeadSpreadsheetContract`, `SalesLeadSpreadsheetWriter`, and lifecycle sync services provide explicit branch workbook contracts where enabled. Google is not a universal source of truth: inspect each module before changing ownership or consistency assumptions.

Recommended direction, not implemented: migrate remaining Google mirror/snapshot modules to normalized local data with explicit import/sync boundaries. Detailed audit and additive Phase 1 schema foundation: `docs/DATABASE_LOCAL_MIGRATION_PLAN.md` (**PHASE 1 FOUNDATION IMPLEMENTED / NO READ/WRITE CUTOVER**).

## 3. Authorization and Scope

### Core services

- `WorkspaceAccessService`: active accessible branches/projects, branch rights, project assignment windows, requested workspace resolution.
- `OrganizationScopeService`: module-specific own/team/assigned/branch/all intersections and visible user/branch/project IDs.
- `ReportingHierarchyService`: managerial supervisor hierarchy, rank/authority, descendants, and cycle prevention.
- `SalesTeamScopeService`: operational Sales team. `sales_coordinator_sales` rows are filtered by `is_active`, `started_at`, `ended_at`, and valid primary roles.
- `CoordinatorLeadTeamService`: Coordinator role/workspace checks used by Coordinator monitoring.

Critical distinction: `users.supervisor_user_id` is an organizational/reporting relationship. It is not the canonical operational Coordinator → Sales relationship. Coordinator Sales membership must use `sales_coordinator_sales` through `SalesTeamScopeService`. Reusing generic organization team IDs for this purpose can create a monitoring/private-endpoint authorization mismatch.

Primary role permissions come from `users.role_id` and the registered Permission Catalog. Supplemental roles do not grant permissions. `pusat` receives explicit operational mappings, not automatic system administration. Superadmin wildcard behavior applies to registered permission slugs only.

### Current role behavior

- Sales: own Sales workspaces, assigned branches/projects, own Agenda actions and optional evidence upload.
- Sales Coordinator: current operational Sales team monitoring through `sales_coordinator_sales`; evidence is read-only within that scope.
- Supervisor: existing canonical monitoring scope; evidence is read-only.
- Manager/Branch Manager: monitoring/operational access according to explicit permission and workspace scope.
- Admin: legacy operational branch administration and branch monitoring where mapped; evidence is read-only.
- Pusat: explicit cross-branch operational access only.
- Superadmin: registered system and operational permissions; explicit cleanup action is separate from normal impersonation authority.

Always test direct URLs, JSON, exports, sync endpoints, bulk endpoints, and deep links. Navigation is not authorization.

## 4. Buku Saku Sales

`ContentItem` represents task, agenda, and content. Sales Agenda is identified by `item_type=agenda` and `agenda_type=buku_saku_sales`. The local workflows include Leads, Sales Agenda, reports, reminders, lifecycle status/history, consumer/SLIK/freelance/Akad operations, and monitoring.

The Sales lifecycle preserves legacy stage timestamps beside canonical lifecycle records. Pull/reconciliation and branch workbook operations are isolated in their dedicated services. `SALES_LEAD_GOOGLE_SYNC_ENABLED` controls optional Google lead sync behavior; it does not make the local application database disposable.

Current operational team behavior:

- Coordinator monitoring resolves Sales through `SalesTeamScopeService` and current assignment rows.
- Supervisor monitoring remains separate and must not be changed to Coordinator pivot semantics.
- Admin monitoring uses authorized branch scope and does not require a Coordinator mapping.
- Project assignment active/date windows and WorkspaceAccess branch/project rights apply after role/team resolution.

## 5. Agenda Evidence

Evidence rules are centralized in `SalesAgendaEvidenceRules`:

- optional for all Sales Agenda categories;
- maximum two files per Agenda;
- JPEG, PNG, WebP input;
- maximum 10 MiB upload;
- maximum 40 million pixels;
- resize longest side to 1,600 pixels without upscaling;
- output WebP quality 75;
- private `agenda_evidence` disk;
- private `agenda_evidence_archives` disk;
- private response with `Cache-Control: private, no-store` and `nosniff`;
- delete allowed to owner only before Agenda is finished.

`SalesAgendaEvidenceImageService` requires GD functions including `imagewebp`, validates actual image content, handles JPEG orientation, converts to WebP, and writes generated storage paths. No public storage URL is used.

View authorization in `SalesAgendaEvidenceAuthorizationService` is role-aware:

- Sales owner: own Agenda.
- Coordinator: `CoordinatorSalesMonitoringService` canonical branch/project scope plus `SalesTeamScopeService::contains()` current operational membership.
- Supervisor: existing `CommentableAccessService` Sales Agenda visibility path and canonical Supervisor scope.
- Admin: existing authorized branch behavior.
- Superadmin: full view through registered access.

Do not modify `OrganizationScopeService::teamIds()` globally to solve a Coordinator issue. It is shared by Work Planner, Database, Consumer Progress, Expenses, Dana Talangan, and other modules.

## 6. Archive and Retention

`agenda-evidence:archive-weekly {--week=}` processes every branch, defaulting to the previous week. The scheduler runs Sunday at 02:00 with overlap protection. `SalesAgendaEvidenceArchiveService`:

1. groups evidence by branch/week;
2. writes a ZIP to the private archive disk;
3. includes `manifest.json` with Agenda, owner, project, file, and checksum metadata;
4. verifies ZIP entry count, manifest, sizes, and SHA-256 values;
5. stores archive checksum and verification timestamp;
6. marks evidence as archived.

The archive disk remains on the same OASIS server in Phase 1. It is operational retention, not disaster recovery backup. Purge is manual, Superadmin-only, requires at least 60 days, verifies the archive again, deletes matching local files only when checksums match, and preserves database metadata with `purged_at` and null storage paths.

An Agenda with archived or purged evidence cannot be removed through Superadmin cleanup because its ZIP and audit metadata must remain intact. Evidence stream requests return controlled `410` after purge, not a public file.

Required production capabilities: GD with WebP support and ZipArchive. Validate them on Hostinger before enabling the workflow.

## 7. Superadmin Cleanup and Impersonation

`ImpersonationService` preserves original and target IDs plus start time in server session state, logs start/stop events, and logs the original Superadmin as causer. Normal request authorization follows the authenticated target. `RejectImpersonatedRequest`/`not.impersonating` blocks sensitive writes while impersonating.

The cleanup exception is deliberately narrow. The Sales Agenda detail action is explicit (`Hapus sebagai Superadmin`) and requires:

- valid trusted impersonation state when impersonating;
- current authenticated user matching trusted target;
- original actor loaded from server-side session state;
- original actor active, verified, password-complete, primary Superadmin;
- validated reason and confirmation;
- no archived/purged evidence;
- ActivityLog event `sales_agenda_cleaned_by_superadmin` with original actor as `causer_id`.

It deletes only the selected canonical Agenda and associated local evidence files. It does not alter policies, permissions, target authority, or generic impersonation behavior. A normal Superadmin may use the explicit cleanup action without impersonation as well.

Stop route: `POST /impersonation/stop`, named `impersonation.stop`. The UI exposes the stop control. Invalid original/target session state fails closed and invalidates the impersonated session.

## 8. Module and Global Maintenance

`config/oasis_modules.php` is the registry. Current keys: `database`, `sales_pocketbook`, `promo`, `consumer_progress`, `dana_talangan`, `work_planner`, `feedback_reports`, `users`, `branches`, `projects`, and `kavling`.

`EnforceModuleMaintenance`:

- resolves status through `ModuleMaintenanceService`;
- bypasses only when service authorization allows;
- permits route context for authorized bypass users;
- returns branded HTML 503 for normal browser requests;
- returns module-aware JSON 503 for `expectsJson()` requests;
- includes `Retry-After` only when a configured future estimated end exists.

`EnforceOperationalMaintenance` is separate and higher priority. It returns branded global HTML/JSON 503 responses and checks `OperationalMaintenanceService` bypass permissions. Module navigation may remain visible with a maintenance marker. Management writes are blocked while impersonating.

Do not infer maintenance state from navigation. Do not add module keys without route patterns, authorization, controller, view, and tests.

## 9. Google Test Isolation

The production `GoogleSheetsApiService` constructor intentionally fails when `GOOGLE_SHEETS_CREDENTIALS_PATH` does not exist. Local/test verification intentionally keeps `storage/app/google/service-account.json` absent.

Tests bind a `GoogleSheetsApiService` mock or use `tests/TestCase.php::fakeGoogleSheets()` before resolving dependent services. When adding a controller test, mock at the external service boundary before container resolution. Do not generate fake credentials, commit credentials, or weaken the constructor to hide a production configuration error.

## 10. Deployment Notes

Hostinger deployment uses PHP 8.3+, MySQL, writable `storage`/`bootstrap/cache`, and a one-minute cron invocation of `artisan schedule:run`. See `HOSTINGER_DEPLOYMENT.md` and `docs/PRODUCTION_ACCEPTANCE.md` together; the former contains shared-hosting/VPS layout details and the latter contains release acceptance requirements.

Safe release outline:

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

Build frontend assets with `npm ci && npm run build` where Node is available. If assets are built elsewhere, deploy generated `public/build` artifacts through the established Hostinger layout. Configure production `.env` separately. Never put `.env`, Google service-account JSON, API keys, mail passwords, or Hostinger credentials in Git.

Before release, verify backup/restore readiness, migration order, scheduler registration and actual execution, writable paths, `APP_DEBUG=false`, HTTPS, asset availability, and Agenda Evidence GD/ZipArchive support.

## 11. Verified Operational Gotchas

- A stale/corrupt local SQLite DB can produce `database disk image is malformed`, false-looking null relations, and misleading scope failures. Recreate only local DB files.
- Config/route/view cache can preserve old environment or authorization behavior. Use `php artisan optimize:clear` locally.
- Google constructors fail without credentials unless tests bind mocks first.
- A Coordinator mapping must be current and valid; historical/inactive `sales_coordinator_sales` rows do not grant access.
- Branch membership, project assignment, and assignment date windows matter. `users.branch_id` alone is not sufficient proof of scope.
- `PSJB` and other Konsumen Progress sheet tab names can be case-sensitive.
- Database and Konsumen Progress sync are separate systems; do not merge their status tables or controls.
- Cache row IDs in Konsumen Progress are unstable across replacement sync.
- Native Vite/Rolldown Windows file locks can cause npm `EPERM`; stop processes and reinstall dependencies instead of forcing upgrades.
- `vendor/bin/pint --test` may report legacy repository style issues; inspect changed files separately and do not reformat unrelated code.

## 12. GitHub CI Baseline

`.github/workflows/ci.yml` is the first CI workflow. It runs on pushes to `main`, pull requests targeting `main`, and manual `workflow_dispatch`. It has two read-only jobs with concurrency cancellation for superseded runs:

- **PHP / Laravel** on Ubuntu with PHP 8.3, Composer lockfile install, ctype/fileinfo/GD/mbstring/PDO SQLite/SQLite3/XML/Zip extensions, generated local `APP_KEY`, config clear, Google credential absence assertion, Pint, `composer test`, and `composer audit`.
- **Frontend** on Ubuntu with Node.js 22 LTS line, `npm ci`, `npm run build`, and `npm audit`.

PHPUnit continues to use its configured SQLite `:memory:` database. CI creates no `database/database.sqlite`, Google service-account JSON, production database, production `APP_KEY`, or custom secret. Google isolation remains in tests and `tests/TestCase.php`.

CI does not deploy, publish artifacts, run browser/E2E coverage, upload coverage, run dependency update automation, or mutate lockfiles. Those are future improvements, not current automation.

Local equivalent gates:

```bash
vendor/bin/pint --test
composer test
composer audit
npm ci
npm run build
npm audit
```

## 13. Current Technical Debt

Verified debt and boundaries:

- Database remains a Google spreadsheet cache/sync and write integration rather than a fully normalized local domain.
- Konsumen Progress remains a replaceable Google sheet snapshot with separate sync/status infrastructure.
- Dana Talangan retains Google integration and a local cache boundary.
- Deployment is documented for Hostinger but release execution remains an operational workflow; GitHub CI validates code but does not deploy.
- Some legacy UI views and dialogs retain older styling/focus patterns while newer CRM primitives exist. Improve only in scoped UI work.
- OpenSpout is installed but unused; PhpSpreadsheet is the current XLSX implementation. Removing or switching it requires dependency audit and migration scope.

Do not classify local-first Sales Agenda, evidence, users, comments, or notification behavior as Google debt.

## 13. Recommended Roadmap — Not Implemented

1. Extend GitHub CI with browser/E2E coverage, coverage reporting, dependency update automation, and scheduled security scans.
2. Define and execute Database local normalization with explicit migration/backfill and remote compatibility period.
3. Define normalized Proses Konsumen workflow after reconciling current Konsumen Progress tabs, ownership, and reporting requirements.

Roadmap items are recommendations only. They are not present architecture.

## 14. Handoff Validation Snapshot

At this documentation baseline:

- `php artisan test`: 843 passed, 7,296 assertions, 0 failures; 9 deprecated tests.
- `npm run build`: passed.
- `npm audit`: 0 vulnerabilities.
- `composer audit`: no advisories.
- Google credential file: intentionally absent in local verification.

These are snapshots, not permanent promises. Rerun commands after checkout, dependency, migration, or configuration changes.

## 15. Source Files Audited

Primary audit sources for this handoff included:

- `composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `.env.example`, `phpunit.xml`.
- `config/database.php`, `config/filesystems.php`, `config/services.php`, `config/oasis_modules.php`.
- `routes/web.php`, `routes/auth.php`, `routes/console.php`, `bootstrap/app.php`.
- `app/Http/Middleware/EnforceModuleMaintenance.php`, `EnforceOperationalMaintenance.php`, `RejectImpersonatedRequest.php`.
- `WorkspaceAccessService`, `OrganizationScopeService`, `ReportingHierarchyService`, `SalesTeamScopeService`, `CoordinatorSalesMonitoringService`, `CommentableAccessService`.
- Sales Agenda Evidence authorization, image, archive, cleanup, controller, model, and rules classes.
- Google Sheets, Database, Konsumen Progress, Dana Talangan, and Sales lifecycle services.
- role/permission seeders and migrations, relevant Feature tests, `HOSTINGER_DEPLOYMENT.md`, and `docs/PRODUCTION_ACCEPTANCE.md`.

When a future change conflicts with this handoff, inspect executable source and update documentation in the same scoped change.

## 16. Changelog Convention

User-visible behavior, authorization, data, workflow, configuration, integration, or error handling changes require one concise Indonesian Oasis Changelog entry in an idempotent migration using `DB::table('changelogs')->updateOrInsert(...)`. Pure documentation, tests, formatting, and behavior-neutral refactors do not require an application Changelog row.

## 17. No Secrets

This handoff intentionally contains placeholders, command names, environment variable names, route names, and storage paths only. It contains no APP_KEY, database password, Google private key, API token, mail password, or Hostinger credential.

Before committing any documentation, search changed files for secret-like values and review the staged diff manually.
