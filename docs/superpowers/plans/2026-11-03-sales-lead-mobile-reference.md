# Sales Lead Mobile Reference Alignment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Align the mobile Lead Saya cards and Tambah lead bottom sheet with the supplied reference while preserving routes, payloads, server validation, authorization, and desktop behavior.

**Architecture:** Keep the existing `lead-saya.blade.php` data and form contract. Change only presentation markup needed for visible compact detail actions, required-field markers, manual status badge variants, and the mobile form grouping. Keep the responsive behavior in the existing `resources/css/app.css` token layer; no new JavaScript or dependency is needed.

**Tech Stack:** Laravel Blade, Alpine.js existing form behavior, CSS in `resources/css/app.css`, PHPUnit feature/source-contract tests, Vite.

**Spec:** `docs/superpowers/specs/2026-11-03-sales-lead-mobile-reference-design.md`

## Global Constraints

- Do not change database, controller, route, policy, FormRequest, scope, or submitted field names.
- Keep `phone` server validation required and preserve the existing Indonesian validation message.
- Apply yellow manual-status styling without relying on color alone; status text remains rendered.
- Apply the reference alignment to mobile only; preserve desktop/tablet layout unless a semantic label change is shared safely.
- Keep the optional `Sumber dan proses` fields available and collapsed by default, while reopening them when their validation errors require correction.
- No new dependency, picker, modal, notification, or form architecture.
- Add one idempotent Indonesian Oasis Changelog migration for the user-visible change.

## Review Focus

- A lead with `No Respon`, `Diskusi`, `Tatap Muka`, or `Cek Lokasi` must render a yellow/pending badge with readable text; pin in the mobile render contract test.
- A completed lead must retain its semantic success badge; pin in the same card render test.
- The detail link must be visible, compact, and not stretch over the whole card; pin with markup classes and CSS contract assertions.
- A missing phone must still return HTTP redirect/session validation with the existing message; pin in a request feature test.
- Validation errors in optional source/process fields must reopen the collapsed section; pin in the rendered validation test.
- Long customer names and option labels must wrap without horizontal overflow at 425px; pin with the CSS contract and browser verification.

---

### Task 1: Add failing mobile Lead Saya regression coverage

**Files:**
- Modify: `tests/Feature/SalesPocketbookMobileContractTest.php`
- Modify: `tests/Feature/SalesPocketbookTest.php` if the existing request fixture is the clearer home for server validation.

**Interfaces:**
- Consumes: Existing `salesContext()`, `leadForMobile()`, `sales-leads.store`, and `sales-agendas.index?tab=leads` test helpers/routes.
- Produces: Render and validation contracts for the mobile card/form changes.

- [ ] **Step 1: Add a failing mobile card/status test**

  Add a test that creates one open lead with status `discussion` and one completed lead, renders the `tab=leads` workspace, and asserts the open badge uses `crm-status-badge--pending`, the completed badge uses `crm-status-badge--success`, the visible action uses the compact mobile detail class, and the old full-card overlay contract (`height: 100%`/opacity overlay marker) is absent.

- [ ] **Step 2: Add a failing reference-form markup test**

  Assert the response contains the mixed-case `Tambah lead` heading, `sales-required-mark`, the WhatsApp label/field, the collapsed optional-details marker, and both expected mobile action buttons. Assert the optional `<details>` is not open in a clean render.

- [ ] **Step 3: Add a failing missing-phone validation test**

  Post a valid minimal sales lead payload without `phone` and assert redirect plus the exact existing message `No. WhatsApp / Telepon wajib diisi untuk lead baru atau perubahan melalui formulir.`.

- [ ] **Step 4: Run the focused tests to verify RED**

  ```bash
  php artisan test tests/Feature/SalesPocketbookMobileContractTest.php tests/Feature/SalesPocketbookTest.php --filter='mobile|phone' --compact
  ```

  Expected: FAIL because the current modal is open by default, labels have no required marker, manual badges are neutral, and the card action is an opacity/full-card overlay.

### Task 2: Implement the mobile card and Tambah lead reference alignment

**Files:**
- Modify: `resources/views/crm/sales-pocketbook/partials/lead-saya.blade.php`
- Modify: `resources/css/app.css`

**Interfaces:**
- Consumes: The class and markup contracts introduced by Task 1.
- Produces: Mobile-only compact card actions and a reference-aligned add-lead bottom sheet with unchanged form payload.

- [ ] **Step 1: Make manual status variants semantic yellow**

  In the mobile lead loop, use `pending` for open manual statuses and keep `success` for completed leads. Preserve the displayed status label. Do not change the desktop table status behavior unless required for shared markup correctness.

