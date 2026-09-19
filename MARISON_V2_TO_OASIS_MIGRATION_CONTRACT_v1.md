# Marison V2 → OASIS Migration Contract v1

**Status:** IMPLEMENTATION-READY CONTRACT  
**Source baseline:** Marison V2.5.50  
**Destination:** `marketingbranding/oasiscrm`  
**Primary migration identity:** `id_transaksi_v2`  
**Identity policy:** consumer identity is reconciled by admin after process/history import.

---

## 1. Objective

Migrate all operational process history from Marison V2 into OASIS without making consumer name the migration key.

The migration must preserve:

- one stable housing journey per `id_transaksi_v2`;
- BI Checking history;
- PSJB facts;
- Pemberkasan facts;
- all bank attempts / SP3K responses;
- PPJB Developer;
- Akad;
- BAST;
- status history;
- kavling movement history;
- source lineage/confidence;
- auditability and idempotency.

The migration must **not** silently decide who the customer is or silently occupy/release a kavling. Those decisions are finalized through an OASIS reconciliation workflow.

---

## 2. Core Rules

### 2.1 Canonical migration identity

```text
source_system + branch_code + id_transaksi_v2
```

Example form:

```text
marison_v2:MGL:TRX-MGL-2026-000001
```

`id_transaksi_v2` is the transaction/application identity.

Do **not** use consumer name, NIK, phone, current kavling, document number alone, or row number as migration identity.

### 2.2 Consumer identity

Phase-1 migration creates/imports the OASIS application with:

```text
consumer_applications.customer_id = NULL
consumer_applications.nama_konsumen = NULL
consumer_applications.nik = NULL
```

The user-facing reconciliation flow later links the application to an existing or newly created OASIS customer.

`id_konsumen_v2` may be retained only as an **opaque source reference** for lineage; it is not an OASIS customer ID.

### 2.3 PII policy

The standard migration package MUST omit:

- `nama_konsumen`;
- `no_ktp` / NIK;
- `no_hp`;
- emergency-contact identity;
- full customer address/profile fields.

This prevents accidental auto-matching and keeps customer reconciliation explicit.

If assisted identity matching is required later, define a separate, opt-in secure identity bridge. It is not part of this contract.

### 2.4 No destructive overwrite

An already imported source transaction is never silently overwritten.

Importer result must be one of:

- `READY`
- `ALREADY_IMPORTED`
- `SOURCE_CHANGED_REVIEW`
- `NEEDS_REVIEW`
- `BLOCKED`

### 2.5 OASIS remains canonical after reconciliation

Marison V2 is a migration source. After a transaction is reconciled and OASIS cutover is accepted, OASIS becomes the operational source for that record.

---

## 3. Source Precedence

### 3.1 Transaction root

Use `trx_penjualan` as the transaction root.

Fields used:

| Marison V2 | Purpose in migration |
|---|---|
| `id_transaksi` | canonical migration identity |
| `id_konsumen` | opaque legacy consumer reference |
| `id_cabang` | branch resolver |
| `id_proyek` | project resolver |
| `id_kavling_current` | source/current-kavling suggestion |
| `metode_pembayaran` | KPR/CASH |
| `detail_metode` | payment detail |
| `status_transaksi` | source-state suggestion |
| `status_bank` | source-state suggestion |
| `status_kavling` | source-state suggestion |
| `tahap_terkini` | source-stage suggestion |
| document/date latest fields | reconciliation/validation only |
| `status_migrasi` | source audit |
| `lineage_confidence` | preview risk level |
| `source_row_count` | source audit |
| `schema_version` | source audit |
| `source_system` | source audit |
| `catatan` | source audit |

The `nama_konsumen` and `no_ktp` columns are intentionally excluded.

### 3.2 SP3K source of truth

Marison V2.5.50 rule:

```text
proses_bank = canonical SP3K source
ppjb_dev.no_sp3k = compatibility mirror only
ppjb_dev.tanggal_sp3k = compatibility mirror only
```

Therefore the OASIS importer must **not** source SP3K from PPJB.

### 3.3 Bank-attempt precedence

Use `kpr_bank_attempt` as the preferred attempt-level history when available.

Enrich/validate it with:

- `pemberkasan` by `id_transaksi_v2 + id_berkas`;
- `proses_bank` by `id_transaksi_v2 + id_berkas/no_sp3k`.

Never create duplicate OASIS bank-process rows from all three sources for the same attempt.

---

## 4. OASIS Target Model

Existing OASIS foundations are sufficient for most normalized data:

| OASIS | Use |
|---|---|
| `consumer_applications` | one application per `id_transaksi_v2` |
| `consumer_legacy_identities` | stable V2 ↔ OASIS transaction bridge |
| `consumer_stage_events` | append-only stage/history facts |
| `consumer_psjbs` | typed PSJB data |
| `consumer_bank_processes` | typed Pemberkasan / bank-attempt data |
| `consumer_ppjb_developers` | typed PPJB Developer data |
| `consumer_akad_records` | typed Akad data |
| `consumer_bast_records` | typed BAST data |
| `consumer_kavling_assignments` | authoritative kavling lifecycle **after reconciliation** |
| `kavlings` | local kavling catalog |
| `customers` | linked/created during reconciliation |

### Required new persistent reconciliation layer

Add a persistent OASIS reconciliation entity, e.g. `consumer_migration_reconciliations`.

Minimum fields:

- `consumer_application_id`
- `branch_id`
- `project_id`
- `source_system`
- `source_transaction_id`
- `source_customer_ref`
- `source_current_kavling`
- source transaction/bank/kavling/stage status fields
- `suggested_decision`
- `decision` nullable
- `customer_id` nullable
- `target_kavling_id` nullable
- `reconciliation_status`
- `source_payload_hash`
- `resolved_by` nullable
- `resolved_at` nullable

Recommended reconciliation statuses: `PENDING`, `READY_TO_CONFIRM`, `RESOLVED`, `IGNORED`, `CONFLICT`.

---

## 5. Import Mapping by Process

### 5.1 Root transaction → `consumer_applications`

Create one application per `id_transaksi_v2`.

Initial values:

| Target | Value |
|---|---|
| `customer_id` | `NULL` |
| `branch_id` | resolved from branch mapping |
| `project_id` | resolved from project mapping |
| `kavling_id` | `NULL` until reconciliation |
| `id_kavling` | source current kavling string, informational |
| `nama_konsumen` | `NULL` |
| `nik` | `NULL` |
| `application_status` | `migration_pending` |
| `consumer_status` | `NULL` until reconciliation |
| `current_stage` | derived from imported process facts |
| `source_last_process` | imported source stage / derived stage |
| `status_cash` | true when route is CASH |
| `akad_date` | source Akad if valid |

Create a `consumer_legacy_identities` record:

```text
legacy_source = marison_v2
spreadsheet_id = source workbook ID
sheet_name = trx_penjualan
external_key = id_transaksi_v2
mapping_status = pending_customer_reconciliation
consumer_application_id = created/reused application
customer_id = NULL
```

### 5.2 BI Checking → `consumer_stage_events`

- `stage = bi_checking`
- `id_kons` → `source_id`
- `tanggal_slik` → `event_date/occurred_at` when a real date
- `hasil_slik` → `status`
- `keterangan` → `notes`
- source link/confidence → `metadata`

Special values such as `CASH` are not parsed as dates.

### 5.3 PSJB → `consumer_psjbs` + stage event

Typed mapping:

- `id_psjb`
- `tanggal_psjb`
- `nama_koordinator`
- `nama_sales`
- `harga_unit`
- `tanggal_utj`
- `utj`
- `dp_all_in`
- `nominal_cicilan`
- `jumlah_cicilan`
- `luas_klt_m2` → `luas_klt`
- `harga_klt/m` → `harga_klt_m`
- `harga_klt_total`
- `cara_pembayaran`
- promo resolver where available
- `keterangan`

Stage event: `stage = PSJB`, `source_id = id_psjb`.

### 5.4 Pemberkasan + bank attempts → `consumer_bank_processes`

Preferred unique attempt identity: `id_bank_attempt`.

Fallback: `id_transaksi_v2 + id_berkas + attempt_no`.

Typed fields include:

- `id_berkas`
- `tanggal_terima_bank`
- `bank_name`
- `kc_unit`
- `request_plafond`
- `request_tenor`
- `tipe_pemberkasan`
- `submitted_at`
- source/status metadata

Create stage event `pemberkasan` when the source has a valid Pemberkasan event.

### 5.5 Proses Bank / SP3K → same bank attempt

Merge with the matching bank attempt instead of creating duplicate attempt rows.

Typed fields:

- `no_sp3k`
- `sp3k_at` from canonical `tanggal_sp3k`
- `response_type`
- `approved_plafond`
- `approved_tenor`
- `status`
- `revision_category`
- `revision_detail`
- `notes`
- other source fields in metadata

Create/append stage event `proses_bank`, source ID `no_sp3k` or bank-attempt ID.

Multiple bank attempts are preserved.

### 5.6 PPJB Developer → `consumer_ppjb_developers` + event

