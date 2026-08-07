# OASIS Buku Saku Sales 2.1 Multi-Branch Lifecycle Audit

This document records the verified implementation delivered by commits `651619e`, `8d36681`, `b646941`, `3ec058e`, `3e230bb`, and `59817f7`. Executable code, migrations, database constraints, and each branch's configured spreadsheet remain authoritative. The audited Solo workbook is a schema reference, never a production fallback.

## 1. Implemented Scope

Buku Saku Sales 2.1 adds a branch-scoped lead lifecycle alongside the preserved six legacy stage timestamps. It implements:

- canonical lead status and status history;
- linked site-visit, consumer/NUP, SLIK, freelance, and Akad records;
- project-level NUP capability;
- branch workbook contract resolution and stable metadata writes;
- immediate lead and lifecycle spreadsheet writes;
- pull synchronization, capability reporting, and reconciliation-item generation;
- scoped sync/status/reconciliation routes, permissions, command, and schedule;
- lifecycle controls and details in the existing Buku Saku Sales workspace;
- one consolidated OASIS Changelog entry.

The existing `contacted_at`, `met_at`, `surveyed_at`, `utj_at`, `documents_completed_at`, and `akad_at` columns, their stage controls, reversal behavior, reports, and drilldowns remain. The canonical lifecycle does not replace that legacy stage model. Pull sync writes `akad_at` from a valid linked Akad date when it is currently null; other new lifecycle operations do not populate the legacy timestamps.

Database sync and Konsumen Progress sync remain independent. Buku Saku Sales lifecycle has its own services, status table, routes, permissions, command, and lock namespace.

## 2. Durable Model

### `lead_master`

`is_nup_eligible` is a boolean project capability with default `false`. It selects the consumer operation destination:

- `false`: `data_konsumen`, then canonical status `utj`;
- `true`: `data_konsumen_nup`, with no UTJ transition and no `consumer_converted_at` update.

The flag is not inferred from workbook tabs or project text. Existing projects are effectively backfilled by the database default.

### `sales_leads`

The lifecycle additions are:

| Group | Fields |
|---|---|
| External lead data | `external_lead_id`, `external_sync_id`, `id_promo`, `source`, `platform`, `campaign_id`, `campaign_name` |
| Canonical status | `current_status`, `current_status_changed_at`, `current_status_source`, `current_status_source_id` |
| Conversion markers | `consumer_converted_at`, `freelance_converted_at` |
| External references | `consumer_external_id`, `freelance_external_id`, `slik_external_id`, `akad_external_id` |

`current_status` defaults to `no_response` and is cast to `SalesLeadStatus`. Both `external_lead_id` and `external_sync_id` are unique only within a branch. A synchronized lead cannot be moved to another branch.

### Lifecycle tables

| Table | Purpose and important identity |
|---|---|
| `sales_lead_status_histories` | Append-style status evidence with branch, actor, source/source ID, operation UUID, event time, and allowlisted metadata. Unique by `branch_id + operation_uuid` and by `sales_lead_id + source + source_id + status`. |
| `sales_lead_site_visits` | Multiple complete or incomplete visits, including date, time bucket, result, notes, completion flag, sheet row, and sync UUID. |
| `sales_lead_consumer_links` | Normal or NUP consumer link, NIK, kavling, payload snapshot, conversion time, and sheet identity. Each non-null NIK is unique per branch and sheet type at the database boundary; service validation prevents reuse by another lead while allowing one lead to progress from NUP to normal consumer. |
| `sales_lead_slik_attempts` | Consumer-linked SLIK submissions/results, attempt number, NIK, kavling, SLIK date, rejection time, and sheet identity. |
| `sales_lead_freelance_links` | Freelance conversion plus resolved coordinator, `OJT` Sales identity, source NIK/name values, and sheet identity. |
| `sales_lead_akad_links` | Pull-synchronized Akad evidence linked to a consumer, with optional SLIK link, kavling, Akad reference/date, and source metadata. |
| `sales_lead_lifecycle_sync_statuses` | One row per branch: state, operation UUID, message, summary, timing, last success, and initiator. |
| `sales_lead_lifecycle_reconciliation_items` | Branch-scoped open/resolved issues keyed by entity type, identity key, and issue code. |