- [ ] **Step 2: Replace the transparent card overlay with a visible action row**

  Keep the existing named detail/edit routes, but render the action container in normal flow on mobile. Give the detail action its compact mobile class, visible label, focus state, and minimum touch height. Remove only the CSS rules that made the first link fill the entire card and become transparent; do not remove authorization gates.

- [ ] **Step 3: Add required markers without changing validation semantics**

  Add an `aria-hidden="true"` red asterisk next to visible required labels in the add-lead form, including `No. WhatsApp / Telepon`. Keep HTML `required`, `@required(...)`, and server-side rules unchanged.

- [ ] **Step 4: Match the reference modal grouping**

  Keep the existing hidden server-controlled date/project inputs and visible context. Style the context as the reference’s compact date/project block. Remove unconditional `open` from `Sumber dan proses`; reopen it only when relevant validation errors exist. Keep source, channel, activity, status, notes, duplicate warning, CSRF, operation UUID, and both submit actions intact.

- [ ] **Step 5: Add scoped mobile CSS**

  Use existing OASIS sales tokens and square geometry. Set the heading to mixed-case presentation, retain the yellow `Wajib diisi` badge, make the name/phone controls full-width, keep date/project context in two columns where present, keep source/status compact, preserve scroll-safe bottom-sheet behavior, and ensure long content wraps at 425px. Do not weaken the existing desktop media rules.

- [ ] **Step 6: Run focused tests to verify GREEN**

  ```bash
  php artisan test tests/Feature/SalesPocketbookMobileContractTest.php tests/Feature/SalesPocketbookTest.php --filter='mobile|phone' --compact
  ```

  Expected: all new and existing selected tests pass.

### Task 3: Record the user-visible change and validate source/build output

**Files:**
- Create: `database/migrations/2026_11_03_160000_add_sales_lead_mobile_reference_changelog.php`
- Modify: `tests/Feature/SalesPocketbookTest.php`
- Modify: `public/build/manifest.json` and generated `public/build/assets/*` via Vite.

**Interfaces:**
- Consumes: Completed mobile reference alignment from Task 2.
- Produces: Exactly one idempotent Changelog row titled `Perbaikan Tampilan Mobile Lead Saya`.

- [ ] **Step 1: Add a failing changelog idempotency/visibility test**

  Require the migration, call `up()` twice, assert exactly one null-version `changed` row with the stable title, render the Changelog page as Super Admin, assert the title/description are visible, call `down()`, and assert only the exact row is removed.

- [ ] **Step 2: Implement the migration**

  Use `DB::table('changelogs')->updateOrInsert(['version' => null, 'title' => ...])`; `down()` must delete only the exact null-version/title pair. Use concise Indonesian user-facing copy.

- [ ] **Step 3: Run formatting, focused tests, build, and Blade cache**

  ```bash
  php artisan migrate --no-interaction
  vendor/bin/pint --dirty --format agent
  php artisan test tests/Feature/SalesPocketbookMobileContractTest.php tests/Feature/SalesPocketbookTest.php --compact
  npm run build
  php artisan optimize:clear
  php artisan view:cache
  git diff --check
  ```

  Expected: migration succeeds, focused tests pass, Pint/build/cache/diff checks pass, and the generated manifest points to the new CSS asset.

### Task 4: Browser verification, review, and commit

**Files:**
- No source changes expected unless browser verification finds a concrete defect.

- [ ] **Step 1: Verify the supplied browser tab at 425×812**

  Use browser page `519a704c-ddca-4fe2-8670-ebd241048eb5`, open `Tambah lead`, and verify the compact reference order, collapsed optional section, red WhatsApp asterisk, yellow status badge, visible detail action, no horizontal overflow, and readable long names.

- [ ] **Step 2: Verify desktop behavior and console**

  Check the same workspace at approximately 1440×900, verify the desktop form/cards are not unintentionally restyled, and inspect console/network output for errors or failed assets.

- [ ] **Step 3: Run the full suite and update the code graph**

  ```bash
  php artisan test --compact
  graphify update .
  git diff --check
  ```

  Expected: full suite passes, graph update completes, and no whitespace errors are reported.

- [ ] **Step 4: Commit the focused change**

  ```bash
  git add resources/views/crm/sales-pocketbook/partials/lead-saya.blade.php resources/css/app.css tests/Feature/SalesPocketbookMobileContractTest.php tests/Feature/SalesPocketbookTest.php database/migrations/2026_11_03_160000_add_sales_lead_mobile_reference_changelog.php docs/superpowers/specs/2026-11-03-sales-lead-mobile-reference-design.md docs/superpowers/plans/2026-11-03-sales-lead-mobile-reference.md public/build
  git commit -m "feat: align mobile sales lead workspace"
  ```
