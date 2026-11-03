# Buku Saku Sales Gap Pass 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Menyamakan layar mobile Rencana, TambahAgendaForm, DetailAgenda, dan StrukAkhirPekan dengan reference pada 390×844 tanpa mengubah route, service, authorization, atau tampilan desktop/legacy.

**Architecture:** Pertahankan Blade existing dan endpoint existing. Tambahkan helper slot waktu murni di JavaScript yang dipakai oleh workspace form, markup mobile yang tetap mengirim field request lama, dan evidence slot tunggal yang auto-submit ke route upload existing. Semua CSS baru memakai prefix `sales-mobile-agenda-*` atau selector turunan `.sales-mobile-detail-page`.

**Tech Stack:** Laravel Blade/PHP 8.3, Alpine.js, vanilla ES modules, Tailwind CSS v4/Vite, PHPUnit Feature tests, Playwright browser verification.

**Spec:** `docs/superpowers/specs/2026-10-31-buku-saku-sales-gap-pass-2-design.md`

## Global Constraints

- Jangan mengubah route, service, policy, permission, authorization, atau schema.
- CSS baru hanya berlaku pada komponen agenda mobile dan detail mobile.
- Semua teks layar agenda mobile memakai Archivo; tidak ada serif fallback.
- Weekend diturunkan dari tanggal rencana; jangan menambah kolom database.
- Maksimal evidence tetap 2 dan authorization upload tetap melalui route existing.
- Pertahankan desktop/legacy behavior.

## Review Focus

- Agenda tanpa jam — harus menampilkan `Fleksibel`, tidak `--:--` atau `0 mnt`; dipin oleh render test.
- Slot mulai di istirahat/overlap/jam pulang — harus dikoreksi atau ditolak secara deterministik; dipin oleh helper test.
- Custom duration kosong, bukan kelipatan 5, atau melewati jadwal — harus memberi error inline; dipin oleh helper/browser test.
- Double tap pada slot foto — hanya satu submit dan tidak ada foto duplikat; dipin oleh browser interaction check dan max-two feature test.
- Sticky completion bar pada viewport kecil — konten hasil tidak tertutup dan tombol tetap nonaktif sampai hasil terisi; dipin oleh render/browser check.

---

### Task 1: Pure agenda slot helper

**Files:**
- Create: `resources/js/sales-agenda-slot.js`
- Create: `tests/frontend/sales-agenda-slot.test.js`
- Modify: `package.json` only if needed to expose `node --test` without adding dependencies

**Interfaces:**
- Produces `calculateAgendaSlot({ date, agendas, requestedStart, durationMinutes, schedule })` returning `{ ok, startTime, endTime, durationMinutes, remainingMinutes, message }`.
- Produces `formatAgendaTime(minutes)` and `isWeekendDate(date)` as pure exports.

- [ ] Write failing Node tests for Monday–Thursday start at 09:00, Friday start at 08:30, empty weekday, previous agenda end, lunch jump, overlap rejection, 17:00 rejection, weekend free start, and custom duration validation.
- [ ] Run `node --test tests/frontend/sales-agenda-slot.test.js`; confirm RED before implementation.
- [ ] Implement the pure helper with no DOM, Alpine, or network dependency. Weekday schedule is 09:00–17:00 except Friday 08:30–17:00, break 12:00–13:00 except Friday 11:30–13:00; weekend has no forced start/target.
- [ ] Run the focused Node test; confirm GREEN.

### Task 2: Mobile Rencana/weekend rendering and agenda form

**Files:**
- Modify: `resources/views/crm/sales-pocketbook/partials/agenda-receipt.blade.php`
- Modify: `resources/js/sales-agenda-workspace.js`
- Modify: `resources/css/app.css`
- Test: `tests/Feature/SalesAgendaWorkspaceRenderTest.php`

**Interfaces:**
- Consumes `calculateAgendaSlot()` from Task 1 and existing `schedule`/`receiptSummary` view data.
- Preserves request fields `scheduled_date`, `start_time`, `end_time`, `duration_minutes`, `sales_activity_category`, and `title`.