Each operation table uses branch-scoped uniqueness for `operation_uuid`, `oasis_sync_id`, and `sheet_name + remote_row_number`. Row number is retained as location metadata, not used as the durable write identity.

## 3. Status Ownership and Precedence

Canonical statuses are:

| Status | Owner/evidence |
|---|---|
| `no_response` | Manual |
| `discussion` | Manual |
| `site_visit` | Manual status or site-visit operation |
| `utj` | Normal consumer conversion or linked normal-consumer pull evidence |
| `slik_check` | SLIK submission or linked SLIK pull evidence |
| `slik_rejected` | Explicit SLIK rejection or linked SLIK evidence |
| `akad` | Valid linked Akad pull evidence |
| `freelance` | Independent conversion flag/history; not part of primary precedence |

Primary precedence is exactly:

```text
no_response < discussion < site_visit < utj < slik_check < slik_rejected < akad
```

Manual forms and the lifecycle-status endpoint accept only `no_response`, `discussion`, and `site_visit`. Manual statuses may move among those three, including back to `no_response`, but once the current status is system-owned it is read-only in lead forms. Forged system statuses are rejected.

System transitions are monotonic according to primary precedence. Pull sync records lower/equal observations in history but does not downgrade `current_status`. `freelance` coexists with the primary status: it sets `freelance_converted_at`, external identity, and history without replacing the primary status. When appropriate, `lead.status_lead` is still written as `Jadi Freelance`; the local primary status remains unchanged.

`Cek Silk`, `Cek Slik`, and `Cek SLIK` all ingest as `slik_check`. OASIS displays `Cek SLIK`; writes use the exact alias present in the selected branch spreadsheet's strict dropdown validation.

### NUP and UTJ boundary

NUP conversion writes `data_konsumen_nup` only. It does **not** set `utj`, `utj_at`, `consumer_converted_at`, or the normal consumer reference. A normal `data_konsumen` conversion sets canonical `utj` and the consumer conversion/reference fields, but does not set legacy `utj_at`.

A `data_ceklok.status_ceklok` value of `utj` is only a visit outcome. It sets/retains canonical `site_visit`; it does **not** set canonical `utj` or legacy `utj_at`.

## 4. Branch Spreadsheet Contract

All lifecycle writes resolve the workbook only through:

```text
sales_lead.branch_id -> branches.id -> branches.sheet_id
```

The resolver rejects inactive/missing branches, blank `sheet_id`, unknown/missing tabs, missing or reordered required headers, missing required row-two formulas, and incompatible validation metadata. It never falls back to Solo, another branch, project names, users, or workbook titles.

The write contract registers these exact tabs:

- `lead`;
- `data_ceklok`;
- `data_sales`;
- `data_konsumen_nup`;
- `data_konsumen`;
- `bi_checking`;
- `akad`.

The resolver accepts extra branch columns while requiring the registered headers in order. Formula-owned fields are detected from row two and are never replaced by submitted values. The writer copies row-two format and formulas to appended rows where formula ownership exists.

### Metadata and idempotency

Writable tabs use exact trailing metadata headers:

```text
oasis_sync_id, oasis_deleted_at, oasis_deleted_by
```

If none exist, the writer appends, re-reads, verifies, and hides exactly those new trailing columns. If metadata is partial, reordered, or not trailing, the operation fails closed. Existing unrelated hidden columns are not hidden or removed by this writer.

Every append requires a UUID operation ID. Before appending, and again after an uncertain append failure, the writer searches `oasis_sync_id`. A retry returns the existing row rather than appending a duplicate. Updates first resolve the current row by `oasis_sync_id`; stored row numbers are not trusted after row movement. Formula-owned, metadata, unknown, and unsupported fields are ignored by the write mapper.

