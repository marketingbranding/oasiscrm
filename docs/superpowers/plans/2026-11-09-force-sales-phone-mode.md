# Force Personal Buku Saku Sales Phone Mode Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Keep the personal Buku Saku Sales workflow in its existing phone-style layout at laptop widths.

**Architecture:** Extend the route-scoped `body.sales-personal-page` presentation rules so they apply at every viewport width, while preserving the existing narrow centered canvas and mobile-specific small-screen refinements. Monitoring, coordinator, supervisor, and administration views remain outside this body class and unchanged.

**Tech Stack:** Laravel Blade, shared CSS in `resources/css/app.css`, PHPUnit feature contract tests, Vite production assets, Playwright browser verification.

**Spec:** Approved design in the conversation on 2026-11-09: option 1, limited to the personal Sales workspace.

## Global Constraints

- Preserve OASIS visual identity, Indonesian labels, and existing Buku Saku Sales workflows.
- Do not add JavaScript, dependencies, routes, authorization changes, or business-logic changes.
- Leave supervisor, coordinator, monitoring, and administration layouts unchanged.
- Commit generated `public/build` assets because this repository tracks them.

## Review Focus

- Laptop viewport: personal Buku Saku must use the narrow phone canvas rather than the wide CRM shell; pin this with CSS contract assertions and browser geometry.
- Existing mobile viewport: current 390px layout and fixed actions must remain unchanged; rerun the existing mobile contract suite.
- Other Sales screens: views without `sales-personal-page` must not receive the phone-mode rules; keep selectors route-scoped.
- Wide content: tabs and forms must remain usable inside the narrow canvas without horizontal page overflow; inspect at 1440px.
- Generated assets: the manifest must point to the rebuilt CSS asset; verify with the production build.

---

### Task 1: Apply route-scoped phone mode at all viewport widths

**Files:**
- Modify: `resources/css/app.css` around the existing `body.sales-personal-page` and final reference-contract rules.
- Test: `tests/Feature/SalesPocketbookMobileContractTest.php`.

**Interfaces:**
- Consumes: existing `body-class` value `sales-personal-page` from `resources/views/crm/sales-pocketbook/sales-agenda.blade.php`.
- Produces: route-scoped CSS that keeps the personal workspace narrow and phone-styled above `767px`, without changing non-personal Sales pages.

- [ ] **Step 1: Add failing CSS contract assertions** for an all-width route-scoped phone canvas, including the narrow `crm-page-shell`, hidden global shell elements, and the absence of a max-width-only gate around those declarations.
- [ ] **Step 2: Run the focused contract test**.

  Run: `php artisan test --compact tests/Feature/SalesPocketbookMobileContractTest.php --filter=phone_mode`

  Expected: FAIL because the current phone rules are inside `@media (max-width: 767px)`.

- [ ] **Step 3: Move or duplicate only the personal phone-mode declarations** into an unconditional `body.sales-personal-page` scope. Keep genuinely small-screen refinements, such as compact padding and phone-specific grid changes, under their existing media query. Ensure the personal screen remains centered at roughly `480px` and does not re-enable the desktop shell at laptop widths.
- [ ] **Step 4: Run the focused contract test**.

  Run: `php artisan test --compact tests/Feature/SalesPocketbookMobileContractTest.php --filter=phone_mode`

  Expected: PASS.

- [ ] **Step 5: Run the complete affected contract suite**.

  Run: `php artisan test --compact tests/Feature/SalesPocketbookMobileContractTest.php`

  Expected: PASS with no regressions to lead, agenda, onboarding, or lifecycle contracts.

- [ ] **Step 6: Format and build assets**.

  Run: `vendor/bin/pint --dirty --format agent` and `npm run build`

  Expected: both commands pass; `public/build/manifest.json` references the new CSS asset.

- [ ] **Step 7: Verify browser geometry** at approximately `390x844` and `1440x900` on the personal Buku Saku route. Confirm both viewports show the same narrow phone composition, the desktop sidebar/topbar remain hidden, no horizontal page overflow appears, and the lead form’s existing bottom actions remain visible.
- [ ] **Step 8: Validate final diff and commit**.

  Run: `php artisan view:cache`, `git diff --check`, and `git status --short`

  Expected: Blade cache and whitespace checks pass; only intended CSS, test, and tracked build files are changed.

  Commit: `git commit -m "fix: keep personal sales workspace in phone mode"`
