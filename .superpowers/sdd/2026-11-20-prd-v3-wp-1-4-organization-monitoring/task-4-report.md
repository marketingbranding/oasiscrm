# Task 4 report — Inventory and migrate Sales monitoring consumers (1.4c–1.4d)

## Status

Selesai untuk 1.4c–1.4d. Projection 1.4d implemented. Flag `org_hierarchical_monitoring` tetap default-off. Tidak ada cutover produksi.

## Changes

- `SalesLeadOptionController` kini memakai `OrganizationScopeService::branchIds()` saat flag aktif. Workspace branch check tetap berjalan. Flag mati mempertahankan jalur lama.
- Tambah direct AJAX boundary test untuk branch luar scope (BR-51).
- Tambah direct coordinator monitoring URL test untuk forged `sales_id` (BR-51). Existing service guard tetap dipakai.
- Agenda personal tidak diubah. Baseline flag-off tidak menghasilkan foreign agenda visibility dengan fixture valid; controller sudah memakai owner, branch, dan project scope.
- Round 1 fix: `SalesMonitoringProjectionService` adds one internal projection contract. Flag-off and direct descendants keep existing Eloquent model shape. Distant descendants use Lead allowlist (name, owner Sales, project, stage, last active date) and agenda allowlist (Sales name, date, total/target minutes, send status, activity count, late count). Full distant models never reach Blade/export.
- `ReportingHierarchyService::distance` follows legacy/shadow/graph source semantics. Coordinator, Supervisor, and Admin Branch monitoring paths project same datasets used by screen and Supervisor/Coordinator exports. Team history remains absent; no route/service invented.
- Distant leakage tests cover phone, notes, agenda title, location, activity result, and evidence. Export projection parity tested. Existing notification/deep-link tests explicitly recheck recipient and comment scope; no notification path changed.
- AI inventory corrected: `AiToolRegistry` has 8 read-only tools; no Sales-monitoring-specific tool exists and none added.

## Round 1 fix verification

- `php artisan test --compact tests/Feature/SalesMonitoringProjectionTest.php tests/Feature/SupervisorSalesPocketbookTest.php tests/Feature/SupervisorSalesPocketbookExportTest.php tests/Feature/CoordinatorSalesMonitoringServiceTest.php tests/Feature/AdminBranchSalesMonitoringTest.php` — 55 passed, 358 assertions before final export-test extension; projection suite latest 4 passed, 35 assertions.
- `vendor/bin/pint --dirty --format agent` — passed.
- `git diff --check` — passed.
- `php artisan view:cache` — passed.

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
- No team-history route/service; Team History remains absent.
- No Sales-monitoring-specific AI tool. `AiToolRegistry` retains 8 generic read-only tools.
- No agenda controller change without a reproducing RED test.

## Exact notification/deep-link coverage

- `UniversalCommentNotificationTest::test_br51_sales_notification_deep_link_rechecks_target_scope` verifies direct notification deep links reauthorize Sales target scope and redirect unauthorized recipients to the dashboard.
- `UniversalCommentNotificationTest::test_open_is_recipient_only_marks_read_redirects_with_fragment_and_reauthorizes` verifies recipient-only access, target reauthorization, read state, and comment fragment redirect.
- `UniversalCommentNotificationTest::test_notification_service_skips_inactive_and_inaccessible_recipients` verifies inaccessible recipients receive no notification.

## Round 2 findings and fixes

- Coordinator `actionableLeads`, `failedSyncLeads`, detail week items, and backlog now pass through shared projection. Views handle distant summary arrays without detail routes or mutation controls.
- Lead projection uses `latest_activity_at`, latest status-history `changed_at`, then current-status timestamp; never prefers `updated_at` when activity history exists.
- Agenda receipt summary now exposes `late_activity_count` for all late activities on owner/date. Collection projection caches hierarchy distance and owner/date receipt summaries.
- Coordinator and Supervisor exports keep summary values under matching summary headers; distant summaries do not occupy phone, notes, sync, result, or location columns.
- Added BR-51 regression coverage for projection source, late-count aggregation, Coordinator distant consumers, and existing export contracts.

## Full-suite result

- No parent-branch before/after comparison was captured. Do not label current failures baseline.
- `php artisan test --compact` — 1,883 tests, 13,419 assertions, 8 failures, 0 errors. Exit 1.
- Failures remain limited to Sales personal workspace markup/evidence tests. No parent-branch before/after comparison exists; cause remains unclassified, not baseline.
- Exact failures:
  - `Tests\Feature\SalesAgendaEvidenceTest::test_agenda_records_and_evidence_are_preserved_in_the_direct_workspace`
  - `Tests\Feature\SalesAgendaOnlyWorkspaceTest::test_sales_workspace_renders_receipt_date_context_and_add_plan_action`
  - `Tests\Feature\SalesAgendaWorkspaceInteractionMarkupTest::test_mobile_modal_transitions_cover_shared_modal_and_agenda_sheet`
  - `Tests\Feature\SalesAgendaWorkspaceInteractionMarkupTest::test_draft_receipt_exposes_edit_delete_actions_and_submitted_receipt_hides_them`
  - `Tests\Feature\SalesAgendaWorkspaceRenderTest::test_receipt_heading_matches_today_tomorrow_and_other_planned_dates`
  - `Tests\Feature\SalesAgendaWorkspaceRenderTest::test_existing_draft_shows_a_resume_action_and_resume_query_opens_receipt`
  - `Tests\Feature\SalesAgendaWorkspaceRenderTest::test_populated_receipt_keeps_weekly_navigation_with_exact_date_links`
  - `Tests\Feature\SalesAgendaWorkspaceRenderTest::test_today_navigation_marks_only_the_selected_week_day_as_active`
