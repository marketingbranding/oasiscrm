# Sales Mobile Form and Agenda Layout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix mobile overlap in the expanded lead-entry form and prevent agenda Edit/Hapus actions from clipping.

**Architecture:** Adjust the existing mobile CSS overrides in `resources/css/app.css`; retain current Blade structure, routes, and behavior. Add the required idempotent Changelog migration and regression test. Verify layout in a browser where authenticated access is available, then confirm desktop is unchanged.

**Tech Stack:** Laravel Blade, existing CSS, Playwright browser verification.

**Spec:** `docs/superpowers/specs/2026-11-04-sales-mobile-form-and-agenda-actions-design.md`

## Global Constraints

- Keep changes presentation-only and mobile-scoped.
- Do not change routes, authorization, lifecycle behavior, or agenda behavior.
- Do not add dependencies.
- Keep the form action footer visible below its scroll area without covering fields.
- Add one Indonesian user-facing Changelog entry via idempotent migration with `version` null and `created_by` null.

## Review Focus

- Optional lead fields expanded: form content remains scrollable above the sticky buttons.
- Short mobile viewport: footer does not cover the currently focused field.
- Narrow mobile width: Edit and Hapus remain fully visible and usable inside the agenda paper.
- Longer agenda/action text: action controls do not force horizontal overflow.
- Desktop viewport: existing layout remains unchanged.

---

### Task 1: Correct mobile lead form and agenda action layout

**Files:**
- Modify: `resources/css/app.css` (mobile lead entry panel and receipt agenda action overrides near lines 5532–5614)
- Create: `database/migrations/2026_11_04_053610_add_sales_lead_agenda_mobile_layout_changelog.php`
- Modify: `tests/Feature/SalesPocketbookMobileContractTest.php`

**Interfaces:**
- Consumes: Existing `.sales-lead-entry-form`, `.sales-lead-entry-scroll`, `.sales-lead-form-actions`, `.sales-receipt-paper-row-actions`, and `.sales-receipt-row-action` markup/classes.
- Produces: Mobile-only CSS behavior that keeps the form body scrollable above its visible footer and keeps agenda actions within the paper width.

- [ ] **Step 1: Inspect current browser states**

Run the project’s existing development command or reuse its running preview. At mobile width, open the add-lead form and expand optional fields; inspect an agenda receipt with Edit/Hapus actions. Record any console errors and verify current overflow/overlap.

- [ ] **Step 2: Adjust only the relevant mobile CSS overrides**

In `resources/css/app.css`, ensure the lead-entry form's scroll region has the available flex height and the action row stays in normal layout flow after that scroll region so it cannot cover fields. Make the agenda row action container wrap or otherwise fit both controls within its parent at narrow widths, preserving touch targets and danger styling.

- [ ] **Step 3: Add a Changelog migration regression test**

In `SalesPocketbookMobileContractTest`, assert the migration can be applied twice while leaving exactly one `version = null` entry with its Indonesian title, `changed` category, and null `created_by`; assert the Changelog page renders that title. Run the focused test and observe it fail before adding the migration.

- [ ] **Step 4: Add the idempotent Changelog migration**

Create the migration with `php artisan make:migration --no-interaction`. Use `DB::table('changelogs')->updateOrInsert(...)` keyed by `version` null and the exact title; `down()` removes only that exact entry. Re-run the focused test and expect it to pass.

- [ ] **Step 5: Verify responsive states in the browser**

If the development preview permits authenticated access, check approximately 390px and 360px viewport widths, both with optional lead fields collapsed and expanded, and with a populated agenda receipt. Confirm all lead fields can be reached by scrolling, both actions are fully visible, there is no horizontal overflow, and keyboard focus remains visible. At desktop width, confirm the prior layout is unchanged. Check the browser console. If authentication is unavailable, record that visual validation is blocked and do not claim it was completed.

- [ ] **Step 6: Run repository checks**

Run `php artisan optimize:clear`, `php artisan view:cache`, the focused feature test, `vendor/bin/pint --dirty --format agent`, `npm run build`, `git diff --check`, and verify the migration-backed Changelog entry renders. Expected: all commands succeed and exactly one Changelog row exists in the test database.
