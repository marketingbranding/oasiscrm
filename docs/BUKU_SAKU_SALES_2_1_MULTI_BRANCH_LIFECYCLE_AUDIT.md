# OASIS Buku Saku Sales 2.1 Multi-Branch Lifecycle Audit

This document records the verified current state and the approved implementation contract for the multi-branch Buku Saku Sales lifecycle integration. This audit commit is documentation only; the subsequent implementation commits add the routes, database changes, synchronization, spreadsheet writes, permissions, and UI behavior.

`AGENTS.md`, executable application code, database constraints, and each branch's configured spreadsheet remain authoritative. The Solo workbook is a reference specimen, not a production default.

## 1. Purpose and Decision Boundary

The intended 2.1 outcome is a branch-scoped lifecycle that can reconcile OASIS sales leads with the relevant operational tabs in each branch workbook without replacing the existing Buku Saku Sales lead model or silently standardizing unlike workbooks.

This proposal covers:

- branch and spreadsheet resolution;
- the verified Solo reference schema;
- the verified Jepara differences;
- lifecycle event interpretation;
- stable cross-system identity;
- field and status ownership;
- deterministic reconciliation and conflict precedence;
- migration, backfill, authorization, observability, rollback, and acceptance requirements.

The task specification authorizes the implementation described here. Risky workbook mutations remain fail-closed and require the branch contract checks, stable identity, and backup/reconciliation safeguards defined below.

## 2. Current OASIS Baseline

`sales_leads` is the current durable Buku Saku Sales lead store. Each row belongs to one branch, one project, and one Sales user. It also stores the lead date, customer identity fields, lead source, notes, optional manual consumer reference, creator/updater references, and normal Laravel timestamps.

The current lifecycle is represented by exactly six nullable timestamps, in order:

| Order | Column | Current label |
|---|---|---|
| 1 | `contacted_at` | `DIHUBUNGI` |
| 2 | `met_at` | `TATAP MUKA` |
| 3 | `surveyed_at` | `SURVEY` |
| 4 | `utj_at` | `UTJ` |
| 5 | `documents_completed_at` | `BERKAS AWAL LENGKAP` |
| 6 | `akad_at` | `AKAD` |

The latest non-null timestamp determines the displayed current stage. Reversing a stage clears that timestamp and every later timestamp. Stage writes are optimistic-lock protected and activity logged.

### Preservation requirement

The six columns, their order, labels, reversal semantics, report use, drilldowns, and exports must be preserved. A spreadsheet lifecycle import may supply evidence or propose a missing timestamp, but it must not rename, collapse, reinterpret, or overwrite an existing timestamp without an approved field-level rule.

The current `linked_consumer_reference` is a manual string, not a stable foreign key. Names, phone numbers, row numbers, and replaceable Konsumen Progress cache IDs must not be promoted to durable identities.

## 3. Unchanged Product Contracts

The following behavior remains unchanged unless a later implementation scope explicitly says otherwise:

- the current Buku Saku Sales routes and route names;
- primary-role permission resolution and the primary Sales `sales.access` restriction;
- `SalesLeadPolicy`, organization scope, branch/project assignment windows, and explicit denial of inaccessible filters;
- lead creation, update, duplicate-phone warning, stage set/reverse, comments, mentions, presence, notifications, and optimistic conflict handling;
- the independent 20-row lead and agenda paginators;
- Sales Agenda ownership, subtype, completion, missing-result, and reschedule behavior;
- weekly/custom reports, event-count metrics, conversion calculations, drilldowns, sorting, XLSX output, and all-time `last_input` behavior;
- the daily Sales reminder and its dismissal/suppression rules;
- the six legacy lifecycle timestamps and all historical values already stored in them.

Legacy reports must continue to read the same timestamp columns with the same period semantics. Backfill must not rewrite report history merely to make spreadsheet status totals agree.

Database sync and Konsumen Progress sync remain separate systems with separate routes, caches, completeness rules, and status tables. Buku Saku Sales 2.1 must not merge them, route through them implicitly, or attach durable lead identity to `KonsumenProgressSheetRow` IDs.

## 4. Spreadsheet Resolution and Reference Safety

