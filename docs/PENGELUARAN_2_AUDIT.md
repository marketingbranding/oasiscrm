# Pengeluaran 2.0 Audit

This audit captures the current Pengeluaran implementation before UI migration. It is intentionally behavior-preserving: authorization, scope, persistence, export, cancellation, and optimistic locking remain the source of truth unless a later task explicitly changes them.

## Current Surface

Pengeluaran is implemented as an operational finance module, not a ledger, reimbursement, approval, budget, or payment system. It records manual expenses per branch and optional project, supports category management, exports XLSX reports, and preserves cancelled records for audit history.

Current routes:

| Method | URI | Name | Controller | Extra middleware |
|---|---|---|---|---|
| GET | `/pengeluaran` | `expenses.index` | `ExpenseController@index` | `permission:expenses.view` |
| GET | `/pengeluaran/create` | `expenses.create` | `ExpenseController@create` | `permission:expenses.create` |
| POST | `/pengeluaran` | `expenses.store` | `ExpenseController@store` | `permission:expenses.create` |
| GET | `/pengeluaran/export` | `expenses.export` | `ExpenseController@export` | `permission:expenses.export` |
| GET | `/pengeluaran/projects` | `expenses.projects` | `ExpenseController@projects` | `permission:expenses.view` |
| GET | `/pengeluaran/{expense}` | `expenses.show` | `ExpenseController@show` | `permission:expenses.view` |
| GET | `/pengeluaran/{expense}/edit` | `expenses.edit` | `ExpenseController@edit` | `permission:expenses.update` |
| PUT | `/pengeluaran/{expense}` | `expenses.update` | `ExpenseController@update` | `permission:expenses.update` |
| PATCH | `/pengeluaran/{expense}/cancel` | `expenses.cancel` | `ExpenseController@cancel` | `permission:expenses.cancel` |
| GET | `/pengeluaran/kategori` | `expense-categories.index` | `ExpenseCategoryController@index` | `permission:expenses.manage_categories` |
| POST | `/pengeluaran/kategori` | `expense-categories.store` | `ExpenseCategoryController@store` | `permission:expenses.manage_categories` |
| PUT | `/pengeluaran/kategori/{expenseCategory}` | `expense-categories.update` | `ExpenseCategoryController@update` | `permission:expenses.manage_categories` |
| PATCH | `/pengeluaran/kategori/{expenseCategory}/toggle` | `expense-categories.toggle` | `ExpenseCategoryController@toggle` | `permission:expenses.manage_categories` |

All routes are inside the protected CRM group and currently pass through `web`, `auth`, `active`, `verified`, `password.changed`, `operational.maintenance`, and `sales.access` before the route-specific permission middleware.

## Authorization And Scope

Registered Pengeluaran permissions are defined in `PermissionCatalog`:

| Permission | Purpose |
|---|---|
| `expenses.view` | list/detail/project options |
| `expenses.create` | create form/store |
| `expenses.update` | edit/update active records |
| `expenses.cancel` | cancel active records |
| `expenses.export` | XLSX export |
| `expenses.manage_categories` | category index/store/update/toggle |

Organization scope is enforced through `OrganizationScopeService` with module key `expenses` and scope suffixes including view/export/manage behavior. `ExpenseController::scopeFilters()` attaches `scope_branch_ids` and rejects explicitly requested unauthorized branches with 403. Store and update also verify the submitted branch against the caller's manageable branch scope, except for `expenses.manage_all` on update.

Navigation shows Pengeluaran only for non-Sales users with `expenses.view` and a scoped `expenses` permission. Kategori Pengeluaran also requires `expenses.manage_categories`.

Current tests assert Superadmin and primary Pusat can access expense endpoints, Sales/staff/admin/manager are forbidden for HTTP routes, and category management is Superadmin-only at UI and HTTP layers. This is the behavior this migration should preserve.

### Access Drift To Preserve

The product prompt for Pengeluaran has described Superadmin + primary Pusat as intended users. Executable code contains broader permission mapping and scope structures in `PermissionCatalog`/`OrganizationScopeService`, while focused tests still assert a narrower HTTP outcome for several roles. This migration must not silently broaden or narrow access. Any authorization cleanup should be a separate task with a deliberate matrix update and tests.

Supplemental roles do not grant permissions because user permission resolution is primary-role based. The `sales.access` middleware still applies, so primary Sales must not gain access through navigation or direct routes.

## Data Model

`expenses` stores operational records with soft deletes. Normal UI behavior does not permanently delete records; cancellation sets status and cancellation fields.

Important fields:

| Field | Behavior |
|---|---|
| `expense_date` | required date |
| `branch_id` | required active branch on create; historical inactive branch may remain on edit |
| `project_id` | optional active project for selected branch; historical inactive project may remain on edit |
| `expense_category_id` | required active category on create; historical inactive category may remain on edit |
| `amount` | `decimal(15,2)` storage and model cast `decimal:2` |
| `payment_method` | allowlisted method key |
| `description` | required short description |
| `vendor_name` | optional vendor/recipient |
| `reference_number` | optional reference |
| `notes` | optional notes |
| `status` | `active` or `cancelled` |
| `cancellation_reason` | required when cancelling |
| `cancelled_at` / `cancelled_by` | set during cancellation |
| `created_by` / `updated_by` | actor tracking |
| `lock_version` | optimistic locking together with timestamp token |

Money is stored as decimal. Existing summary/export calculations cast totals to float for display and workbook numeric cells; this is current behavior and not changed by the UI migration.

`expense_categories` are database-backed, seeded by migration, soft-deletable, ordered by `sort_order` then `name`, and toggled active/inactive. Category `code` is stable and ignored on update.

Seeded category codes include `iklan_digital`, `event_pameran`, `cetak_media_promosi`, `transportasi`, `konsumsi`, `peralatan`, `langganan_software`, `operasional_kantor`, `pemeliharaan`, `dana_talangan`, `pengadaan`, and `lainnya`.

## Current Workflows

Index:

- normalizes query input through `ExpenseFilterService::normalize()`;
- scopes branches before querying;
- eager loads branch, project, category, creator, and comment count;
- paginates with query string preservation;
- displays active-period summary metrics;
- provides search, advanced filters, direct-click sorting, XLSX export, and create action.

Create/store:

- active manageable branches only;
- project must belong to selected branch and be active;
- category must be active;
- amount must be positive, at most two decimals, and below the configured max;
- `submit_action=add_another` preserves only safe reusable fields.

Edit/update:

- cancelled records cannot be edited;
- historical inactive branch/project/category can remain visible for existing records;
- optimistic locking uses `expected_updated_at` and `lock_version` via `OptimisticLockService`;
- stale JSON writes return HTTP 409 and do not overwrite.

Cancel:

- cancellation requires a reason and optimistic token;
- cancellation changes status to `cancelled`, records actor/time/reason, increments lock version, and writes activity log;
- cancelled records are excluded from default active views and active summaries;
- `status=all` includes cancelled detail rows while summaries remain active-only.

Export:

- XLSX export uses `ExpenseReportExport` and PhpSpreadsheet;
- workbook sheets are `RINGKASAN`, `DETAIL PENGELUARAN`, and `REKAP`;
- text cells are written safely for formula-like input;
- dates and amounts use native spreadsheet types and formats;
- empty export redirects back with warning.

Project options JSON:

- validates active branch;
- checks branch visibility through `OrganizationScopeService`;
- returns active projects for that branch only;
- reports retryable JSON failure if loading projects throws.

## Confirmed Non-Features

No evidence/upload attachment behavior was found. No import flow exists for Pengeluaran. No approval, payment execution, reimbursement, budget, accounting ledger, or external sync behavior exists in this module. These must not be introduced as incidental UI work.

## Current UI Gaps

The existing pages already use some canonical primitives, including `.crm-table-scroll`, `.crm-data-table`, `x-crm.click-sort-th`, `x-crm.date-field`, and the shared CRM layout. Remaining gaps are mostly presentation consistency:

- index header is custom markup instead of `x-crm.page-header`;
- KPI cards use repeated ad hoc markup instead of shared card/section patterns;
- toolbar is close to the canonical pattern but still hand-built;
- active filters are custom spans instead of shared filter chip styling;
- status uses custom border spans instead of `x-crm.status-badge` where practical;
- create/edit/show/category pages remain legacy bordered forms/tables;
- modal focus management is local Alpine code and should remain accessible if migrated;
- mobile usability depends on wrapping and horizontal table scroll, but browser verification is unavailable in this environment.

## Migration Plan

Pengeluaran 2.0 should be implemented as a UI and documentation migration only:

1. Keep all route names, controllers, policies, requests, services, query parameters, validation rules, exports, and persistence semantics unchanged.
2. Migrate index shell to canonical page header, compact KPI sections, toolbar, active filter chips, status badges, empty state, table, and pagination without changing query names or sort labels.
3. Migrate create/edit form presentation while preserving `expenseForm()` dynamic project loading, date picker behavior, hidden optimistic token, and `add_another` behavior.
4. Migrate show and category management pages to the same workstation visual language without adding routes or permissions.
5. Add one concise Indonesian changelog entry for the user-visible UI migration.
6. Run focused Pengeluaran tests, route inspection where relevant, `optimize:clear`, `view:cache`, Pint for changed PHP files if any PHP changes occur, `npm run build` if scanned markup/CSS changes require it, and `git diff --check`.

## Baseline Verification

Before UI edits, the focused Pengeluaran baseline passed:

```text
php artisan test tests/Feature/ExpenseAccessTest.php tests/Feature/ExpenseManagementTest.php tests/Feature/ExpenseReportingExportTest.php
35 tests, 355 assertions
```

Browser automation was not available during this audit, so no browser verification is claimed.
