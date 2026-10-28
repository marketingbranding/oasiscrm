# Buku Saku Sales Full Workflow Redesign Plan

> **Implementation:** execute inline with `superpowers:executing-plans` and keep existing uncommitted work intact.

**Goal:** Recompose the six Buku Saku Sales screens shown in `C:\Users\Administrator\Downloads\Redesign · Buku Saku Sales.pdf` into one coherent operational workspace while preserving every existing route, form field, permission check, Alpine hook, business rule, and Indonesian domain label.

**Architecture:** Native incremental redesign. Keep Blade, Alpine, Tailwind v4, the current CRM layout, existing page components, and the current controller/service contracts. Add a Buku Saku-specific visual token/composition layer in `resources/css/app.css`, then apply it to the existing personal workspace, agenda detail, lead list/create, lead detail, and monitoring views. No new frontend framework, route, API, or data model.

**Reference:** `C:\Users\Administrator\Downloads\Redesign · Buku Saku Sales.pdf`.

## Global constraints

- Preserve route names, HTTP verbs, request names, hidden fields, validation, authorization, query parameters, existing copy, Alpine data/actions, and progressive-enhancement behavior.
- Do not revive Daily Planning or add workflow actions that are not already present.
- Keep OASIS yellow, black structural accents, square/minimally rounded geometry, compact workstation density, and accessible mobile behavior.
- Use existing components and tokens; no dependency changes, icon library, or second styling architecture.
- New/changed CSS colors and fonts must use declared tokens; no `transition: all`; keep `overflow-x: clip`, focus-visible states, and reduced-motion fallbacks.
- Do not overwrite or reset unrelated existing worktree changes.
- UI behavior changes require focused source-contract/feature coverage; pure visual changes may use existing render contracts.
- User-visible behavior changes require one idempotent null-version Oasis Changelog migration.

## Screen map

1. Personal workspace: Agenda / Lead Saya tabs, scope strip, quick create, agenda paper/list.
2. Agenda creation: existing inline form, visually promoted as a clear paper/workbench composer.
3. Agenda detail: metadata, checklist, photo evidence, activity result, completion action.
4. Lead list: personal lead tabs/list and monitoring lead list with responsive record rows.
5. Add lead: existing quick lead form and personal compact lead form, grouped by required/optional information.
6. Lead detail: lead identity, status history, completion action, site-visit result and dialogs.

## Task 1: Establish the shared Buku Saku visual language

**Files:** `resources/css/app.css`, optionally existing CRM component usage only where a matching component already exists.

- Add a clearly scoped Buku Saku token block for paper, ink, muted surface, rules, selected yellow, semantic status, and responsive shell values.
- Add shared composition utilities/classes for page frame, context strip, tabs, record panels, compact metadata, section headers, action rows, and mobile-safe controls.
- Preserve existing class names and append/override only where needed so current tests and JavaScript selectors remain valid.
- Add immediate focus-visible styles and reduced-motion rules for new transitions.

**Expected:** the six views can share the same visual grammar without changing their contracts.

## Task 2: Recompose the personal workspace and create flows

**Files:** `resources/views/crm/sales-pocketbook/sales-agenda.blade.php`, `resources/views/crm/sales-pocketbook/index.blade.php`, `resources/views/crm/sales-pocketbook/coordinator-leads.blade.php`.

- Keep all existing form actions, fields, hidden values, validation output, and Alpine bindings.
- Reframe the personal view as a two-tab workspace with a clear scope strip, compact date context, dominant `Tambah Agenda`/lead action, and responsive agenda/lead records.
- Recompose agenda creation into a visual composer without removing optional fields or evidence support.
- Recompose the monitoring view into the same shell language: filters remain functional, monitoring/report tabs remain URL-backed, and tables remain available for wide-screen users.
- Recompose lead creation into explicit required consumer data, source/process data, and notes/action groups while retaining every field and endpoint.
- Keep mobile lists and desktop tables as deliberate alternatives, not duplicated business behavior.

**Expected:** personal, coordinator, and monitoring pages match the PDF hierarchy while current route/form tests continue to pass.

## Task 3: Recompose agenda detail and lead detail

**Files:** `resources/views/crm/sales-pocketbook/sales-agenda-detail.blade.php`, `resources/views/crm/sales-pocketbook/lead-detail.blade.php`.

- Keep breadcrumb destinations and all existing update/upload/delete/complete actions.
- Make the record identity and status the first visual block, followed by a structured metadata rail.
- Style checklist rows, evidence/photo slots, result entry, site-visit history, and completion actions as one operational record flow.
- Preserve modal names, modal form routes, optimistic lock tokens, permissions, and progressive enhancement.
- Ensure long names, notes, file names, and status labels wrap safely at 360–430px.

**Expected:** detail screens read like the PDF's paper record/workbench and retain every evidence/status behavior.

## Task 4: Add focused visual/source contracts and changelog

**Files:** `tests/Feature/SalesPocketbookMobileContractTest.php` or a focused new feature test, and a new migration under `database/migrations/`.

- Add assertions for the shared composition markers on personal workspace, agenda detail, lead list/create, and lead detail.
- Assert critical existing endpoints/fields remain rendered in the redesigned forms.
- Add an idempotent Indonesian changelog entry using `updateOrInsert`, with `version` null and an exact stable title.
- Verify the changelog appears once in the Changelog page.

**Expected:** tests prove visual contracts without asserting incidental class ordering, and the migration is idempotent.

## Task 5: Verify and review

- Run the focused Buku Saku tests first and watch new contract tests fail before implementation.
- Run `php artisan optimize:clear`, `php artisan view:cache`, and `npm run build`.
- Run `vendor/bin/pint --dirty --format agent` for changed PHP, `git diff --check`, and focused tests again.
- Verify via Playwright at approximately 390×844, 430×932, 1024×900, and 1366×900: no horizontal overflow, mobile actions reachable, tabs/filters/forms work, dialogs close with Escape, and no browser console errors.
- Run Hallmark `sloplint.mjs` against the changed CSS/Blade with render where available; fix new failures and document legacy findings outside the page scope.
- Run the full test suite if focused verification is green.