Lead creation generates `external_sync_id` before the remote append, writes the `lead` row, then re-reads the generated `id_lead` and stores it as `external_lead_id`. A remote failure rolls back the local transaction and does not claim success. Lead updates write the remote row before the local update.

## 5. Implemented Operations and Field Mapping

| Operation | Spreadsheet mutation | Local result |
|---|---|---|
| Create/update lead | Append/update `lead` by `external_sync_id` | Stores generated `id_lead`; updates canonical/manual status history. |
| Set manual status | Update `lead.status_lead` when synchronized | Updates canonical status/history. |
| Complete site visit | Append `data_ceklok` | Stores a completed visit and advances to at least `site_visit`. |
| `Isi Nanti` site visit | No `data_ceklok` append | Stores an incomplete local visit and advances to at least `site_visit`. |
| Convert normal consumer | Append `data_konsumen` | Stores completed consumer link and advances to `utj`. |
| Convert NUP consumer | Append `data_konsumen_nup` | Stores completed NUP link without UTJ. |
| Submit SLIK | Append `bi_checking` from the linked normal consumer | Stores active `submitted` attempt and advances to `slik_check`. |
| Reject SLIK | Update existing `bi_checking` row by sync UUID | Stores result/reason and advances to `slik_rejected`. |
| Convert freelance | Append `data_sales` | Stores independent freelance link/flag and status history. |
| Akad | No web mutation | Pull sync mirrors valid `akad` rows and advances to `akad`. |

### Lead fields

OASIS writes `tanggal_lead`, `sumber_lead`, `kanal_masuk`, `aktivitas_lead`, `nama_konsumen`, `no_hp`, `proyek`, `sales_pic`, `status_lead`, `keterangan`, and `id_promo`. `id_lead` remains formula-owned. When the detailed sheet source is blank, OASIS derives it from the OASIS lead-source snapshot; `Iklan Pusat` maps to legacy `Lead Cabang`.

### Lead spreadsheet headers versus internal fields

The spreadsheet contract is authoritative and uses the physical lead headers directly. The canonical `lead` tab requires exactly `id_lead, id_promo, tanggal_lead, sumber_lead, kanal_masuk, aktivitas_lead, nama_konsumen, no_hp, proyek, sales_pic, status_lead, keterangan` (plus optional trailing helper columns and OASIS metadata). OASIS never requires or writes internal names such as `source`, `platform`, or `campaign_name` in the workbook.

Mapping happens only at the OASIS boundary, in both directions:

| Spreadsheet header | Internal OASIS field |
|---|---|
| `sumber_lead` | `source` |
| `kanal_masuk` | `platform` |
| `aktivitas_lead` | `campaign_name` |

