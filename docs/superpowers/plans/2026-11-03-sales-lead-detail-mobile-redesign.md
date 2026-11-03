# Sales Lead Detail Mobile Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the mobile Sales lead detail screen to match the supplied 390×844 reference while preserving current lifecycle semantics, permissions, and desktop behavior.

**Architecture:** Keep `sales-leads.show` and `SalesLeadController::show` as the data/authorization entry point. Update the existing Blade detail view and its `sales-mobile-detail-page` CSS contract for mobile presentation, using the existing manual-status and UTJ endpoints for “Update hasil”. Keep the current desktop presentation and add a Changelog migration for the user-visible redesign.

**Tech Stack:** Laravel 13, Blade, Tailwind CSS v4, shared `resources/css/app.css`, PHPUnit feature tests, Vite, Playwright.

**Spec:** `docs/superpowers/specs/2026-11-03-sales-lead-detail-mobile-redesign-design.md`

## Global Constraints

- No new route, permission, database field, dependency, or lifecycle status.
- No free-form follow-up note workflow; render only persisted status and site-visit data.
- Preserve existing lead completion, UTJ, Cek Lokasi, and manual-status persistence behavior.
- Preserve desktop presentation and existing role/scope authorization.
- The visual target is 390×844 CSS pixels; no horizontal overflow at supported mobile widths.
- Any user-visible UI change requires an idempotent Oasis Changelog migration and test.

## Review Focus

- Unexpected lifecycle values (`discussion`, `utj`, `slik_rejected`, `akad`, `freelance`) must map to the display step without altering canonical status.
- Leads with no history or visit must show an honest empty state, never fabricated notes or counts.
- Completed leads and users without update/complete permission must not receive enabled unauthorized actions.
- Long customer/project names, notes, and history must not create mobile horizontal overflow or hide behind the fixed action bar.
- Historical or unauthorized viewers must retain the current `viewSiteVisit` access boundary.

---

## File Structure

- Modify `resources/views/crm/sales-pocketbook/lead-detail.blade.php`: mobile information hierarchy, five-step display mapping, merged real history, policy-gated actions, and existing status/UTJ confirmation forms.
- Modify `resources/css/app.css`: mobile lead-detail-only layout, progress strip, information/history panels, and safe-area sticky action bar; keep desktop and agenda selectors unchanged.
- Modify `tests/Feature/SalesLeadSiteVisitResultsTest.php`: render the detail screen using its existing fixtures and assert status/history/action contracts and authorization behavior.
- Modify `tests/Feature/SalesPocketbookMobileContractTest.php`: assert the source/CSS hooks and responsive style contract.
- Create `database/migrations/<timestamp>_add_sales_lead_detail_mobile_redesign_changelog.php`: idempotent system Changelog entry.
- Modify `tests/Feature/SalesPocketbookTest.php`: verify Changelog idempotency, rendering, and rollback.
- Vite build updates the tracked manifest and hashed CSS asset according to repository convention.

### Task 1: Pin the rendered mobile detail contract

**Files:**
- Modify: `tests/Feature/SalesLeadSiteVisitResultsTest.php`
- Modify: `tests/Feature/SalesPocketbookMobileContractTest.php`

**Interfaces:**
- Consumes: existing `context()`, `history()`, and `incompleteVisit()` test helpers in `SalesLeadSiteVisitResultsTest`.
- Produces: failing feature assertions that Task 2 must satisfy; no production interface is added.

- [ ] **Step 1: Add rendered view assertions**

Add a focused test that requests `route('sales-leads.show', $lead)` and asserts the mobile sections for the back link, lead context, five-step status summary, Informasi, and Riwayat hasil. Assert phone/source/date are present and the legacy generic tab labels are not the mobile hierarchy.

- [ ] **Step 2: Add mapping/history/action assertions**

Render fixtures for `NoResponse`, `Discussion`, `FaceToFace`, `SiteVisit`, `Utj`, `SlikCheck`, `SlikRejected`, and `Akad`; assert the matching five-step progress index while the actual status label remains visible. Add two dated status histories and one site visit; assert newest-first ordering, actual visit details only, and an honest empty-history state when records are absent. Assert actions are conditional on current policies and completion state.

- [ ] **Step 3: Add source/CSS contract assertions**

Extend the mobile contract test to require lead-detail-specific selectors for the progress strip, info/history panels, and sticky action bar; ensure the mobile detail CSS does not broaden to `.sales-agenda-detail` or other pages.

- [ ] **Step 4: Run the focused tests and verify RED**

Run:

```bash
php artisan test tests/Feature/SalesLeadSiteVisitResultsTest.php tests/Feature/SalesPocketbookMobileContractTest.php --filter='lead_detail' --compact
```

Expected: failures identify missing mobile hierarchy and display contract.

### Task 2: Implement the mobile detail composition and status presentation

**Files:**
- Modify: `resources/views/crm/sales-pocketbook/lead-detail.blade.php`
- Modify: `resources/css/app.css`

**Interfaces:**
- Consumes: the assertions introduced in Task 1 and the existing view variables `lead`, `latestVisit`, `canRecord`, and `canComplete`.
- Produces: mobile presentation in the existing `sales-leads.show` view; no new route or backend state.

