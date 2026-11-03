# Mobile Agenda Photo Actions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Give Sales users separate controls to upload an existing Agenda photo or take a new photo with a mobile camera.

**Architecture:** Keep the existing multipart `sales-agendas.evidence.store` endpoint, validation, storage, image processing, authorization, and audit logging unchanged. Change only the Agenda detail markup to render two native file controls: one normal picker and one with `capture="environment"`.

**Tech Stack:** Laravel 13, Blade, PHPUnit, native HTML file input, existing Tailwind/CSS primitives.

**Spec:** `docs/superpowers/specs/2026-10-31-mobile-agenda-photo-actions-design.md`

## Global Constraints

- Preserve the existing maximum of two photos and the restriction that completed agendas cannot receive evidence.
- Accept only the existing JPEG, PNG, and WebP image types.
- Do not add a camera library, backend endpoint, permission, or storage format.
- Use the existing OASIS labels and shared CRM button styling.
- Add one Indonesian Oasis Changelog entry through an idempotent migration.

## Review Focus

- Existing-image upload must submit the same `photo` field and route as before; pin this in the detail-page markup test (Task 1).
- Camera capture must use the rear-camera hint without changing accepted MIME types; pin `capture="environment"` and `accept` in the markup test (Task 1).
- Completed agendas must still render neither action; retain the existing conditional and cover it in the markup test (Task 1).
- The two controls must remain usable on narrow screens; verify the rendered form structure at approximately 390px during browser verification (Task 3).
- Changelog insertion must be idempotent and rendered once; verify the migration and Changelog page (Task 2).

---

### Task 1: Add regression coverage for separate photo actions

**Files:**
- Modify: `tests/Feature/SalesAgendaEvidenceTest.php` near `test_owner_can_view_and_delete_before_done_but_not_after_done`

**Interfaces:**
- Consumes: Existing Sales user, Agenda factory setup, `sales-agendas.show` route, and current evidence conditional.
- Produces: Assertions that the detail page exposes distinct upload and camera controls while preserving the existing endpoint and file contract.

- [ ] **Step 1: Write the failing feature test**

Add a test that creates a planned Agenda with no evidence, requests its detail page as the owner, and asserts:

- response is successful;
- `Upload dari perangkat` is present;
- `Ambil foto` is present;
- both controls use `route('sales-agendas.evidence.store', $agenda)`;
- the upload input accepts `image/jpeg,image/png,image/webp` and has no camera capture attribute;
- the camera input accepts the same types and includes `capture="environment"`;
- after marking the Agenda done, the detail page no longer renders either action.

- [ ] **Step 2: Run the focused test to verify it fails**

Run: `php artisan test tests/Feature/SalesAgendaEvidenceTest.php --filter=separate_photo_actions`

Expected: FAIL because the current page exposes only one `Tambah foto` input and no separate action labels.

- [ ] **Step 3: Commit the failing test**

```bash
git add tests/Feature/SalesAgendaEvidenceTest.php
git commit -m "test: define separate agenda photo actions"
```

### Task 2: Implement the two native photo actions and changelog

**Files:**
- Modify: `resources/views/crm/sales-pocketbook/sales-agenda-detail.blade.php:43-49`
- Create: `database/migrations/2026_10_31_000000_add_mobile_agenda_photo_actions_changelog.php`

**Interfaces:**
- Consumes: Existing `sales-agendas.evidence.store` route and `photo` multipart field.
- Produces: Separate **Upload dari perangkat** and **Ambil foto** forms, both backed by the unchanged evidence upload flow; one idempotent Changelog row.

- [ ] **Step 1: Replace the single evidence input with two accessible native controls**

In the existing unfinished-agenda conditional, render two clearly labelled forms or form controls:

- upload action: normal `type="file"`, existing accepted image MIME types, no `capture` attribute;
- camera action: `type="file"`, the same accepted image MIME types, `capture="environment"`;
- both submit to `route('sales-agendas.evidence.store', $agenda)` with `name="photo"` and CSRF protection;
- keep the existing maximum-two condition and shared CRM button component;
- preserve mobile touch sizing and avoid a horizontal overflow layout.

- [ ] **Step 2: Add the idempotent Indonesian Changelog migration**

Create a migration using `DB::table('changelogs')->updateOrInsert(...)` with a stable `title` such as `Agenda Sales Menyediakan Upload dan Kamera`, `version` set to `null`, category `changed`, and a concise Indonesian description. `down()` must remove only that exact title/version pair.

- [ ] **Step 3: Run formatting and the focused test**

Run: `vendor/bin/pint --dirty --format agent`

Run: `php artisan test tests/Feature/SalesAgendaEvidenceTest.php --filter=separate_photo_actions`

Expected: PASS.

- [ ] **Step 4: Commit the implementation**

```bash
git add resources/views/crm/sales-pocketbook/sales-agenda-detail.blade.php database/migrations/2026_10_31_000000_add_mobile_agenda_photo_actions_changelog.php
git commit -m "feat: add agenda upload and camera actions"
```

### Task 3: Verify the complete change

**Files:**
- No source changes expected unless verification finds a defect.

**Interfaces:**
- Consumes: Updated Blade view, migration, focused feature test, and existing production-quality browser workflow.
- Produces: Evidence that upload/camera controls, existing evidence behavior, responsive layout, and Changelog rendering work together.

- [ ] **Step 1: Clear caches and run relevant tests**

Run: `php artisan optimize:clear`

Run: `php artisan view:cache`

Run: `php artisan test tests/Feature/SalesAgendaEvidenceTest.php`

Expected: all tests in the focused file pass.

- [ ] **Step 2: Build frontend assets**

Run: `npm run build`

Expected: Vite production build exits successfully.

- [ ] **Step 3: Verify browser behavior**

Using the existing development command, inspect the Agenda detail page at approximately 1440x900 and 390x844. Confirm the two labels, file controls, focus states, no horizontal overflow, and that the camera control retains the native mobile capture hint. Confirm a completed Agenda does not show either control.

- [ ] **Step 4: Verify changelog and repository hygiene**

Run the migration in the normal test/database verification path, confirm exactly one matching Changelog row and that the Changelog page renders it, then run `git diff --check`.

- [ ] **Step 5: Commit any verification-only fixes**

If verification requires a source fix, rerun the affected checks and commit it separately with a focused message; otherwise leave the implementation commits unchanged.
