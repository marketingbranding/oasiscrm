# Buku Saku Sales Onboarding Hallmark Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Recompose the existing Buku Saku Sales onboarding into a focused, mobile-first four-step experience that follows the supplied PDF while preserving all existing onboarding copy, routes, forms, selectors, accessibility hooks, and behavior.

**Architecture:** Keep the current Blade page and Alpine data component as the behavior boundary. Add a page-only shell variant so onboarding can visually leave the CRM chrome without changing the global shell for other routes, then replace the current generic illustration with four semantic, CSS-built visual compositions. Keep all new visual values in the existing OASIS token layer and use CSS-only opacity/transform transitions with reduced-motion fallbacks.

**Tech Stack:** Laravel Blade, Alpine.js, Tailwind CSS v4/Vite, shared `resources/css/app.css`, PHPUnit feature contracts, Playwright smoke verification.

**Spec:** `C:\Users\Administrator\Downloads\Onboarding · Buku Saku Sales.pdf`

## Global Constraints

- Preserve every onboarding endpoint, form method/action, hidden field, main copy, `aria-label`, focus behavior, keyboard arrow navigation, skip flow, replay flow, and completion flow.
- Do not reintroduce Daily Planning or add new onboarding business logic.
- Keep OASIS visual identity: yellow, black, compact workstation density, square/minimally rounded geometry, and mobile-first layout.
- Use existing dependencies and existing OASIS tokens; do not add a UI library, icon package, animation package, or new frontend framework.
- All changed PHP must pass Pint; frontend source changes require Vite build; changed Blade/CSS must pass cache and browser checks.
- Do not overwrite unrelated uncommitted work already present in the repository.

## Review Focus

- The first viewport at 390×844 must show OASIS identity, skip, progress, the active visual, heading, description, and primary action without horizontal scrolling; verify with Playwright.
- JavaScript-disabled markup must retain readable onboarding content instead of hiding every slide; verify the Blade structure does not depend on `x-cloak` for content visibility.
- Every existing route/form contract must remain intact, including skip, complete, replay, and `current_step=4`; run the focused mobile contract test.
- Keyboard navigation and focus restoration must continue after visual transitions; verify ArrowLeft/ArrowRight and focus-visible behavior in browser smoke checks.
- Reduced-motion users must receive visible content without transform/opacity animation; verify the `prefers-reduced-motion` CSS branch and focused test/build checks.

---

### Task 1: Add a page-only onboarding shell variant

**Files:**
- Modify: `resources/views/layouts/crm.blade.php:39` to allow a page-provided body class without changing existing shell data or navigation.
- Modify: `resources/views/crm/sales-pocketbook/onboarding.blade.php:1-5` to provide `sales-pocketbook-onboarding-shell`.
- Modify: `resources/css/app.css:1-100` and the onboarding section to define/reset the page-only shell using existing OASIS tokens.

**Interfaces:**
- Consumes: existing `@extends('layouts.crm')` page and the global `crmShell` Alpine data.
- Produces: a body class that only this onboarding view uses; all other CRM pages retain their current shell.

- [ ] **Step 1: Add the optional body-class yield**

  Append `@yield('body-class')` to the existing body class attribute in `layouts/crm.blade.php` without changing `x-data`, existing classes, or shell markup.

- [ ] **Step 2: Opt onboarding into the shell variant**

  Add `@section('body-class', 'sales-pocketbook-onboarding-shell')` directly after the onboarding title section.

- [ ] **Step 3: Define the page-only shell rules**

  Hide only the global header/sidebar/footer for the class, remove shell padding and desktop sidebar offset, and make the onboarding content fill the viewport. Use tokens, `overflow-x: clip`, and focus-safe layout rules; do not change global shell rules for other pages.

- [ ] **Step 4: Run the focused render contract**

  Run: `php artisan test --compact tests/Feature/SalesPocketbookMobileContractTest.php`

  Expected: all tests pass; onboarding route and form behavior remain unchanged.

---

### Task 2: Recompose the onboarding Blade markup around the PDF sequence

**Files:**
- Modify: `resources/views/crm/sales-pocketbook/onboarding.blade.php:6-45`.

**Interfaces:**
- Consumes: `$steps`, `salesPocketbookOnboarding({{ count($steps) }})`, `route('sales-pocketbook.onboarding.skip')`, and `route('sales-pocketbook.onboarding.complete')`.
- Produces: the same four-step Alpine view with the same routes, but a PDF-aligned header, progress rail, semantic visual panels, and action dock.

- [ ] **Step 1: Preserve the existing Alpine root and accessibility contract**

  Keep `x-data`, `@keydown`, dynamic `aria-labelledby`, `aria-live`, step heading IDs, and the four-step loop. Remove `x-cloak` from slide articles so content remains readable with scripting disabled; rely on Alpine `x-show` for the enhanced single-step view.

- [ ] **Step 2: Move skip into the PDF-style header**

  Keep the existing POST form and CSRF token, but place `Lewati` in a header row beside the OASIS CRM wordmark. Keep it a real submit button with the existing route and an accessible focus state.

