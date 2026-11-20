# Task 4 report — Inventory and migrate Sales monitoring consumers (1.4c–1.4d)

## Status

Selesai minimal confirmed migration. Flag `org_hierarchical_monitoring` tetap default-off. Tidak ada cutover produksi.

## Changes

- `SalesLeadOptionController` kini memakai `OrganizationScopeService::branchIds()` saat flag aktif. Workspace branch check tetap berjalan. Flag mati mempertahankan jalur lama.
- Tambah direct AJAX boundary test untuk branch luar scope (BR-51).
- Tambah direct coordinator monitoring URL test untuk forged `sales_id` (BR-51). Existing service guard tetap dipakai.
- Agenda personal tidak diubah. Baseline flag-off tidak menghasilkan foreign agenda visibility dengan fixture valid; controller sudah memakai owner, branch, dan project scope.

## Consumer inventory

| Consumer | Route/controller/service | Scope/action | Status |
|---|---|---|---|
| Lead Tim | `sales-pocketbook.index` → `CoordinatorSalesLeadWorkspaceController` → `CoordinatorSalesMonitoringService` | `OrganizationScopeService`, `WorkspaceAccessService`, `SalesTeamScopeService`; forged `sales_id` denied | Compliant; tested |
| Rencana Harian Tim/agenda | `sales-pocketbook.index`, coordinator monitoring service | `agendaBase`, `todayWorkspace`, `salesDetail` use shared scope | Compliant; unchanged |
| Agenda personal | `sales-agendas.index` → `SalesAgendaController` | owner self plus authorized branch/project | Compliant; no proven bypass |
| Supervisor monitoring | `sales-pocketbook.index` → `SupervisorSalesMonitoringService` | shared organization branch/project/user scope | Compliant; unchanged |
| Admin Branch monitoring | `sales-pocketbook.index` → `AdminBranchSalesMonitoringService` | shared organization branch/project/user scope | Compliant; unchanged |
| Export | coordinator and supervisor export routes | monitoring services produce export data under same scope | Compliant; unchanged |
| Lead option AJAX | `sales-leads.options` → `SalesLeadOptionController` | flag-on branch intersection through `OrganizationScopeService` | Fixed; tested |
| Notifications/deep links/comments | notification routes, `NotificationController`, `CommentableAccessService`, `CommentMentionService` | existing policy/shared scope checks | Compliant; unchanged |
| Team history | no route/service found | none | Absent; not invented |
| Sales monitoring AI tool | no registry tool found in `AiToolRegistry` | none | Absent; not invented |
| Konsumen/Dana Talangan/Expenses | no migration consumer requested/found | retain current scope | Deferred; no distant-summary projection |

## Flag behavior

- Flag on: foreign branch lead options denied; forged coordinator Sales filter denied.
- Flag off: lead option controller does not call organization scope service; existing workspace behavior preserved.

## Verification

- `php artisan test --compact tests/Feature/SalesMonitoringScopeBoundaryTest.php tests/Feature/CoordinatorSalesMonitoringServiceTest.php` — 11 passed, 56 assertions.
- Broader related suite: `php artisan test --compact tests/Feature/SalesMonitoringScopeBoundaryTest.php tests/Feature/CoordinatorSalesMonitoringServiceTest.php tests/Feature/AdminBranchSalesMonitoringTest.php tests/Feature/SupervisorSalesPocketbookTest.php tests/Feature/UniversalCommentsAuthorizationScopeTest.php tests/Feature/UniversalCommentNotificationTest.php` — 62 passed, 421 assertions.
- `vendor/bin/pint --dirty --format agent` — passed.
- `git diff --check` — passed.
- `$env:CACHE_STORE='array'; php artisan optimize:clear` — passed.
- `php artisan view:cache` — passed.
- `graphify update .` — passed.

## Deliberate exclusions

- No new permission or authorization layer.
- No BR-53 grants.
- No team-history route/service.
- No AI monitoring tool.
- No consumer/distant-summary implementation.
- No agenda controller change without a reproducing RED test.
