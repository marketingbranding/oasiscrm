# Sales Agenda Sticky Completion Viewport Implementation Plan

> **For agentic workers:** Use `superpowers:executing-plans` to implement this plan task by task.

**Goal:** Keep the mobile `Selesaikan agenda` action visible at the viewport bottom while scrolling the Agenda Buku Saku Sales detail page.

**Architecture:** Preserve the existing fixed action markup and completion workflow. On the mobile detail page only, remove the `#crm-main` inner vertical scroll container while retaining horizontal overflow protection at the page level. Let the document viewport own vertical scrolling so the fixed action is anchored consistently.

**Tech Stack:** Laravel 13, PHP 8.3, Blade, existing CRM CSS, PHPUnit, Vite, browser runtime verification.

**Spec:** `docs/superpowers/specs/2026-11-02-sales-agenda-sticky-completion-viewport-design.md`

## Global Constraints

- Do not change routes, validation, authorization, controller behavior, persistence, or completion workflow.
- Keep `.sales-agenda-sticky-action` fixed, centered, full-width, safe-area aware, and horizontally contained.
- Scope the overflow change to `.sales-mobile-detail-page`; desktop and other CRM pages retain current shell behavior.
- Preserve unrelated uncommitted worktree changes.
- Do not add JavaScript scroll handlers, action cloning, or a second completion control.
- Reuse the existing Changelog migration `2026_11_02_170000_add_sales_agenda_mobile_control_visibility_changelog.php`; do not create a duplicate entry.

## Review Focus

- The regression contract must prove the mobile detail page removes only the inner vertical scroll behavior and keeps horizontal overflow protection.
- The existing fixed action contract must remain present and centered.
- The completion form must retain bottom clearance so its final content is not covered by the fixed action.
- Browser verification must check both top and bottom scroll positions at 524×1002 and 390×844, with no horizontal overflow or console errors.

---

### Task 1: Add the failing CSS and rendered-contract regression tests

**Files:**
- Modify: `tests/Feature/SalesAgendaEvidenceTest.php`
- Modify: `tests/Feature/SalesPocketbookMobileContractTest.php`

**Interfaces:**
- Consumes: Existing mobile agenda detail render tests and `resources/css/app.css` source contract.
- Produces: Regression assertions for scoped `#crm-main` overflow, fixed completion action, horizontal containment, and form bottom clearance.

- [ ] **Step 1: Add source-contract assertions for the mobile shell overflow override.**

  Read `resources/css/app.css` and assert it contains a `.sales-mobile-detail-page` rule with `overflow-x: clip !important` and a `.sales-mobile-detail-page #crm-main` rule with `overflow: visible !important`. Also assert the fixed action rule still contains `position: fixed`, `inset-inline: 0`, `bottom: 0`, `justify-content: center`, and `max-width: 480px` on the button.

- [ ] **Step 2: Run the focused tests to verify RED.**

  Run: `php artisan test tests/Feature/SalesAgendaEvidenceTest.php tests/Feature/SalesPocketbookMobileContractTest.php --filter='mobile_agenda_detail|mobile.*overflow|sticky|actions_fit' --compact`

  Expected: FAIL because the scoped `#crm-main` overflow override does not exist yet.

- [ ] **Step 3: Add rendered assertions for the existing page and completion form contract.**

  Keep the detail render test asserting `sales-mobile-detail-page`, `sales-agenda-sticky-action`, and `sales-agenda-completion-form`; assert the completion form still includes bottom padding/clearance markup in the source contract rather than changing its workflow.

### Task 2: Remove the mobile detail inner vertical scroll container

**Files:**
- Modify: `resources/css/app.css`

**Interfaces:**
- Consumes: Existing `#crm-main` shell rules and `.sales-mobile-detail-page` mobile detail rules.
- Produces: A narrow CSS override that makes the document viewport the vertical scroll owner for the mobile agenda detail page.

- [ ] **Step 1: Add the minimal scoped override.**

  In the mobile detail CSS block, add `.sales-mobile-detail-page { overflow-x: clip !important; }` and `.sales-mobile-detail-page #crm-main { overflow: visible !important; }`. Do not change the global layout rule or any desktop selector.

- [ ] **Step 2: Run the focused regression tests to verify GREEN.**

  Run: `php artisan test tests/Feature/SalesAgendaEvidenceTest.php tests/Feature/SalesPocketbookMobileContractTest.php --filter='mobile_agenda_detail|mobile.*overflow|sticky|actions_fit' --compact`

  Expected: PASS, with the fixed action and completion form contracts unchanged.

### Task 3: Run the affected suite and browser verification

**Files:**
- No additional source files expected.

**Interfaces:**
- Consumes: The CSS override and regression tests from Tasks 1–2.
- Produces: Runtime evidence that the action remains visible at scroll top and bottom without horizontal overflow or console errors.

- [ ] **Step 1: Run the affected agenda tests.**

  Run: `php artisan test tests/Feature/SalesAgendaEvidenceTest.php tests/Feature/SalesPocketbookMobileContractTest.php tests/Feature/SalesAgendaWorkspaceInteractionMarkupTest.php tests/Feature/SalesAgendaWorkspaceRenderTest.php --compact`

  Expected: PASS for the affected tests; any known baseline failures outside this change must be reported separately.

- [ ] **Step 2: Run project checks required for CSS/Blade changes.**

  Run: `php artisan optimize:clear`, `php artisan view:cache`, `npm run build`, `vendor/bin/pint --dirty --format agent`, `git diff --check`, and `graphify update .`.

  Expected: Commands complete successfully; Pint may report only pre-existing unrelated formatting if applicable.

- [ ] **Step 3: Verify the authenticated detail page in the browser.**

  At viewport sizes 524×1002 and 390×844, reload the Agenda detail page, inspect computed styles for `#crm-main` and `.sales-agenda-sticky-action`, record screenshots at scroll top and scroll end, and check `document.documentElement.scrollWidth <= window.innerWidth`. Confirm the action remains visible and centered at both positions, and confirm the console has no new errors.

- [ ] **Step 4: Inspect the final diff for scope.**

  Confirm only the intended CSS/test changes for this fix are added on top of the existing worktree changes, with no route, backend, workflow, or unrelated file changes.