The only production spreadsheet resolution rule is:

```text
sales_lead.branch_id -> branches.id -> branches.sheet_id
```

Requirements:

- resolve the branch first through current access and organization scope;
- use that exact branch's nonblank `branches.sheet_id`;
- fail closed when the branch is inactive, inaccessible, missing, or lacks `sheet_id`;
- never substitute another branch's workbook;
- never infer the workbook from a project name, Sales name, workbook title, or a tab value;
- never use a hard-coded spreadsheet ID in application code, configuration fallback, command default, test fixture that can reach production, or recovery path.

The audited Solo spreadsheet ID is reference-only. It must never become a production fallback. This document intentionally does not reproduce that ID.

## 5. Solo Reference Workbook Scope

Solo is the schema reference because all seven audited lifecycle tabs exist there:

- `lead`;
- `data_ceklok`;
- `data_sales`;
- `data_konsumen_nup`;
- `data_konsumen`;
- `bi_checking`;
- `akad`.

The reference is descriptive, not universal. Exact tab names and headers are case-sensitive integration inputs. A branch workbook may be incompatible or partially compatible; the sync must report that state rather than silently reading a similarly named tab.

The Solo audit found no hidden OASIS metadata columns on these tabs. Existing spreadsheet row positions and generated business IDs therefore cannot yet provide a stable OASIS identity.

## 6. Exact Solo Headers

The audited row-one headers, in exact order, are:

| Tab | Columns | Exact headers |
|---|---:|---|
| `lead` | 12 | `id_lead`, `id_promo`, `tanggal_lead`, `sumber`, `platform`, `campaign`, `nama_konsumen`, `no_hp`, `proyek`, `sales_pic`, `status_lead`, `keterangan` |
| `data_ceklok` | 5 | `nama_konsumen`, `tanggal_ceklok`, `waktu_ceklok`, `status_ceklok`, `keterangan` |
| `data_sales` | 4 | `nik_sales`, `nama_sales`, `nik_koordinator`, `nama_koordinator` |
| `data_konsumen_nup` | 14 | `nup`, `no_ktp`, `nama_konsumen`, `tanggal_lahir`, `pekerjaan`, `umur`, `alamat`, `kelurahan`, `kecamatan`, `kabupaten/kota`, `no_hp`, `nama_kondar`, `no_hp_kondar`, `keterangan` |
| `data_konsumen` | 18 | `id_kavling`, `no_ktp`, `nama_konsumen`, `tanggal_lahir`, `pekerjaan`, `detail_pekerjaan`, `umur`, `alamat`, `kelurahan`, `kecamatan`, `kabupaten/kota`, `no_hp`, `nama_kondar`, `no_hp_kondar`, `status_cash`, `status_konsumen`, `Status`, `keterangan` |
| `bi_checking` | 6 | `id_kavling`, `no_ktp`, `id_kons`, `tanggal_slik`, `hasil_slik`, `keterangan` |
| `akad` | 9 | `id_kavling`, `id_ppjb_dev`, `no_ppjb_akad`, `tanggal_akad`, `kualitas_akad`, `lead_time_hari`, `status`, `keterangan_terlambat`, `keterangan` |

Header normalization may be used only to produce a diagnostic. Reads and writes must validate the exact expected contract before mutation. Duplicate, blank, moved, or renamed required headers are schema drift, not an invitation to guess.

## 7. Reference Formulas and Validations

The following formulas describe the audited Solo row-two/reference behavior. Relative row references advance for later rows unless the formula is an array formula.

### `lead`

- `id_lead` is formula-owned. It returns blank when `nama_konsumen` is blank; otherwise it combines `tanggal_lead` as `yymmdd`, initials derived from `sumber` and `platform`, and a two-digit row sequence.
- Exact reference formula:

```gs
=IF(G2="","",
TEXT(C2,"yymmdd")&"-"&
IFERROR(LEFT(UPPER(D2),1)&MID(UPPER(D2),FIND(" ",D2)+1,1),LEFT(UPPER(D2),2))
&"-"&
IFERROR(LEFT(UPPER(E2),1)&MID(UPPER(E2),FIND(" ",E2)+1,1),LEFT(UPPER(E2),2))
&"-"&TEXT(ROW()-1,"00"))
```

