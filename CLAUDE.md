# OASIS CRM — Working Rules

This is a concise newcomer guide. `AGENTS.md` is the canonical, detailed instruction file; `README.md` documents the current handoff baseline.

## Project shape

- Laravel 13 / PHP 8.3, server-rendered Blade, Alpine.js, Tailwind CSS, and Vite.
- Routes: `routes/web.php`, `routes/auth.php`, and scheduled commands in `routes/console.php`.
- Backend: `app/Http/Controllers`, `app/Http/Requests`, `app/Models`, `app/Policies`, `app/Services`, `app/Enums`, and `app/ValueObjects`.
- UI: CRM pages under `resources/views/crm`, shared anonymous components under `resources/views/components/crm`, and Alpine modules under `resources/js` registered from `resources/js/app.js`.
- Persistence: migrations, factories, and seeders under `database/`; tests are split into `tests/Feature` and `tests/Unit`.
- Production is MySQL; local development and PHPUnit use SQLite. PHPUnit uses an in-memory database, array cache/session, sync queue, and array mail.

## Commands

```bash
composer setup                 # install, configure local app, migrate, build
composer dev                   # Laravel server, queue listener, logs, and Vite
composer test                  # clear config, then run the full suite
php artisan test path/to/Test.php
php artisan test --filter=TestName
vendor/bin/pint --dirty --test # check changed PHP files
git diff --check
npm run dev
npm run build
php artisan route:list
```

After route, controller, middleware, provider, or Blade changes, run `php artisan optimize:clear` and usually `php artisan view:cache`. Build assets when JS, CSS, or Tailwind-scanned markup changes. There is no npm lint, type-check, or test script.

## Implementation patterns

- Trace work as **route → middleware → controller → policy/service → scoped query** before editing. Keep controllers thin; put business workflows and integrations in services.
- Inspect existing routes, models/relationships, policies, FormRequests, `WorkspaceAccessService`, `OrganizationScopeService`, migrations, views, JS, and focused tests before inventing anything.
- Use named Laravel routes (`route(...)`), FormRequests for validation, policies/middleware for authorization, migrations for schema, and existing shared components/utilities.
- Use `WorkspaceAccessService` for branch/project access and `OrganizationScopeService` for own/team/assigned/branch/all visibility. Do not duplicate scope logic in controllers or Blade.
- `users.role_id` is the permission source. Supplemental `role_user` roles support legacy role checks but do not grant permissions; `pusat` is not `superadmin`.
- Backend authorization is mandatory for direct URLs, exports, AJAX, sync, bulk actions, and deep links. Hidden navigation is never authorization.
- Preserve Indonesian domain labels and OASIS terminology. Do not replace domain concepts with generic SaaS/accounting language.

## Data and integration traps

- Database sync and Konsumen Progress sync are separate systems with separate caches/status tables; do not merge them.
- Google credentials belong in environment/storage configuration and must never be committed or logged. Mock Google-facing services before resolving dependent services in tests.
- Treat sheet row numbers, names, phone numbers, NIK, and generated business IDs as non-durable identities unless the module contract explicitly says otherwise.
- Money is stored as decimal data, not floats. Expenses and other audited operational records are normally cancelled/soft-deleted rather than permanently deleted.
- Use the existing PhpSpreadsheet implementation for XLSX; do not add another spreadsheet architecture.
- Changelog entries for behavior changes are deployed through idempotent migrations. Pure documentation, tests, formatting, and behavior-neutral refactors do not need entries.
- Do not broaden the PWA service worker to cache authenticated pages or CRM data.

## UI conventions

- Prefer existing `x-crm.*` components and shared CSS tokens in `resources/css/app.css`; do not create a second modal, toast, picker, notification, table, or frontend architecture.
- Use Alpine modules for browser behavior and register them in `resources/js/app.js`; use existing date/month/time picker modules rather than adding a library.
- Keep the OASIS visual identity: compact operational density, square/minimally rounded geometry, yellow/black structure, restrained module accents, and clear semantic status labels.
- New forms need visible labels, inline errors, keyboard-accessible controls, focus states, Escape-close dialogs, and usable mobile layouts. Tables may scroll horizontally on mobile.
- Destructive actions are explicitly labelled, confirmed, and semantically red; module accents are not danger colors.

## Tests and safety

- Add focused Feature tests for authorization, scope, direct routes, exports, sync, mutations, and important failure paths. Use Unit tests for isolated services/parsers where appropriate.
- For new permissions or scoped data, test allowed and denied roles/scopes, supplemental-role non-escalation, direct endpoints, and inaccessible branch/project requests.
- Preserve unrelated working-tree changes. Never commit `.env`, credentials, service-account JSON, or secrets. Do not introduce Redis, Reverb, WebSockets, a new frontend framework, or duplicate infrastructure without explicit scope.
- Before claiming completion, run the narrowest relevant tests, formatting checks for changed PHP, `git diff --check`, and the frontend build when frontend sources changed.
