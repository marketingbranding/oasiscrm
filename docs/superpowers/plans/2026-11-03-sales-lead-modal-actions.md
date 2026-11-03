# Mobile Sales Lead Modal Actions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Keep the mobile Tambah lead sheet closable and keep both existing save actions visible while fields scroll.

**Architecture:** Preserve the native `<details>` entry panel and existing form submission. Add a mobile-only header close button that removes the parent details `open` state, and make the existing form action row a sticky footer inside the scrollable form.

**Tech Stack:** Laravel Blade, CSS in `resources/css/app.css`, Alpine.js inline interaction, PHPUnit feature tests, Vite build.

**Spec:** `docs/superpowers/specs/2026-11-03-sales-lead-modal-actions-design.md`

## Global Constraints

- No route, controller, authorization, validation, or dependency changes.
- Preserve the existing submit labels, `submit_action` values, form action, and Alpine submitting state.
- Preserve desktop behavior; close/sticky presentation applies only to the mobile entry sheet.
- Keep keyboard access, visible focus, safe-area padding, and no horizontal overflow.

## Review Focus

- Closing the sheet must not submit the form or lose the existing native details toggle behavior — test the button type and close contract.
- Both submit actions must remain in the form and retain their values — test `save` and `add_another`.
- Long form content and validation messages must not hide actions — test sticky footer CSS and browser-rendered visibility.
- The close control must have an accessible name and focus-visible styling — test its markup and inspect the accessibility tree.
- Desktop presentation must remain unchanged — retain desktop media-query behavior and verify at desktop width.

---

### Task 1: Add regression contracts for modal actions

**Files:**
- Modify: `tests/Feature/SalesPocketbookMobileContractTest.php`

**Interfaces:**
- Consumes: Existing `sales-agendas.index` rendered lead workspace.
- Produces: Failing contracts for the mobile close control, both submit values, and sticky action styling.

- [ ] **Step 1: Write the failing test**

Extend `test_mobile_lead_entry_matches_reference_form_contract` to assert:

- `sales-lead-entry-close` exists with `type="button"` and `aria-label="Tutup form tambah lead"`.
- The form still contains `name="submit_action" value="save"` and `name="submit_action" value="add_another"`.

Add a CSS contract assertion that `.sales-lead-form-actions` uses `position: sticky` and includes safe-area bottom padding, and that the close control has a mobile display rule.

- [ ] **Step 2: Run the focused test to verify it fails**

Run: `php artisan test tests/Feature/SalesPocketbookMobileContractTest.php --filter=test_mobile_lead_entry_matches_reference_form_contract --compact`

Expected: FAIL because the close control and sticky footer rules are not yet present.

- [ ] **Step 3: Keep the RED test uncommitted and proceed to Task 2**

Do not commit a failing state. The test remains the regression contract while the implementation is added; commit the passing test and implementation together in Task 2.

### Task 2: Implement the mobile close control and sticky action footer

**Files:**
- Modify: `resources/views/crm/sales-pocketbook/partials/lead-saya.blade.php`
- Modify: `resources/css/app.css`

**Interfaces:**
- Consumes: Task 1’s source contracts; existing `<details>` panel and form action row.
- Produces: Mobile header close control and persistently reachable save actions without changing submitted data.

- [ ] **Step 1: Add the close control in the Blade heading**

Add a `type="button"` button with class `sales-lead-entry-close`, accessible label `Tutup form tambah lead`, and the existing inline SVG icon convention. Its Alpine click handler must set the closest `details` element’s `open` property to `false` and restore focus to that details element’s summary. Do not alter the form action or submit buttons.

- [ ] **Step 2: Add mobile-only layout rules**

Keep the close control hidden outside the mobile sheet, then display it as a minimum 44px icon button inside the mobile heading with a visible focus outline. Keep the heading title/status grouping intact.

Make `.sales-lead-form-actions` sticky at the bottom of the form scroll area, add a top border/background for separation, preserve the two-column button grid, and include `env(safe-area-inset-bottom)` in bottom padding. Keep desktop rules unchanged.

- [ ] **Step 3: Run focused tests to verify they pass**

Run: `php artisan test tests/Feature/SalesPocketbookMobileContractTest.php --filter='mobile_lead|phone' --compact`

Expected: PASS.

- [ ] **Step 4: Commit the implementation**

```bash
git add tests/Feature/SalesPocketbookMobileContractTest.php resources/views/crm/sales-pocketbook/partials/lead-saya.blade.php resources/css/app.css
git commit -m "fix: keep mobile sales lead actions reachable"
```

### Task 3: Build and verify the rendered workflow

**Files:**
- Modify: `public/build/manifest.json` and generated `public/build/assets/*` if the repository build workflow tracks them.
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_add_sales_lead_modal_actions_changelog.php`
- Test: `tests/Feature/SalesPocketbookTest.php`

**Interfaces:**
- Consumes: Task 2’s Blade/CSS implementation.
- Produces: Verified mobile and desktop rendering with no regressions.

- [ ] **Step 1: Run formatting, build, and cache checks**

Run:

```bash
vendor/bin/pint --dirty --format agent
npm run build
php artisan optimize:clear --no-interaction
php artisan view:cache --no-interaction
git diff --check
```

- [ ] **Step 2: Add and verify the user-facing changelog**

Create an idempotent migration using `DB::table('changelogs')->updateOrInsert(...)`
with version `null`, Indonesian title/description, category `changed`, and a
`down()` that removes only the exact entry. Add a feature assertion that running
the migration twice leaves exactly one matching row and that the Changelog page
renders the entry. Run `php artisan migrate --no-interaction` and verify the
matching database row.

- [ ] **Step 3: Verify in the authenticated browser tab**

At approximately 425×812, open Tambah lead and confirm the close button is visible, keyboard-focusable, and closes the sheet without submitting; both save actions remain visible at the bottom; scrolling fields does not move the action footer out of view; and there is no horizontal overflow or console error.

At approximately 1440×900, confirm the desktop entry panel and form remain unchanged.

- [ ] **Step 4: Run focused and full verification**

Run:

```bash
php artisan test tests/Feature/SalesPocketbookMobileContractTest.php tests/Feature/SalesPocketbookTest.php --compact
php artisan test --compact
```

Expected: all tests pass with no failures.

- [ ] **Step 5: Review the final diff**

Run `git diff --stat`, `git diff --check`, and `git status --short`; confirm only the planned Blade, CSS, tests, and generated build files changed.