- Strict dropdowns exist for `id_promo`, `sumber`, `sales_pic`, and `status_lead`.
- `tanggal_lead` is date-validated.
- The `id_promo` and `sales_pic` option sets are workbook data and must be read from the branch workbook; they must not be copied from Solo into another branch.
- Audited static `sumber` values are `Canvasing`, `Event`, `Freelance`, `Lead Cabang`, `Online`, `Pameran`, and `Refferal`. Spelling is source data and must not be silently corrected during raw ingestion.

### `data_ceklok`

- `nama_konsumen` is formula-owned by a spill filter:

```gs
=FILTER(lead!G2:G,LOWER(TRIM(lead!K2:K))="cek lokasi")
```

- `tanggal_ceklok` is date-validated.
- `waktu_ceklok` is a strict dropdown with `malam`, `pagi`, `siang`, and `sore`.
- `status_ceklok` is a strict dropdown with `follow up`, `non ok`, and `utj`.

### `data_sales`

- No audited formula or validation was found in the reference rows.
- NIK and name columns are manually maintained reference data.

### `data_konsumen_nup`

- `tanggal_lahir` is date-validated.
- `umur` is formula-owned:

```gs
=IF(D2="", "", YEAR(TODAY())-RIGHT(D2,4) & " tahun")
```

### `data_konsumen`

- `id_kavling` is a strict branch-workbook dropdown. Its current options are operational data, not a portable Solo enum.
- `tanggal_lahir` is date-validated.
- `status_cash` is a strict dropdown with `YA` and `TIDAK`.
- `umur` uses the same formula shape as `data_konsumen_nup`.
- `status_konsumen` is formula-owned. It returns blank for a blank `id_kavling`, returns `Mundur` when `keterangan` contains that term, otherwise reads the latest matching `proses_bank` response and maps `APPROVED`, `APPROVED TENOR`, or `APPROVED TURUN PLAFOND` to `Lanjut`; `REJECT` to `Reject`; `REVISI` to `Revisi`; and `CASH` to `Cash`.
- Exact reference formula shape:

```gs
=IF(A2="","",IF(REGEXMATCH(UPPER(R2),"MUNDUR"),"Mundur",
LET(respon,IFERROR(XLOOKUP(A2,proses_bank!$A:$A,proses_bank!$D:$D,"",0,-1),""),
SWITCH(UPPER(TRIM(respon)),"APPROVED","Lanjut","APPROVED TENOR","Lanjut",
"APPROVED TURUN PLAFOND","Lanjut","REJECT","Reject","REVISI","Revisi",
"CASH","Cash",""))))
```

- `Status` is formula-owned completeness output:

```gs
=IF(A2:A="", "", IF(COUNTA(B2:O2)=14, "Data Lengkap", "Data Belum Lengkap"))
```

### `bi_checking`

- `id_kavling` is a strict branch-workbook dropdown; options are operational data.
- `no_ktp` is formula-owned. It selects a complete matching consumer occurrence from `data_konsumen` or returns `Data Belum Lengkap`.
- `id_kons` is formula-owned from `tanggal_slik`, normalized `hasil_slik`, the final segment of `id_kavling`, and a duplicate sequence:

```gs
=IF(COUNTA(A2,B2,D2,E2)<4,"",
LET(x,TEXT(D2,"yymmdd")&"-"&LEFT(SUBSTITUTE(UPPER(E2)," ",""),3)&"-"&REGEXEXTRACT(A2,"[^- ]+$"),
x&"-"&TEXT(COUNTIFS($A$2:A2,A2,$D$2:D2,D2,$E$2:E2,E2),"00")))
```

- `tanggal_slik` is date-validated. The audit also found date formatting/validation metadata on `no_ktp`; because that conflicts with its identifier meaning, implementation must preserve raw values and treat this as workbook metadata drift rather than converting KTP identifiers to dates.
- `hasil_slik` is a strict dropdown with `OK`, `KOL 1`, `KOL 2`, `KOL 3`, `KOL 4`, `KOL 5`, and `NO BIC`.