Import `id_ppjb_dev` as event/source identity, `tanggal_ttd_ppjb`, status and notes.

Do not trust PPJB mirror fields as SP3K source.

For `consumer_ppjb_developers.tanggal_sp3k`, use the canonical SP3K date already imported from `proses_bank`.

### 5.7 Akad → `consumer_akad_records` + event

Typed mapping:

| Source | Target |
|---|---|
| `tanggal_akad` | `tanggal_akad` |
| `kualitas_akad` | `kualitas_akad` |
| `status_dp` | `status_dp_konsumen` |
| `status_utilitas` | `status_utilitas` |

Store in stage-event metadata when no typed OASIS column exists:

- `progress_bangunan`
- `kendala_konsumen`
- `kendala_bank`
- `detail_kendala`
- `status_lead_time`
- `lineage_confidence_v2`

`no_ppjb_akad` becomes event `source_id`.

Akad makes the reconciliation recommendation `SELESAI/SOLD`, but it does not activate a kavling assignment until admin confirmation.

### 5.8 BAST → `consumer_bast_records` + event

- typed `tanggal_bast`
- stage `bast`
- event `source_id = no_bast`
- notes/status from source

BAST also recommends `SELESAI/SOLD`.

---

## 6. Kavling History

Source: `hist_kavling_transaksi`.

During package import:

- preserve all movement events in migration staging/audit;
- attach them to the application by `id_transaksi`;
- do **not** create an active `consumer_kavling_assignment` yet.

During admin reconciliation:

- `LANJUT` → assign selected/current kavling;
- `MUNDUR` → no active assignment; process history remains;
- `PINDAH_KAVLING` → construct historical released assignment(s), then active target assignment;
- `SELESAI/AKAD` → final assignment status `sold`;
- `REJECT` → admin confirms whether the kavling remains reserved or is released.

This prevents uncertain historical data from locking an OASIS kavling.

---

## 7. Status History

Source: `hist_status_transaksi`.

Rules:

- `MIGRATION_BASELINE` is audit-only, not an operational customer event.
- genuine later operational status events may be imported with `source=marison_v2_history`.
- preserve source event ID and timestamp.
- final current customer decision comes from reconciliation.

---

## 8. Project and Kavling Resolution

### Branch

Resolve by exact V2 branch code (`id_cabang` → `branches.code`). Unknown branch = `BLOCKED`.

### Project

OASIS currently has no canonical V2 project-code field. Maintain a saved mapping:

```text
(source_system, branch_code, source_project_id) → oasis_project_id
```

Never silently map a project by fuzzy name.

### Kavling

Resolver order:

1. exact full-name match inside mapped project;
2. exact `kavling_code` match after deterministic source-code normalization;
3. otherwise `NEEDS_REVIEW`.

No fuzzy match may auto-confirm occupancy.

---

## 9. Reconciliation Workflow

Recommended OASIS workspace per imported transaction:

- source transaction ID;
- source/current kavling;
- payment route;
- highest imported process;
- BI / PSJB / Bank / SP3K / PPJB / Akad / BAST indicators;
- historical movement indicator;
- source status suggestions;
- lineage confidence;
- customer = `Belum Dipilih`.

Admin actions:

```text
1. Pilih / Buat Konsumen
2. Pilih keputusan:
   - Lanjut
   - Mundur
   - Pindah Kavling
   - Reject
   - Selesai/Akad
3. Jika Lanjut/Pindah/Selesai → konfirmasi kavling
4. Simpan & Terapkan
```

Application of a decision must call existing OASIS lifecycle services, not directly mutate assignment rows from the controller.

---

## 10. Suggested Decision Rules

These are suggestions only; admin confirms them.

| Source evidence | Suggested decision |
|---|---|
| BAST exists | `SELESAI` |
| Akad exists | `SELESAI` |
| explicit latest pindah-kavling event | `PINDAH_KAVLING` |
| source transaction `MUNDUR` | `MUNDUR` |
| source consumer status `Reject` | `REJECT` |
| otherwise active process | `LANJUT` |

Conflicting evidence → `NEEDS_REVIEW`.

---

## 11. Package Format

One JSON file per branch export:

```text
marison-v2-MGL-YYYYMMDDTHHMMSS+0700.json
```

Top-level:

```json
{
  "contract": "marison-v2-to-oasis",
  "contract_version": "1.0",
  "source": {},
  "manifest": {},
  "transactions": [],
  "unlinked_records": []
}
```

Each transaction contains all linked process/history records. The package contains **no consumer names**.

Manifest counts at minimum:

- transactions
- bi_checking
- psjb
- pemberkasan
- bank_attempts
- proses_bank
- ppjb_dev
- akad
- bast
- kavling_events
- status_events
- unlinked_records

Importer preview compares source counts with created/reused/skipped/review counts.

---

## 12. Idempotency

Root logical key:

```text
marison_v2 + branch_code + id_transaksi_v2
```

Process keys:

- BI → `id_transaksi_v2 + id_kons` (transaction-scoped). `id_kons` is a consumer-level
  legacy reference and may legitimately repeat across different housing transactions.
  Exact compatibility copies of the same BI fact inside one transaction must be
  collapsed by the exporter before package generation.
- PSJB → `id_psjb`
- bank attempt → `id_bank_attempt`
- Pemberkasan → `id_berkas`
- Proses Bank → bank attempt / `no_sp3k`
- PPJB → `id_ppjb_dev`
- Akad → `no_ppjb_akad`
- BAST → `no_bast`
- kavling history → `event_id`
- status history → `event_id`

Also store SHA-256 payload fingerprints.

For BI source-audit records, OASIS stores a transaction-scoped source record identity.
The raw/pseudonymized `id_kons` remains available in the BI stage metadata; the audit
identity must not make `id_kons` globally unique across transactions.

Same key + same fingerprint = `ALREADY_IMPORTED`.

Same key + different fingerprint = `SOURCE_CHANGED_REVIEW`.

No silent overwrite.

---

## 13. Preview / Validation Gates

### READY

- branch resolved;
- project mapping resolved;
- unique `id_transaksi_v2`;
- no stage belongs to a different transaction;
- source IDs are non-conflicting;
- package schema valid.

### NEEDS_REVIEW

Examples:

- lineage confidence `REVIEW`;
- source/current kavling cannot be resolved;
- conflicting source status;
- movement ambiguity;
- process chronology anomaly that does not destroy lineage.

### BLOCKED

Examples:

- missing `id_transaksi_v2`;
- duplicate transaction root inside package;
- unknown branch;
- project mapping absent;
- same document/source ID linked to different transactions, except BI `id_kons`
  whose identity is explicitly transaction-scoped;
- malformed package/contract version.

### Unlinked records

Unlinked process rows are preserved in `unlinked_records`, never silently discarded.

---

## 14. Confirm Transaction

Confirmation should be transactional:

1. lock migration batch;
2. validate preview hash/version;
3. create/reuse `consumer_application`;
4. create V2 legacy-identity bridge;
5. import typed process records;
6. append stage/history events;
7. create persistent reconciliation item;
8. commit;
9. write activity log.

No customer and no active kavling assignment is required at this step.

---

## 15. Cutover Acceptance

Before declaring a branch migrated:

- 100% source transaction roots accounted for;
- 100% linked process rows accounted for;
- unlinked rows explicitly listed;
- no duplicate migration keys;
- no silent overwrite;
- V2 vs OASIS counts reconciled for BI, PSJB, Pemberkasan, Bank attempts, Proses Bank/SP3K, PPJB, Akad, BAST, and Kavling history;
- all active applications have an admin decision or are explicitly pending;
- no two active/sold applications occupy the same OASIS kavling;
- Akad/BAST applications cannot be accidentally released by migration;
- movement history remains queryable.

---

## 16. Implementation Sequence

### Phase A — OASIS importer foundation

1. JSON package validator.
2. import batch/preview.
3. V2 transaction bridge using `id_transaksi_v2`.
4. typed process import.
5. idempotency/fingerprint.
6. tests.

### Phase B — Reconciliation workspace

1. pending queue.
2. choose/create customer.
3. decision `Lanjut/Mundur/Pindah/Reject/Selesai`.
4. kavling confirmation.
5. apply through `ConsumerKavlingLifecycleService`.
6. audit log.

### Phase C — V2 exporter

1. `Export untuk OASIS`.
2. build branch JSON package.
3. strip PII.
4. manifest counts + hash.
5. pre-export validation.

### Phase D — Magelang pilot

1. export Magelang;
2. OASIS preview only;
3. compare counts;
4. import;
5. reconcile selected sample set;
6. verify dashboards/process details;
7. then plan other branches.

---

## 17. Non-negotiable Rules

- No consumer-name migration.
- No name-based identity matching.
- No kavling as primary migration identity.
- No fuzzy project auto-match.
- No fuzzy kavling auto-confirm.
- No SP3K source from PPJB.
- No direct active kavling assignment during raw import.
- No duplicate bank attempts.
- No silent overwrite on re-import.
- No deletion of historical process records when consumer is Mundur.
- No branch-specific importer fork.