- [ ] Add failing render assertions for `Fleksibel`, weekday tab underline contract, weekend badge/footer copy, and form controls for 30/60/90/120/custom.
- [ ] Run the focused render test; confirm RED.
- [ ] Update agenda row markup so missing start/end renders `Fleksibel` and present times render time range plus `N mnt` below it.
- [ ] Update weekend summary/submitted markup to use `Akhir pekan · bebas`, yellow `AKHIR PEKAN` badge, and `TOTAL N MENIT · TANPA TARGET`; retain existing submission marker data so Coordinator receives the weekend state.
- [ ] Replace the mobile form’s start picker presentation with a 120px time input, duration chips plus custom minutes input, green completion/remaining helper, and inline validation region while preserving the existing hidden/native request fields.
- [ ] Make the bottom sheet scrollable with a sticky bottom action and make background content the receipt canvas when editing a non-empty plan.
- [ ] Wire the Alpine handler to call the pure helper for default start, break jump, duration/end calculation, overlap, and error display; prevent double event listeners.
- [ ] Add scoped mobile CSS tokens/selectors for ink, paper, yellow, muted, and green. Keep desktop selectors unchanged.
- [ ] Run focused render tests and inspect the generated HTML for no desktop selector regressions.

### Task 3: Mobile agenda detail and evidence upload

**Files:**
- Modify: `resources/views/crm/sales-pocketbook/sales-agenda-detail.blade.php`
- Modify: `resources/js/app.js` or a new focused module registered from it
- Modify: `resources/css/app.css`
- Test: `tests/Feature/SalesAgendaEvidenceTest.php`

**Interfaces:**
- Uses existing `sales-agendas.evidence.store` route and existing `name="photo"` validation.
- Keeps existing activity-result PATCH form and optimistic-lock field unchanged.

- [ ] Add failing feature assertions for the ink breadcrumb button, single info card, `accept="image/*"`, hidden evidence inputs, `n dari 2`, disabled completion button, and visible next-agenda text.
- [ ] Run the focused evidence test; confirm RED.
- [ ] Replace the detail summary grid with one Archivo info card containing status pill, title, yellow category tag, and location.
- [ ] Render two 110px evidence slots; occupied slots show thumbnail/name, empty slots use hidden `accept="image/*"` input and a `+ Foto` button. Hide/disable all slots at two photos.
- [ ] Implement single-submit upload behavior with per-slot loading, error/retry state, and a guard against duplicate selection/taps. Keep upload authorization and endpoint unchanged.
- [ ] Make the activity result textarea at least 110px high, show `Selanjutnya: <jam> <judul>`, add bottom content padding, and disable completion until trimmed result is non-empty. Preserve server-side required validation.
- [ ] Scope sticky action above the feedback/chat control with mobile-only spacing or an existing shell offset; do not alter desktop chat placement.
- [ ] Run focused evidence tests and inspect the rendered DOM for accessibility labels and hidden input behavior.

### Task 4: Regression, build, and visual verification

**Files:**
- Modify: `tests/Feature/SalesAgendaWorkspaceRenderTest.php`
- Modify: `tests/Feature/SalesAgendaEvidenceTest.php`
- Modify: `artifacts/SCREENSHOT_INDEX.md` if screenshot inventory changes

- [ ] Run `node --test tests/frontend/sales-agenda-slot.test.js`.
- [ ] Run the focused Buku Saku suite covering receipt workflow, workspace render, evidence, authorization, category, and pocketbook tests.
- [ ] Run `vendor/bin/pint --dirty --format agent`, `php artisan optimize:clear`, `php artisan view:cache`, `npm run build`, and `git diff --check`.
- [ ] Browser-verify reference and app at 390×844 for Rencana, TambahAgendaForm, DetailAgenda, and StrukAkhirPekan; capture before/after screenshots in `artifacts/`.
- [ ] Browser-verify 1440×900 for desktop layout, no horizontal overflow, and no console errors.
- [ ] Report each requested point as sesuai/diubah/belum bisa with screenshot paths and any fixture limitation.