### `akad`

- `id_kavling` is a strict branch-workbook dropdown; options are operational data.
- `id_ppjb_dev` is formula-owned by occurrence-aware lookup into `ppjb_dev`, returning `ID Tidak Ditemukan` when no result exists.
- `no_ppjb_akad` is formula-owned from `tanggal_akad`, normalized `kualitas_akad`, the final segment of `id_kavling`, and a duplicate sequence:

```gs
=IF(COUNTA(A2,D2,E2)<3,"",
TEXT(D2,"yymmdd")&"-"&LEFT(SUBSTITUTE(UPPER(E2)," ",""),2)&"-"&
REGEXEXTRACT(A2,"[^- ]+$")&"-"&TEXT(COUNTIFS($A$2:A2,A2,$D$2:D2,D2,$E$2:E2,E2),"00"))
```

- `tanggal_akad` is date-validated. The audit also found date metadata on `no_ppjb_akad`; it must remain an identifier string.
- `kualitas_akad` is a strict dropdown with `Akad Bangunan Belum Jadi`, `Akad DP Belum Lunas`, `Akad KLT Belum Lunas`, and `Akad Sempurna`.
- `status` is formula-owned:

```gs
=IF(F2:F="","",IF(F2:F>3,"terlambat","ontime"))
```

Formula text is not a cross-branch identity and must not be rewritten merely because another branch uses a different but valid formula.

## 8. Status Vocabulary and Alias Handling

The exact audited `lead.status_lead` values are:

| Raw value | Canonical ingestion value | Interpretation boundary |
|---|---|---|
| `No Respon` | `no_response` | manual lead disposition |
| `Diskusi` | `discussion` | manual lead disposition |
| `UTJ` | `utj` | imported status is accepted only when linked consumer evidence exists |
| `Tidak Lolos BI Checking` | `slik_rejected` | system outcome after linked BI Checking update |
| `Akad` | `akad` | system mirror only when linked `akad.tanggal_akad` is populated |
| `Cek Lokasi` | `site_visit` | approved manual status; `data_ceklok` stores visit history |
| `Cek Silk` | `slik_check` | accepted legacy spelling alias for `Cek SLIK` |
| `Jadi Freelance` | `freelance` | system conversion flag after `data_sales` creation |

`Cek Silk` must be retained verbatim as `source_status_raw` for audit and normalized only in the canonical comparison layer. The UI may display `Cek SLIK`, but sync must accept both spellings without producing two statuses. It must not rewrite the source cell solely to correct the alias.

Status normalization is case-insensitive and trims surrounding whitespace. Unknown nonblank statuses are preserved as raw values, reported as unmapped, and excluded from automatic timestamp changes.

## 9. Jepara Comparison and Schema Drift

Jepara demonstrates why Solo cannot be treated as a universal template:

- `data_ceklok` is absent;
- `data_konsumen` has no `status_konsumen` column;
- `data_konsumen` adds `Kolom Bantu` after `keterangan`;
- Jepara `data_konsumen` headers are therefore `id_kavling`, `no_ktp`, `nama_konsumen`, `tanggal_lahir`, `pekerjaan`, `detail_pekerjaan`, `umur`, `alamat`, `kelurahan`, `kecamatan`, `kabupaten/kota`, `no_hp`, `nama_kondar`, `no_hp_kondar`, `status_cash`, `Status`, `keterangan`, `Kolom Bantu`;
- Jepara `Status` uses a different completeness formula and observed row formulas are not internally uniform, so completeness must be read as source output rather than reimplemented from a Solo column count;
- Jepara `akad` adds `nama_sales` and `nama_koordinator` after the nine Solo columns;
- those two columns use array lookup formulas against `PSJB` in the audited workbook;
- Jepara's `no_ppjb_akad` formula format differs from Solo and can return `Data Belum Lengkap`;
- Jepara `akad` zero-based column indexes 8 through 10 (`I:K`) are hidden; this includes `keterangan`, `nama_sales`, and `nama_koordinator` in the audited layout.