- Write: `SalesLeadService::spreadsheetFields()` emits `sumber_lead` = effective source, `kanal_masuk` = platform, `aktivitas_lead` = campaign name (falling back to campaign ID). Form fields and the option service keep the internal keys (`source`/`platform`/`campaignName`, option keys `source`/`channel`/`activity`).
- Pull: `SalesLeadLifecycleSyncService` reads `sumber_lead`/`kanal_masuk`/`aktivitas_lead` into `source`/`platform`/`campaign_name` and requires those physical headers for the `lead` tab, so missing columns fail the run instead of silently nulling the source.
- Legacy aliases `sumber`, `kanal`, and `campaign` remain accepted on pull as header aliases for `sumber_lead`, `kanal_masuk`, and `aktivitas_lead` respectively. Error messages reference the physical headers only.
- Workbooks that also reuse `sumber_lead`/`kanal_masuk`/`aktivitas_lead` in trailing helper dropdown lists (for example Magelang's `lead` helper columns) are accepted on pull: only the first occurrence of a header is read as data, and later duplicates are ignored.

### Site visit fields

OASIS supplies `tanggal_ceklok`, `waktu_ceklok`, `status_ceklok`, and `keterangan`; `nama_konsumen` remains formula-owned and is not overwritten. Complete visits require date, one of `pagi|siang|sore|malam`, and one of `follow up|non ok|utj`. Multiple visits are allowed and operation UUID makes retries idempotent.

### Consumer fields

Both consumer flows require a 16-digit, non-placeholder NIK and preserve leading zeroes. Normal consumer additionally requires `id_kavling`. Shared fields include customer name and lead phone; optional address, work, contact, date, cash, NUP, and notes fields are limited to headers supported by the selected tab. Duplicate completed NIK in the same branch is rejected.

### SLIK fields

SLIK requires a completed normal consumer with NIK and kavling. Submission writes `id_kavling`, `no_ktp`, `tanggal_slik`, and `keterangan`; a second active `submitted` attempt is blocked. Rejection updates `hasil_slik` and required `keterangan` on the stable row. Accepted rejection results are `KOL 1` through `KOL 5` and `NO BIC`.

### Freelance and coordinator behavior

Freelance writes `nik_sales=OJT`, `nama_sales` from the lead's customer name, the submitted coordinator NIK, and the resolved coordinator's OASIS name. The Sales user's active, branch/project-accessible `supervisor_user_id` is mandatory when valid. A different submitted coordinator is rejected. Only when no valid active supervisor is available may the user select a fallback coordinator, who must also be active and have access to the lead branch and project. Spreadsheet NIK values never update OASIS user identity or reporting hierarchy.

## 6. Pull Sync and Reconciliation

`SalesLeadLifecycleSyncService` reads a complete branch workbook response before changing lifecycle records and runs branch reconciliation inside a database transaction under `SyncLockService` key `sales-lead-lifecycle:branch:{id}`.

`lead` is mandatory. Its missing tab or invalid required headers fail the run. Missing or invalid optional lifecycle tabs become disabled capabilities and open reconciliation items rather than empty successful sources.

Lead matching is branch-isolated:

1. `external_sync_id` from `oasis_sync_id`;
2. branch-local `external_lead_id` from `id_lead`;
3. conflict if those identities point to different leads.

New/imported lead rows require a unique active project name in the branch and a unique active Sales name assigned to that project within the current assignment window. Existing project/Sales ownership is not silently changed; mismatches become reconciliation items.

Consumer, SLIK, Akad, freelance, and visit rows prefer branch-scoped sync UUIDs. Limited fallback matching uses existing confirmed local links and unique branch-local kavling relationships. Ambiguous, missing, unknown-status, invalid-date, unsupported-capability, and assignment cases remain open items. Source deletion does not delete local leads or lifecycle records.

Before each successful reconciliation pass, prior open branch items are marked resolved; recurring issues are reopened through `updateOrCreate`. The summary records `imported`, `updated`, `linked`, `unresolved`, and per-tab capabilities. The status row records success/failure timing and message.

The reconciliation endpoint is **list-only JSON** with optional status filtering and 50-row pagination. There is no endpoint or UI operation to manually mutate, resolve, remap, or override a reconciliation item.

### Lead sync vs. downstream lifecycle sync (separation)

Buku Saku Sales is intended for Sales lead input and monitoring. Each branch shares one `lead` tab; `sales_pic` distinguishes rows. A normal Sales sync must therefore reflect **only** the shared `lead` tab and lead-specific reconciliation, never the downstream lifecycle.

- `SalesLeadSyncService` (`scope = lead`) powers the standard sync button and the `sales-lead:sync` command. It reads only the `lead` tab, validates only the lead contract, imports/updates/links lead rows, maps `sales_pic → sales_user` and `proyek → project`, and writes a `scope = lead` status row.
- `SalesLeadLifecycleSyncService` (`scope = lifecycle`) keeps the full seven-sheet pull (`lead`, `data_konsumen`, `data_konsumen_nup`, `bi_checking`, `akad`, `data_sales`, `data_ceklok`) for downstream reconciliation and the `sales-lead-lifecycle:sync` command/schedule.
- Status truth for the Buku Saku button comes from the lead-scoped status row. SUCCESS means the lead contract is valid, lead read succeeded, and lead rows processed without lead reconciliation; PARTIAL is lead reconciliation only; FAILED is spreadsheet/API/lead-tab/header/lead failures. Downstream `consumer_link_unconfirmed`, SLIK, Akad, NUP, and data-sheet issues never color the lead status and never appear as "perlu diperiksa" for Sales.

Reconciliation is separated by `entity_type`/`issue_code`: lead items (`lead` / `lead_status`; codes `lead_id_missing`, `lead_id_ambiguous`, `project_not_found`, `project_ambiguous`, `sales_not_found`, `sales_ambiguous`, `lead_data_invalid`, `lead_identity_conflict`, `lead_assignment_conflict`, `status_unknown`) count toward the Buku sync; downstream items are lifecycle-only. The reconciliation endpoint accepts `scope=lead` to list only lead items and `scope=lifecycle` for downstream.

Historical or unmapped `sales_pic` rows (for example the Odi Damara history) are never auto- or fuzzy-assigned. They either match an explicit `SalesSheetIdentity` mapping or an exact normalized match to an active Sales assigned to the project in the current window; otherwise they become lead reconciliation items and are not exposed to another Sales. Project identity uses `sheet_project_name` when present (for example internal Jonggrangan = sheet Marison Kalinegoro) before falling back to `project_name`.

`sales_lead_lifecycle_sync_statuses` gained a `scope` column (`lead` default); the branch-unique index is now `(branch_id, scope)`.

## 7. Routes, Permissions, Command, and Schedule

Lifecycle operation routes retain the main protected CRM middleware and authorize each lead through `SalesLeadPolicy::update`-equivalent abilities:

- `sales-leads.lifecycle-status.update`;
- `sales-leads.site-visits.store`;
- `sales-leads.consumer.store`;
- `sales-leads.slik.store`;
- `sales-leads.slik.reject`;
- `sales-leads.freelance.store`.

Sync routes are:

- `POST /buku-saku-sales/lifecycle-sync`, permission `sales_pocketbook.sync`;
- `GET /buku-saku-sales/lifecycle-sync/status`, permission `sales_pocketbook.sync`;
- `GET /buku-saku-sales/lifecycle-reconciliations`, permission `sales_pocketbook.reconcile`.

In addition to the registered permission, sync/status/reconciliation require the requested active branch to be in the user's `sales_pocketbook.manage` organization scope, viewable through `WorkspaceAccessService`, and sync-enabled through `canSyncBranch()`. Explicit inaccessible branches return denial, not fallback.

The migration maps both new permissions to primary `supervisor`, `manager`, `branch_manager`, `pusat`, and legacy `admin` roles. Superadmin receives registered-permission wildcard behavior. They are not mapped to `sales`, `sales_coordinator`, or `staff`, and supplemental roles do not grant them.

The command is:

```text
php artisan sales-lead-lifecycle:sync --branch=ID
```

Without `--branch`, it processes every active branch with a nonblank `sheet_id`, continues after individual failures, and exits failure when any branch fails. It is scheduled every ten minutes with a 30-minute overlap lock as `sales-lead-lifecycle-sync`.

## 8. UI and Export

The existing Buku Saku Sales workspace now includes:

- lifecycle sync status/control and open reconciliation count for authorized users;
- lead status badges and lifecycle actions on each lead card;
- canonical modals for site visit, consumer/NUP, SLIK, SLIK rejection, freelance, and read-only linked details;
- manual status selection on create/edit only for the three manual statuses;
- read-only display for a current system-owned status;
- explicit NUP wording that it does not set UTJ;
- a post-create/post-edit `site_visit` flow that opens the site-visit modal;
- source-sheet, platform, campaign, promo ID, branch/Sales, and sync-identity visibility.

The create/edit forms distinguish the required OASIS lead-source category from `Sumber (Sheet)`. The lead export appends `External Sync ID`, `Sumber (Sheet)`, `Platform`, `Campaign`, `Siklus Saat Ini`, and `Freelance` columns without removing the legacy stage columns.

The reconciliation link currently navigates to JSON, not a rendered management screen. UI source-contract tests verify modal/status text and prohibit new `alert()`/`confirm()` use in this lifecycle surface, but no browser session was run for this audit.

## 9. Migration, Backfill, and Rollback

Migrations `000004` through `000013` add the model fields/tables, operation fields, sync identity, permissions, NUP-to-normal uniqueness correction, and one changelog entry.

The status backfill reads only existing `surveyed_at`, `utj_at`, and `akad_at`, in that order. The highest applicable status wins and each source becomes an idempotent `legacy_timestamp` history row. `contacted_at`, `met_at`, and `documents_completed_at` do not affect canonical backfill. Existing legacy timestamps are not changed.

Rollback behavior is explicit but production-sensitive:

- `000013` restores the earlier branch-wide NIK uniqueness boundary and therefore must not be rolled back after NUP-to-normal progression exists;
- `000012` removes only its exact Changelog entry;
- `000011` removes the two lifecycle permissions;
- `000010` removes `external_sync_id` and its branch uniqueness;
- `000008` removes operation-specific fields and constraints;
- `000007` deletes `legacy_timestamp` histories and resets every canonical status/source field to `no_response`/null;
- `000006`, `000005`, and `000004` drop reconciliation/status tables, lifecycle tables, and lead/project lifecycle fields.

Do not casually run these down migrations after production writes or pull sync. They discard lifecycle history/linkage and cannot undo appended spreadsheet rows or metadata columns. Operational rollback is to stop the scheduler and lifecycle writes first, preserve local evidence, and remediate forward. There is no feature flag or automated workbook restore. `sheet:cleanup-meta` is not a targeted lifecycle rollback because it removes every exact OASIS metadata header it finds.

## 10. Verification and Known Limitations

Focused tests cover status ownership/precedence, backfill, branch uniqueness, contract drift, formula and metadata protection, idempotent append retry, stable-row update, operation validation and rollback, NUP/UTJ separation, coordinator resolution, scoped permissions, command continuation, branch-isolated pull reconciliation, UI source contracts, export additions, and changelog rendering.

Known limitations:

- JPR currently lacks `data_ceklok`; sync reports that capability unavailable and site-visit writes for that branch fail closed rather than using another workbook or synthesizing the tab.
- The writer may provision and hide metadata columns during a valid live operation; there is no separate dry-run/approval UI for metadata provisioning.
- Pull sync can import/update lead identity/contact fields from `lead`; it is not an observation-only cache.
- Reconciliation matching without `oasis_sync_id` is intentionally limited and can leave rows unresolved; there is no manual mutation endpoint to complete those links.
- `data_sales` pull reconciliation confirms only rows already carrying a known local freelance sync UUID. It does not infer OASIS users from NIK or names.
- `data_ceklok` pull reconciliation confirms only known visit sync UUIDs. It does not link historical visits by customer name.
- NUP rows do not automatically promote to normal consumers or UTJ.
- Akad is pull-only in the web lifecycle; there is no Akad mutation form.
- Source row absence does not delete local data, and `oasis_deleted_at`/`oasis_deleted_by` are provisioned metadata but deletion flows are not implemented here.
- No live spreadsheet mutation was executed while verifying these commits. Contract/writer behavior was verified with mocks and source tests only.
- No browser or responsive visual verification was performed for the lifecycle UI.

The original 2.1 release has one user-facing Changelog entry from `2026_08_03_000012_add_sales_lead_lifecycle_ui_changelog.php`: `Siklus Lead Buku Saku Terhubung`. The later strict-dropdown compatibility fix adds `Status Lead Spreadsheet Lebih Kompatibel` through `2026_08_03_000014_add_sales_lead_status_alias_fix_changelog.php`. The permissions migration creates no Changelog entry.
