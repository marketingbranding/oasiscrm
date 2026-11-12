# Sales Fee Excel Export Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Menambahkan export XLSX untuk seluruh hasil Laporan Fee Sales dengan total agenda, agenda selesai, dan lead pada baris terakhir.

**Architecture:** Route GET baru memakai `SalesFeeReportRequest` dan `SalesFeeReportService::summary()` yang sama dengan halaman laporan, sehingga filter dan scope akses tidak memiliki jalur berbeda. Exporter PhpSpreadsheet khusus menulis satu sheet ringkasan, menjaga kolom angka sebagai angka, menambahkan baris total, dan mengunduh file melalui pola temporary-file yang sudah digunakan OASIS.

**Tech Stack:** Laravel 13, Blade, PHPUnit, PhpSpreadsheet 5.8, existing `ExcelStyle` export concern.

**Spec:** Persetujuan pengguna pada percakapan: tombol Export Excel di Laporan Fee Sales, mengikuti filter aktif, mengexport semua baris hasil, dan menambahkan baris TOTAL.

## Global Constraints

- Pertahankan tombol `Lihat` dan `Cetak` yang sudah ada.
- Gunakan permission dan scope `sales_pocketbook.export` yang sudah melindungi Laporan Fee Sales.
- Jangan menambah dependency atau arsitektur export baru.
- Nilai numerik harus ditulis sebagai numeric cells, bukan teks berformat.
- Data cabang/proyek/sales harus tetap berasal dari hasil `SalesFeeReportService`, bukan input browser yang dipercaya.
- Perubahan perilaku user-visible harus memiliki satu migration changelog idempotent.

## Review Focus

- Filter cabang/proyek/koordinator/sales harus menghasilkan workbook yang sama dengan tabel halaman; test endpoint dengan filter.
- User tanpa permission atau scope tidak boleh mengakses route export; test forbidden.
- Sales tanpa aktivitas tetap muncul dan total menjumlahkan nol; test row zero dan total.
- Teks nama yang berpotensi menyerupai formula harus tetap menjadi text cell; test formula safety.
- Workbook kosong tetap valid dan memiliki header serta total nol; test empty result.

---

### Task 1: Add the failing export contract tests

**Files:**
- Modify: `tests/Feature/SalesFeeReportTest.php`

**Interfaces:**
- Consumes: existing `sales-fee-reports.index` route, test fixtures, and `PhpOffice\PhpSpreadsheet\IOFactory`.
- Produces: executable contract for `sales-fee-reports.export`, workbook headers, filtered rows, numeric totals, zero rows, and denial.

- [ ] **Step 1: Write failing tests**

Add tests named:

- `test_export_downloads_filtered_fee_report_with_numeric_total_row`
- `test_export_is_forbidden_for_user_without_fee_report_access`

The valid test should create activity for both projects, request the export with the existing August period and one project filter, assert the XLSX content type/download filename, load the workbook from `$response->baseResponse->getFile()->getPathname()`, and assert the exact headers, only the filtered sales/project row, numeric values `2`, `1`, and `2`, plus a `TOTAL` row with those same sums. Include a name beginning with `=` and assert it is stored as a string when it is exported.

The authorization test should use the existing denied role fixture and assert the new route returns forbidden.

- [ ] **Step 2: Run the focused tests to verify they fail**

Run: `php artisan test tests/Feature/SalesFeeReportTest.php --filter="export" --compact`

Expected: FAIL because the export route/controller action does not exist.

### Task 2: Implement the scoped XLSX export endpoint

**Files:**
- Create: `app/Exports/SalesFeeReportExport.php`
- Modify: `app/Http/Controllers/Crm/SalesFeeReportController.php`
- Modify: `routes/web.php`

**Interfaces:**
- Consumes: `SalesFeeReportService::summary(User $actor, array $filters): array`.
- Produces: `SalesFeeReportController::export(SalesFeeReportRequest $request): BinaryFileResponse` and route `sales-fee-reports.export`.

- [ ] **Step 1: Implement `SalesFeeReportExport::toBrowser(Collection $rows, array $report, string $filename): BinaryFileResponse`**

Use one sheet titled `LAPORAN FEE SALES` with headers `Sales`, `Koordinator`, `Proyek`, `Total Agenda`, `Agenda Selesai`, `Total Lead`. Write text fields explicitly as strings, counts as numeric cells, then append a `TOTAL` row whose numeric cells are sums of the exported rows. Apply existing `ExcelStyle`, auto-filter only to the data header/data range, freeze the header, and use `tempnam()` plus `deleteFileAfterSend(true)` like the other export classes.

- [ ] **Step 2: Add the controller action and route**

Add the route before the parameterized detail route:

`GET /sales-fee-reports/export` → `SalesFeeReportController::export`, named `sales-fee-reports.export`, with the existing `permission:sales_pocketbook.export` middleware.

The controller action calls `$this->reports->summary($request->user(), $request->validated())`, builds a deterministic filename from the validated date range, and delegates all workbook creation to `SalesFeeReportExport`.

- [ ] **Step 3: Run the focused tests to verify they pass**

Run: `php artisan test tests/Feature/SalesFeeReportTest.php --filter="export" --compact`

Expected: PASS.

### Task 3: Add the page action and changelog

**Files:**
- Modify: `resources/views/crm/sales-fee-reports/index.blade.php`
- Create: `database/migrations/2026_11_12_000001_add_sales_fee_report_excel_export_changelog.php`
- Modify: `tests/Feature/SalesFeeReportTest.php`

**Interfaces:**
- Consumes: `sales-fee-reports.export` and the current filter variables.
- Produces: accessible `Export Excel` action that preserves all current query filters and an idempotent Changelog entry.

- [ ] **Step 1: Add a view contract assertion**

Extend the existing index test to assert `Export Excel` and the generated export route are present when the page is rendered with its current filters.

- [ ] **Step 2: Add the Export Excel button**

Place a secondary export action beside `Terapkan Filter`. Generate the URL with `route('sales-fee-reports.export', [...])`, passing branch, date range, project, coordinator, and sales filters. Give the link an accessible visible label and preserve the existing action hierarchy.

- [ ] **Step 3: Add the idempotent changelog migration**

Insert one Indonesian `added` entry with `version` null using `updateOrInsert` keyed by the stable title, describing that Laporan Fee Sales can export all filtered rows to Excel with a total row. `down()` must remove only that exact entry.

- [ ] **Step 4: Run the focused test file**

Run: `php artisan test tests/Feature/SalesFeeReportTest.php --compact`

Expected: PASS.

### Task 4: Verify, format, and review

- [ ] **Step 1: Clear and rebuild relevant Laravel caches**

Run: `php artisan optimize:clear` and `php artisan view:cache`.

- [ ] **Step 2: Run formatting and validation**

Run: `vendor/bin/pint --dirty --format agent`, `git diff --check`, and `php artisan route:list --name=sales-fee-reports`.

- [ ] **Step 3: Run the full test suite and frontend build if required**

Run: `php artisan test --compact`. Run `npm run build` because Blade markup changed and Tailwind-scanned markup may be affected.

- [ ] **Step 4: Review the final diff**

Confirm only the export endpoint, exporter, view action, focused tests, changelog migration, and plan are changed; do not modify the existing uncommitted NgodingPakeAI baseline files.