The integration must use per-branch capability detection. Missing `data_ceklok` means that source is unavailable for Jepara; it must not be synthesized from Solo or treated as an empty successful tab. Added columns must be preserved. Formula-owned hidden columns must not be exposed as manual inputs.

## 10. Target Lifecycle Model

The target model separates three concepts:

- the preserved six OASIS milestone timestamps;
- source observations from branch tabs;
- a derived lifecycle state used for reconciliation diagnostics.

Recommended source observations are branch-scoped and append/update by stable metadata identity. At minimum they distinguish lead, site-check, NUP, consumer, SLIK, and akad evidence. They must retain the source tab, source raw business ID where present, source status, observed business date, last source hash, and reconciliation state.

The lifecycle must remain monotonic by default: later evidence can fill a missing earlier or same-stage timestamp only under an approved mapping, but it must not erase an existing OASIS timestamp. Reverse-stage remains an explicit authorized OASIS action, not a consequence of a source row disappearing or changing status.

### Milestone evidence boundary

| Existing timestamp | Permitted evidence candidate | Automatic-write position |
|---|---|---|
| `contacted_at` | an explicitly approved `lead.status_lead` mapping | not approved by this audit |
| `met_at` | no exact audited spreadsheet event | preserve existing/manual only until a source is defined |
| `surveyed_at` | first transition to canonical `site_visit` | backfill/compatibility only; visit history remains separate |
| `utj_at` | successful linked `data_konsumen` creation/import | `status_ceklok=utj` explicitly does not set UTJ |
| `documents_completed_at` | verified `data_konsumen.Status=Data Lengkap` | requires proof of the business date; formula output alone has no event timestamp |
| `akad_at` | valid `akad.tanggal_akad` | strongest candidate, but still conflict-protected |

No timestamp may be fabricated from sync time when the business event date is absent. Such evidence remains pending completion.

## 11. NUP Project Strategy

NUP participation is a project capability, not a workbook-wide assumption. The target configuration is an explicit project-level flag such as “uses NUP lifecycle,” with a default of `false` for every existing and new project unless deliberately enabled.

Rules:

- do not infer the flag from the presence of `data_konsumen_nup`;
- do not infer it from an `NUP` substring in free text;
- when false, NUP rows are ignored for lifecycle progression but may be reported as unmatched source observations;
- when true, `data_konsumen_nup` may represent a pre-consumer phase, but promotion still requires an approved stable match to the later consumer record;
- enabling or disabling the flag is configuration, must be permission-gated and audited, and must not delete prior observations;
- disabling after use stops new NUP progression and leaves historical linkage readable.

The flag must be backfilled to `false` so deployment does not change existing project behavior.

## 12. Sales and Coordinator Resolution

`data_sales` contains `nik_sales`, `nama_sales`, `nik_koordinator`, and `nama_koordinator`. OASIS users currently have no NIK field. Therefore NIK cannot be used as an automatic OASIS user key.

The target coordinator relationship is the resolved Sales user's `users.supervisor_user_id`, subject to existing reporting-hierarchy validity. Supplemental roles and spreadsheet names do not grant coordinator scope.

Required completion strategy:

- migration/import preview lists each distinct source Sales and coordinator pair;
- an authorized operator explicitly maps the source Sales identity to an active OASIS Sales user or marks it unresolved;
- `nik_sales` and `nik_koordinator` may be retained as source references, but not written into an unrelated user field;
- after the Sales user is resolved, `supervisor_user_id` is the OASIS coordinator source of truth;
- a mismatch between spreadsheet coordinator and `supervisor_user_id` is a review item, not an automatic hierarchy update;
- exact-name matching may suggest candidates only; it must never confirm a user automatically;
- unresolved or ambiguous mappings block automatic ownership assignment for affected rows.

Because no NIK field exists, completion input is mandatory before affected records can be reconciled automatically.

## 13. Stable Branch-Scoped Identity

Row number is not identity. Formula-generated IDs may change when rows move or source fields are corrected. Names and phone numbers are not unique. Identity must be explicit and branch-scoped.

The preferred metadata contract for writable lifecycle tabs is:

| Metadata | Purpose |
|---|---|
| `oasis_sync_id` | immutable UUID assigned once per source record |
| `oasis_deleted_at` | explicit soft-deletion marker, if deletion is in scope |
| `oasis_deleted_by` | source actor/reference for the deletion marker |

The durable uniqueness boundary is at least `branch_id + source_tab + oasis_sync_id`. Spreadsheet ID may be stored as synchronization context, but changing `branches.sheet_id` must not cause cross-branch adoption of records.

Metadata columns must be appended only after a dry-run schema check, backup, explicit authorization, and confirmation that formulas/validations will not be displaced. They should be hidden after creation. Existing non-OASIS hidden columns must remain untouched.

Solo currently has no OASIS metadata, so the initial assignment requires a controlled metadata-seeding pass. A row receives one UUID and retains it through row movement and business-field edits. Blank/template/spill rows do not receive identity. The process must be idempotent and must stop on duplicate metadata IDs.

For read-only or not-yet-migrated branches, natural keys are reconciliation candidates only:

- `lead.id_lead` within branch and tab;
- `data_konsumen_nup.nup` within an explicitly NUP-enabled project;
- `data_konsumen.id_kavling` plus an approved consumer occurrence discriminator;
- `bi_checking.id_kons`;
- `akad.no_ppjb_akad`.

Candidate keys never authorize an update when duplicated, blank, formula-error-valued, or inconsistent with branch/project scope.

## 14. Manual and System Ownership

Field ownership must be explicit to prevent sync loops.

| Data | Owner | Sync rule |
|---|---|---|
| OASIS six milestone timestamps | OASIS/manual workflow unless separately approved | source evidence cannot clear or silently replace |
| `lead.status_lead` | spreadsheet operator | ingest raw and normalized; do not rewrite for alias cleanup |
| `data_ceklok` date/time/status/notes | spreadsheet operator, except formula-spilled name | ingest as source observation |
| `data_sales` names and NIK references | spreadsheet operator | use for completion mapping only |
| identity/contact fields on source tabs | spreadsheet operator until an approved two-way field map exists | do not overwrite OASIS PII automatically |
| formula columns | spreadsheet system | read effective output; never replace formulas with values |
| OASIS metadata columns | OASIS sync | operators must not edit; duplicate/change is a conflict |
| derived canonical lifecycle state | OASIS system | recalculate from observations and preserved timestamps |
| reconciliation decisions and overrides | authorized OASIS operator | audit actor, reason, old/new values |
| `supervisor_user_id` | OASIS IAM | spreadsheet mismatch is advisory only |

Manual status and system-derived status must be stored/displayed separately. A manual status communicates operator intent; a derived status communicates verified lifecycle evidence. Neither should overwrite the other.

## 15. Reconciliation and Precedence

Each synchronization run must be branch-isolated, lock-protected, idempotent, and complete before replacing its observation snapshot. A partial Google response must not be treated as deletion.

Recommended matching precedence:

1. exact `branch_id + source_tab + oasis_sync_id`;
2. previously confirmed external-reference mapping in the same branch and tab;
3. unique, nonblank business ID in the same branch and expected project;
4. explicit operator completion from the migration review queue;
5. otherwise unmatched.

Names, normalized phones, NIK strings, kavling text, and row positions may rank review suggestions but cannot create a confirmed link by themselves.

Recommended field/event precedence:

1. an existing non-null OASIS lifecycle timestamp is preserved;
2. an explicit authorized OASIS override with reason wins over imported evidence;
3. valid dated source evidence may fill a null timestamp only when its mapping is approved and unambiguous;
4. among equivalent source observations, the earliest valid business event date supplies first-occurrence milestones unless the business rule explicitly defines latest occurrence;
5. manual source status without a business date does not fabricate a timestamp;
6. formula output is evidence, not authority to overwrite manual OASIS history;
7. unknown, contradictory, duplicate, or cross-project evidence enters review and performs no automatic write.

Negative statuses such as `Tidak Lolos BI Checking`, `non ok`, `Reject`, or `Mundur` must not reverse timestamps. They are outcomes with their own observation history. Source deletion also must not reverse a stage.

