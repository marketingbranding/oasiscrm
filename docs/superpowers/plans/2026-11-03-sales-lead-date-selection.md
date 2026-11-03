# Sales Lead Date Selection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Let primary Sales users choose the actual lead date in the quick-create form while preserving today's default.

**Architecture:** Reuse the existing native date input and `lead_date` request contract. Update the Sales-only Blade branch to render the date input, then make request normalization and assignment validation honor that submitted date instead of forcing today.

**Tech Stack:** Laravel Blade, PHP FormRequest validation, PHPUnit, Vite CSS build.

**Spec:** `docs/superpowers/specs/2026-11-03-sales-lead-date-selection-design.md`

## Global Constraints

- No new date-picker dependency.
- No artificial `min` or `max` date restriction.
- Preserve `lead_date`, `project_id`, route, permission, and existing quick-create behavior.
- Keep `Y-m-d` values and Indonesian labels.

## Review Focus

- A submitted non-today date must persist instead of being replaced by `today()` — covered by the primary Sales create feature test.
- A missing date must still default safely to today — covered by the same request path's default assertion.
- Project assignment windows must use the selected date, not the server's current date — covered by the request validation test or existing assignment fixture.
- The rendered Sales form must expose one native date input and no hidden Sales date input — covered by the mobile contract.
- Existing non-Sales date input and current routes remain unchanged — covered by the focused Sales Pocketbook suite.

---

### Task 1: Add failing contracts for selectable Sales lead dates

**Files:**
- Modify: `tests/Feature/SalesPocketbookMobileContractTest.php`
- Modify: `tests/Feature/SalesPocketbookMobileContractTest.php` existing primary Sales create test

**Interfaces:**
- Consumes: Existing `sales-agendas.index` lead form and `sales-leads.store` route.
- Produces: Failing assertions for native date input rendering and persistence of a selected date.

- [ ] **Step 1: Write the failing tests**

Update the render contract to assert the Sales branch contains `type="date"`,
`name="lead_date"`, and the expected default value, and no longer contains the
Sales hidden date input. Rename the existing server-controlled-date test and
change it to submit a non-today date, asserting that date is persisted.

- [ ] **Step 2: Run the focused tests and verify RED**

Run:

```bash
php artisan test tests/Feature/SalesPocketbookMobileContractTest.php --filter='lead_entry|primary_sales_can_create' --compact
```

Expected: fail because the current Sales branch renders a hidden date and the
request forcibly replaces submitted dates with today.

### Task 2: Implement selected-date handling

**Files:**
- Modify: `resources/views/crm/sales-pocketbook/partials/lead-saya.blade.php`
- Modify: `app/Http/Requests/Crm/SalesLeadRequest.php`
- Modify: `tests/Feature/SalesPocketbookMobileContractTest.php`

**Interfaces:**
- Consumes: Task 1's native input and persistence contracts.
- Produces: A `lead_date` submitted by the Sales user, normalized with a today fallback, and validated against date-aware project assignment.

- [ ] **Step 1: Render the native date input**

Replace the Sales-only hidden `lead_date` and text context with a labelled
`<input type="date" name="lead_date">` using the existing `old()`/query/today
fallback. Keep the project hidden and the project context visible.

- [ ] **Step 2: Preserve a supplied date in `prepareForValidation()`**

For primary Sales users, use the submitted `lead_date` when present and only
fall back to `today()->toDateString()` when absent. Resolve the project using
that effective date. Do not change the server-controlled Sales owner and branch
fields.

- [ ] **Step 3: Use the effective lead date for assignment validation**

Change the project assignment start/end checks to compare against the request's
validated/effective `lead_date`, while retaining today's fallback and existing
authorization checks.

- [ ] **Step 4: Run focused tests and verify GREEN**

Run the Task 1 command and the full `SalesPocketbookMobileContractTest.php`.

### Task 3: Build and verify the workflow

**Files:**
- Modify: generated `public/build/manifest.json` and `public/build/assets/*` when the CSS build hash changes.

- [ ] **Step 1: Run formatting, cache, and build checks**

```bash
vendor/bin/pint --dirty --format agent
php artisan optimize:clear --no-interaction
php artisan view:cache --no-interaction
npm run build
git diff --check
```

- [ ] **Step 2: Verify the browser workflow**

At approximately 425×812, open the Lead Saya quick-create sheet and confirm the
date is a native date control defaulted to today, can be changed, and does not
cause horizontal overflow. Confirm the selected date remains in the form after
the interaction and the existing close/save actions remain visible.

- [ ] **Step 3: Run focused and full verification**

```bash
php artisan test tests/Feature/SalesPocketbookMobileContractTest.php tests/Feature/SalesPocketbookTest.php --compact
php artisan test --compact
```

- [ ] **Step 4: Review and commit**

Review `git diff --stat`, `git diff --check`, and `git status --short`, then
commit the implementation with a focused message.
