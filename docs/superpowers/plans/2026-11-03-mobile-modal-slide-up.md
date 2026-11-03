# Mobile Modal Slide-Up Animation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make shared CRM modals and the Sales Agenda mobile bottom sheet enter from the bottom and leave downward on mobile, without changing desktop behavior.

**Architecture:** Extend the existing Alpine `x-transition` hooks in the shared CRM modal component and add the same transition contract to the agenda sheet markup. Keep animation styling in `resources/css/app.css`, scoped to the mobile breakpoint, with the existing reduced-motion contract reducing transition duration.

**Tech Stack:** Laravel Blade, Alpine.js, CSS in `resources/css/app.css`, PHPUnit feature/source-contract tests, Vite.

**Spec:** `docs/superpowers/specs/2026-11-03-mobile-modal-slide-up-design.md`

## Global Constraints

- Mobile-only behavior; desktop modal placement and motion remain unchanged.
- Use existing Alpine and CSS; add no animation dependency or drag-to-dismiss behavior.
- Preserve focus management, Escape handling, backdrop close, body-scroll locking, and 44px touch targets.
- Respect `prefers-reduced-motion` with a minimal transition.
- Include an idempotent Indonesian Oasis Changelog migration for the user-visible behavior change.

## Review Focus

- Shared center modal on mobile must slide up while the desktop center modal remains static; pin with `DesignSystemComponentTest` source assertions and browser verification.
- Right-placement drawer must retain its horizontal desktop transition and not receive the mobile bottom-sheet transform; pin with existing placement assertions and CSS selectors.
- Sales Agenda’s specialized bottom sheet must use the same mobile motion and close path; pin with `SalesAgendaWorkspaceInteractionMarkupTest`.
- Reduced-motion users must not receive a full-duration slide; pin with the CSS contract assertion.
- Existing focus/close behavior must remain present after adding transition attributes; preserve and rerun the component interaction tests.

---

### Task 1: Lock the transition contracts with failing tests

**Files:**
- Modify: `tests/Feature/DesignSystemComponentTest.php`
- Modify: `tests/Feature/SalesAgendaWorkspaceInteractionMarkupTest.php`

**Interfaces:**
- Consumes: Existing Blade-rendered shared modal and agenda workspace markup.
- Produces: Regression assertions for shared mobile transition hooks, agenda sheet hooks, mobile-only CSS, and reduced-motion behavior.

- [ ] **Step 1: Add failing shared-modal assertions**

  Extend the modal component test to assert the rendered shared modal includes panel transition hooks for enter/leave states while preserving the existing right-placement backdrop transition.

- [ ] **Step 2: Add failing agenda-sheet assertions**

  Assert the agenda workspace source/rendered markup includes the transition hooks on `sales-sheet-backdrop` or its panel and that the mobile CSS contains the slide-up selector and reduced-motion rule.

- [ ] **Step 3: Run the focused tests to verify RED**

  Run:

  ```bash
  php artisan test tests/Feature/DesignSystemComponentTest.php tests/Feature/SalesAgendaWorkspaceInteractionMarkupTest.php --compact
  ```

  Expected: FAIL only on the new transition-contract assertions.

### Task 2: Implement shared and agenda mobile transitions

**Files:**
- Modify: `resources/views/components/crm/modal.blade.php`
- Modify: `resources/views/crm/sales-pocketbook/partials/agenda-receipt.blade.php`
- Modify: `resources/css/app.css`

**Interfaces:**
- Consumes: The transition names asserted in Task 1.
- Produces: Mobile-only slide-up/slide-down motion for shared center modals and the agenda bottom sheet.

- [ ] **Step 1: Add Alpine transition hooks to the shared modal panel**

  Keep the existing backdrop transition behavior for right placement and add explicit panel enter/leave classes to the dialog section. The panel classes must be inert outside the mobile breakpoint so desktop behavior does not change.

- [ ] **Step 2: Add transition hooks to the Sales Agenda sheet**

  Apply the shared transition naming pattern to the existing `x-show="agendaOpen"` backdrop/panel without changing its form state, focus, body-scroll, or close handlers.

- [ ] **Step 3: Add scoped mobile CSS**

  Use a 150–180ms transform transition with `translateY(100%)` for enter-start and leave-end, returning to `translateY(0)` for the open state. Keep backdrop opacity fade subtle. Add a `prefers-reduced-motion: reduce` override that reduces the duration to the existing minimal value.

- [ ] **Step 4: Run focused tests to verify GREEN**

  Run the same focused PHPUnit command from Task 1. Expected: all existing and new assertions pass.

### Task 3: Add user-visible changelog and validate shipped assets

**Files:**
- Create: `database/migrations/2026_11_03_130000_add_mobile_modal_animation_changelog.php`
- Modify: `tests/Feature/DesignSystemComponentTest.php`
- Modify: `public/build/manifest.json` and generated `public/build/assets/*` via Vite.

**Interfaces:**
- Consumes: The completed UI behavior from Task 2.
- Produces: One idempotent Changelog entry titled `Animasi Modal Mobile dari Bawah` and production asset references.

- [ ] **Step 1: Add the migration idempotency assertion**

  Require the migration in a feature test, run `up()` twice, and assert exactly one null-version row exists with the stable Indonesian title and category `changed`.

- [ ] **Step 2: Implement the migration**

  Use `DB::table('changelogs')->updateOrInsert(['version' => null, 'title' => ...])`; `down()` must delete only that exact null-version/title pair.

- [ ] **Step 3: Run migration, focused tests, Pint, and build**

  Run serially:

  ```bash
  php artisan migrate --no-interaction
  vendor/bin/pint --dirty --format agent
  php artisan test tests/Feature/DesignSystemComponentTest.php tests/Feature/SalesAgendaWorkspaceInteractionMarkupTest.php --compact
  npm run build
  php artisan view:cache
  git diff --check
  ```

  Expected: migration succeeds, focused tests pass, Pint passes, Vite build succeeds, Blade cache succeeds, and diff check is clean.

### Task 4: Browser and final verification

**Files:**
- No source changes expected unless verification finds a defect.

- [ ] **Step 1: Verify the existing dev command and open the relevant page**

  Reuse the existing preview server when available. Use Playwright to inspect at approximately 390×844 and 1440×900.

- [ ] **Step 2: Exercise modal states**

  On an accessible page, verify open and close motion, backdrop close, Escape close, focus placement/restoration, and that the right-placement desktop drawer remains horizontal/static as before.

- [ ] **Step 3: Inspect console and final repository state**

  Read recent browser logs, run the complete test suite where appropriate, run `graphify update .`, inspect `git diff --check`, and confirm no unrelated tracked changes.

- [ ] **Step 4: Commit the focused change**

  ```bash
  git add resources/views/components/crm/modal.blade.php resources/views/crm/sales-pocketbook/partials/agenda-receipt.blade.php resources/css/app.css tests/Feature/DesignSystemComponentTest.php tests/Feature/SalesAgendaWorkspaceInteractionMarkupTest.php database/migrations/2026_11_03_130000_add_mobile_modal_animation_changelog.php docs/superpowers/specs/2026-11-03-mobile-modal-slide-up-design.md docs/superpowers/plans/2026-11-03-mobile-modal-slide-up.md public/build
  git commit -m "feat: animate mobile modals from bottom"
  ```