Conflict classes must include duplicate identity, changed metadata identity, owner mismatch, branch/project mismatch, ambiguous consumer match, source date after/before contradictory milestones, unknown status, missing required tab/header, formula replaced by value, and inaccessible actor-requested scope.

## 16. Migration and Backfill Plan

A future implementation should be phased and reversible at the feature level:

1. Deploy nullable/local integration structures and project NUP configuration with default `false`; do not enable sync writes.
2. Register any new permissions through `PermissionCatalog` and an idempotent migration with deliberate role mappings.
3. Inventory every active branch `sheet_id`, exact required tabs/headers, formula columns, validation columns, hidden columns, duplicate business IDs, and row counts in read-only dry-run mode.
4. Produce branch capability reports. Do not classify a missing optional tab as success or a missing required tab as empty data.
5. Load source observations read-only without changing `sales_leads` or workbooks.
6. Collect mandatory completion mappings for Sales users, coordinators, projects, ambiguous consumers, NUP-enabled projects, and duplicate business IDs.
7. Backfill confirmed links using branch-scoped identities. Preserve every existing six-stage timestamp exactly.
8. Compare proposed lifecycle events against existing OASIS timestamps and legacy reports. Require zero unexplained report changes before enabling writes.
9. Seed hidden OASIS metadata per branch/tab only after backup and approved dry-run. Never copy Solo metadata or IDs into another branch.
10. Enable one pilot branch behind explicit configuration, initially in observe-only mode, then allow only approved null-timestamp fills.
11. Expand branch by branch after reconciliation error thresholds, authorization tests, and operational sign-off pass.
12. Enable branch-scoped two-way writes only for operations whose exact sheet/header/metadata contract has passed validation; incompatible operations remain disabled with a focused Indonesian configuration error.

Backfill must be restartable. Each batch records scope, counts, hashes, completion decisions, conflicts, and actor/system identity. It must not use `updated_at` as the imported business event date.

## 17. Authorization Matrix

All access continues to require active, verified, password-complete accounts and the primary Sales route restriction. Supplemental roles do not grant permissions. Branch/project/user scope must be resolved through `WorkspaceAccessService` and `OrganizationScopeService`.

The existing permissions do not automatically define lifecycle sync or reconciliation powers. If implementation adds those operations, use separately registered permissions with scoped variants rather than treating view, export, Database sync, or Konsumen Progress sync as equivalent.

| Actor/scope | View reconciled lifecycle | Run read sync | Complete mappings/conflicts | Seed/write metadata | Change NUP flag | Reverse legacy stage |
|---|---|---|---|---|---|---|
| Primary Sales, own active assignment | own records only | no by default | no | no | no | no |
| Sales Coordinator, team scope | authorized team records | no by default | only if a dedicated scoped permission is approved | no | no | existing policy denies Sales reversal only; all other checks still apply |
| Supervisor, assigned/team scope | authorized intersection | dedicated assigned permission required | dedicated assigned permission required | no by default | no by default | current lead policy and branch edit right |
| Manager/Branch Manager, branch scope | authorized branch/project intersection | dedicated branch permission required | dedicated branch permission required | dedicated high-risk permission required | dedicated configure permission required | current lead policy and branch edit right |
| Pusat with explicit all-scope permission | authorized all scope | dedicated all-scope permission required | dedicated all-scope permission required | dedicated high-risk permission required | dedicated configure permission required | current lead policy |
| Superadmin | registered-permission wildcard only | only after permissions are registered | only after permissions are registered | only after permissions are registered | only after permissions are registered | current policy |
| No scoped Buku Saku permission | denied | denied | denied | denied | denied | denied |

Direct URLs, commands, AJAX, exports, reconciliation detail, conflict payloads, and metadata writes require the same scope checks. Explicit inaccessible branch/project requests return denial, not fallback. Sync diagnostics must not expose PII from rows outside the viewer's scope.

## 18. Operations, Rollback, and Validation

### Observability

Each branch run should record start/end time, workbook identity hash or configured context, requested and returned tabs, schema version/capabilities, source row counts, created/updated/unchanged observations, confirmed links, pending completions, conflicts by class, skipped formulas, proposed timestamp fills, applied fills, and failure message. Logs and notifications must exclude full KTP, phone, addresses, credentials, and spreadsheet contents.

