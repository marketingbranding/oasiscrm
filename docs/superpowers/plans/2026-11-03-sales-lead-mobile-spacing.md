# Sales Lead Mobile Spacing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Remove the optional helper copy and add mobile breathing room between the Lead Saya form header and Tanggal Lead.

**Architecture:** Keep the existing Lead Saya Blade partial and mobile CSS contract. Remove the helper span from the optional-fields summary and change only the mobile `.sales-lead-entry-scroll` top padding from `0` to `8px`; no backend or shared spacing primitive changes.

**Tech Stack:** Laravel Blade, shared CSS, PHPUnit source/render contract, Vite, Playwright.

**Spec:** `docs/superpowers/specs/2026-11-03-sales-lead-mobile-spacing-design.md`

## Global Constraints

- No route, backend, dependency, or form workflow changes.
- Keep OASIS compact workstation styling and existing 4px spacing rhythm.
- Verify at approximately 425×812 and ensure no horizontal overflow.

## Review Focus

- The helper phrase must disappear from rendered HTML and source markup.
- The date field must have visible separation from the modal header on mobile.
- Desktop and form submission behavior must remain unchanged.
- The optional-fields summary must remain keyboard/click operable after copy removal.

---

### Task 1: Update the mobile form contract

**Files:**
- Modify: `tests/Feature/SalesPocketbookMobileContractTest.php`

- [ ] **Step 1: Add failing assertions**

Extend `test_mobile_lead_entry_matches_reference_form_contract()` to assert the
helper text is absent and the mobile `.sales-lead-entry-scroll` rule includes
`padding-top: 8px !important`.

- [ ] **Step 2: Run the focused contract and verify RED**

```bash
php artisan test tests/Feature/SalesPocketbookMobileContractTest.php --filter=mobile_lead_entry_matches_reference_form_contract --compact
```

Expected: fail because the helper text is still rendered and mobile padding is
currently explicitly `0`.

### Task 2: Implement local markup and spacing changes

**Files:**
- Modify: `resources/views/crm/sales-pocketbook/partials/lead-saya.blade.php`
- Modify: `resources/css/app.css`

- [ ] **Step 1: Remove the helper span**

Change the optional-fields summary to render only `Sumber dan proses`, keeping
the existing `details`/`summary` interaction and plus/minus indicator.

- [ ] **Step 2: Add mobile header separation**

Change only the mobile override for `.sales-lead-entry-scroll` from
`padding-top: 0 !important` to `padding-top: 8px !important`. Remove obsolete
summary-span color/display rules made unused by the markup removal.

- [ ] **Step 3: Run the focused contract and verify GREEN**

```bash
php artisan test tests/Feature/SalesPocketbookMobileContractTest.php --filter=mobile_lead_entry_matches_reference_form_contract --compact
```

### Task 3: Verify rendered UI and repository quality

- [ ] **Step 1: Clear/cache views and build assets**

```bash
php artisan optimize:clear --no-interaction
php artisan view:cache --no-interaction
npm run build
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 2: Verify browser behavior**

At approximately 425×812, open the Lead Saya quick-create panel and confirm the
helper phrase is absent, Tanggal Lead is separated from the header, the summary
still opens/closes, and the document has no horizontal overflow or console errors.

- [ ] **Step 3: Run final checks**

```bash
php artisan test tests/Feature/SalesPocketbookMobileContractTest.php --compact
git diff --check
```