- [ ] **Step 1: Calculate the display-only progress step**

In the existing view, derive a local 1–5 display index: `NoResponse` → 1; `Discussion` or `FaceToFace` → 2; `SiteVisit` or `Utj` → 3; `SlikCheck` or `SlikRejected` → 4; `Akad` → 5; if `completed_at` is set, show the completed final state. Keep the real `current_status->label()` visible. Treat `Freelance` as independent and do not use it to regress/change primary progress.

- [ ] **Step 2: Build the mobile sections**

Render mobile breadcrumb/header, current status badge, five labeled progress segments, compact lead information, and a newest-first “Riwayat hasil” composed only of persisted status histories and site-visit outcomes. Maintain existing desktop content and its route links. Do not fabricate notes/counts; show an explicit empty state if there is no history.

- [ ] **Step 3: Wire “Update hasil” to existing lifecycle behavior**

For users authorized by `updateLifecycleStatus`, keep the button connected to confirmation forms posting each allowed `SalesLeadStatus::MANUAL` value to `sales-leads.lifecycle-status.update`. Keep the existing UTJ confirmation flow on `sales-leads.utj.store` only for users authorized by `markUtjDirect`, and only when the status is manual. Retain automatic timestamps, operation UUID behavior where already required, and backend services unchanged. For non-manual/system statuses, do not render a misleading manual status editor.

- [ ] **Step 4: Build the fixed action bar**

Place “Update hasil” and the existing `sales-leads.complete` action in a fixed mobile bottom bar. Render each only when its corresponding policy permits it; completed leads show their completion state, not an active completion submit. Add bottom content clearance and `env(safe-area-inset-bottom)` padding.

- [ ] **Step 5: Apply mobile-only visual styling**

Use existing OASIS tokens/colors: cream page surface, white bordered information/history panels, black structure, yellow active step, square geometry, compact Archivo/system type. Scope all new rules under `.sales-mobile-detail-page` plus lead-detail-specific classes. Preserve desktop rules and agenda detail styles.

- [ ] **Step 6: Run focused tests and verify GREEN**

```bash
php artisan test tests/Feature/SalesLeadSiteVisitResultsTest.php tests/Feature/SalesPocketbookMobileContractTest.php --compact
```

Expected: all focused lead-detail, responsive contract, lifecycle permission, and site-visit tests pass.

### Task 3: Add the required Oasis Changelog entry

**Files:**
- Create: `database/migrations/<timestamp>_add_sales_lead_detail_mobile_redesign_changelog.php`
- Modify: `tests/Feature/SalesPocketbookTest.php`

**Interfaces:**
- Consumes: existing Changelog schema and migration conventions.
- Produces: one system release entry with `version = null`, category `changed`, and a stable Indonesian title; `down()` deletes only this exact identity.

- [ ] **Step 1: Add the idempotency/visibility/reversibility test**

Require the migration, call `up()` twice, assert exactly one matching `version = null`/`title`/`changed` row, render it on `changelogs.index` for a Super Admin, call `down()`, and assert the exact row is gone.

- [ ] **Step 2: Run the test and verify RED**

```bash
php artisan test tests/Feature/SalesPocketbookTest.php --filter=mobile_lead_detail_redesign_changelog --compact
```

- [ ] **Step 3: Generate and implement the migration**

Use `php artisan make:migration add_sales_lead_detail_mobile_redesign_changelog --no-interaction`. Follow neighboring migration pattern: `DB::table('changelogs')->updateOrInsert(['version' => null, 'title' => self::TITLE], [...])`; set `created_by` null; exact `down()` filter by null version and title.

- [ ] **Step 4: Run the test and verify GREEN**

```bash
php artisan test tests/Feature/SalesPocketbookTest.php --filter=mobile_lead_detail_redesign_changelog --compact
```

### Task 4: Final quality and browser verification

**Files:**
- Verify all files touched by Tasks 1–3; no additional production behavior changes.

- [ ] **Step 1: Clear and cache Blade views**

```bash
php artisan optimize:clear --no-interaction
php artisan view:cache --no-interaction
```

- [ ] **Step 2: Build assets and format changed PHP**

```bash
npm run build
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 3: Verify in browser**

At 390×844 compare the result to the supplied image: hierarchy, widths, spacing, status strip, panels, and bottom bar. Also verify 360px and 430px mobile, tablet at 1024px, desktop at 1366px, scrolling past the fixed action bar, keyboard focus, no horizontal overflow, and no console errors. Exercise at least one allowed status update and verify denied roles do not see/submit those actions.

- [ ] **Step 4: Run final tests and diff check**

```bash
php artisan test tests/Feature/SalesLeadSiteVisitResultsTest.php tests/Feature/SalesPocketbookMobileContractTest.php tests/Feature/SalesPocketbookTest.php --compact
git diff --check
```

Expected: all selected tests pass; diff check reports no whitespace issues.

## Dependency Order

Task 1 defines observable contracts; Task 2 implements those contracts; Task 3 adds release visibility; Task 4 validates the complete result. No parallel execution is recommended because the core implementation is localized to one Blade view and one CSS file, and its tests/changelog depend on those exact UI decisions.