The UI must distinguish no data, unsupported/missing tab, schema drift, credentials/network failure, partial response, lock contention, and successful zero-change sync.

### Rollback

- Disable the lifecycle feature/scheduler first; do not redirect to Solo or another branch.
- Stop metadata and timestamp writes while retaining observations and audit records for investigation.
- Restore a workbook from its pre-seeding backup if metadata insertion damaged layout; remove only exact OASIS metadata columns introduced by the rollout.
- Do not automatically roll back six-stage timestamp fills after users or reports may have relied on them. Review each applied event through its audit record and use the existing authorized reversal workflow where appropriate.
- Do not delete confirmed identity mappings merely because a source row disappeared.
- Database schema rollback is safe only before production observations and reconciliation decisions exist. After data exists, prefer forward remediation.
- Changing `branches.sheet_id` requires a new dry-run and explicit confirmation; it must not transplant old mappings into the new workbook automatically.

### Required validation

- exact tab/header/formula/validation audit for every enabled branch;
- Solo reference parity and Jepara drift behavior;
- missing `data_ceklok` handling;
- NUP flag default false and explicit enablement;
- metadata seeding idempotency, duplicate detection, row movement, formula preservation, hidden-column preservation, and retry safety;
- existing timestamp preservation and zero unexplained legacy report changes;
- alias normalization for `Cek Silk`/`Cek SLIK` with raw-value retention;
- manual versus derived status separation;
- field/event precedence and all conflict classes;
- Sales/coordinator completion with no NIK-based automatic user mutation;
- full authorization matrix, supplemental-role denial, inaccessible explicit scope, commands, exports, and AJAX;
- Google failure, partial response, lock, retry, and concurrent edit behavior;
- Database and Konsumen Progress route/cache/status isolation;
- rollback rehearsal from a backed-up pilot workbook.

## 19. Known Gaps and Acceptance Gate

The following gaps are known and must not be hidden by implementation defaults:

- unknown spreadsheet statuses have no automatic mapping and remain reconciliation items;
- `met_at` has no exact audited source event;
- `documents_completed_at` cannot receive a truthful timestamp from a timeless completeness formula alone;
- `data_ceklok` is absent in Jepara and may be absent elsewhere;
- branch formulas and completeness definitions differ and may drift within one tab;
- Solo currently has no OASIS metadata, so existing rows have no stable OASIS identity;
- formula-generated IDs are not immutable and may collide or change;
- OASIS users have no NIK field, so Sales mapping needs authorized completion input;
- the exact relationship between a lead, NUP row, consumer/kavling row, BI record, and akad row is not guaranteed by a shared immutable key;
- `linked_consumer_reference` remains manual and is not sufficient for automatic linkage;
- the project-level NUP capability does not yet exist and must default to false if implemented;
- source PII corrections remain field-owned by the explicit lifecycle operation; sync does not overwrite unrelated PII;
- deletion semantics for source rows are not approved; absence must not mean deletion;
- retention limits for source observations, conflict history, and potentially sensitive source snapshots require approval;
- the operational process for workbook backup, metadata seeding approval, and spreadsheet-owner communication requires definition;
- no release version is supplied, so a future behavior change must use a null changelog version unless a release version is explicitly provided.

Implementation acceptance requires all of the following:

- explicit branch capability definitions and pilot branch selection;
- approved permissions and default role mappings deployed idempotently;
- completed Sales/project/coordinator/NUP/ambiguous-record mapping input for the pilot scope;
- successful read-only backfill with no cross-branch links and no unexplained changes to legacy timestamp reports;
- verified stable metadata seeding against a restorable workbook backup;
- focused authorization, reconciliation, concurrency, failure, and rollback tests;
- confirmation that Database and Konsumen Progress remain operationally separate;
- one Indonesian OASIS Changelog entry in the eventual behavior-changing implementation, not in this documentation-only audit.

Any branch or operation that does not satisfy this gate remains disabled with a configuration/reconciliation message. There is never an automatic source deletion, hierarchy update, or cross-branch fallback.