- [ ] **Step 3: Build four visual compositions without changing the step copy**

  Add a visual region per step, selected by the loop index:

  - Step 1: yellow Agenda sheet behind a black Lead Saya sheet, using the PDF's existing example labels.
  - Step 2: Kemarin/Hari ini/Rencana list with the Hari ini row emphasized and a dashed evidence-photo affordance.
  - Step 3: a paper-like AGENDA SALES slip with metadata and a torn lower edge, plus the Koordinator Sales handoff label.
  - Step 4: a lead progress card with funnel segments and a prominent Tandai selesai action.

  These are explanatory visual compositions only; they must not submit data, add fields, or introduce new domain state.

- [ ] **Step 4: Keep the action contract and improve hierarchy**

  Retain `Kembali`, `Lanjut`, `Mulai bekerja`, the complete form, hidden `current_step`, and existing route names. Place the primary action in a fixed-in-flow bottom dock, keep `Kembali` secondary, and keep the final submit as the only completion action.

- [ ] **Step 5: Run the focused contract tests**

  Run: `php artisan test --compact tests/Feature/SalesPocketbookMobileContractTest.php tests/Feature/SalesPocketbookTest.php`

  Expected: all tests pass with unchanged redirect/idempotency behavior.

---

### Task 3: Implement the PDF-aligned visual system and interaction states

**Files:**
- Modify: `resources/css/app.css:1-100` for onboarding-specific tokens and the onboarding block around lines 1347-1460.
- Modify: `resources/js/sales-pocketbook-onboarding.js:3-47` only if needed to expose transition direction/focus state without changing navigation semantics.

**Interfaces:**
- Consumes: the Blade class names from Task 2 and the existing Alpine methods `next`, `previous`, `focusStep`, and `handleKeydown`.
- Produces: responsive PDF-like styling with visible hover/focus/active/disabled states and reduced-motion support.

- [ ] **Step 1: Add the Hallmark stamp and onboarding tokens**

  Add the redesign stamp before the stylesheet imports, then add named onboarding paper/ink/muted/line/visual tokens under `:root`. Use existing OASIS font and spacing tokens wherever possible; do not add literal colors or font families in the new onboarding rules.

- [ ] **Step 2: Replace the generic illustration rules**

  Style the header, progress bars, visual stage, paper layers, checklist rows, torn edge, lead progress segments, and action dock. Keep a capped centered container on larger screens and a 390px-friendly single-column flow on mobile. Use `minmax(0, 1fr)` for image-bearing/grid-like tracks and `overflow-wrap: break-word` on display headings.

- [ ] **Step 3: Add interaction and accessibility states**

  Add immediate `:focus-visible` outlines for every onboarding button, visible pressed/hover states, active progress indication, and transition classes that animate only `opacity` and `transform`. Do not use `transition: all`, hover-only information, or tracked-uppercase labels.

- [ ] **Step 4: Add responsive and reduced-motion rules**

  Ensure the action dock never covers content, the visuals scale without horizontal scrolling at approximately 360px/390px/430px, desktop keeps the illustration and copy balanced, and `prefers-reduced-motion: reduce` disables transform/opacity motion while leaving content visible.

- [ ] **Step 5: Build and cache the frontend**

  Run: `php artisan optimize:clear; php artisan view:cache; npm run build`

  Expected: Blade cache succeeds and Vite emits a production build without errors.

---

### Task 4: Verify the redesign against the reference and project contracts

**Files:**
- Test: existing `tests/Feature/SalesPocketbookMobileContractTest.php` and related focused sales tests.
- Inspect: `resources/views/crm/sales-pocketbook/onboarding.blade.php`, `resources/css/app.css`, and generated browser screenshots.

**Interfaces:**
- Consumes: completed Tasks 1–3.
- Produces: verified responsive onboarding with no behavior regression and a clean diff.

- [ ] **Step 1: Run focused behavioral tests**

  Run: `php artisan test --compact tests/Feature/SalesPocketbookMobileContractTest.php tests/Feature/SalesPocketbookTest.php`

  Expected: all focused tests pass.

- [ ] **Step 2: Run formatting and static checks**

  Run: `vendor/bin/pint --dirty --test --format agent` and `git diff --check`.

  Expected: Pint passes and no whitespace errors are reported.

- [ ] **Step 3: Run browser verification at mobile and desktop widths**

  Start the Laravel server, open `/buku-saku-sales/onboarding`, and verify at 390×844 and 1366×900: header/skip, progress, each visual, step actions, keyboard arrows, final submit visibility, no horizontal overflow, and zero browser console errors.

- [ ] **Step 4: Run Hallmark static/render sweep where available**

  Run the Hallmark `sloplint.mjs` against the changed onboarding Blade/CSS with the local route and render tier. Fix every new Floor failure; document any legacy global stylesheet findings that are outside this page's scope.

- [ ] **Step 5: Run the full suite if focused verification is clean**

  Run: `php artisan test --compact`

  Expected: the existing full suite remains green; report any pre-existing deprecations without broadening this redesign.
