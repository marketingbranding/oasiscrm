# IMPLEMENTATION PLAN — OASIS CONSUMER DATABASE V1

**Product:** OASIS  
**Repository:** `marketingbranding/oasiscrm`  
**Primary Reference:** `PRD_OASIS_CRM_Consumer_Database_Platform.md`  
**Delivery Strategy:** Consumer Database First, End-to-End Architecture  
**Product Architecture (V2):** Lead → Data Konsumen → PSJB/SLIK → Pemberkasan → Proses Bank → SP3K → PPJB → Akad → BAST → Form Garansi → Selesai
**Target Platform:** Responsive Web Application / PWA  
**Implementation Principle:** Audit first, reuse existing code, migration-safe, no big-bang rewrite  
**Verified repository baseline when this plan was prepared:** `main` at commit `4c3e945a0f84cc112f98596b87adefa53a16f251` on 1 October 2026.  
**Important:** The coding agent must re-check the current branch and commit before starting. Do not assume this SHA is still latest.

---

# 1. PURPOSE

Dokumen ini menerjemahkan Master PRD OASIS menjadi rencana implementasi teknis untuk **Consumer Database V1**.

Tujuan milestone pertama bukan menyelesaikan seluruh CRM OASIS. Tujuan milestone pertama adalah memastikan bahwa:

1. Consumer Database memakai arsitektur final yang tidak perlu dibongkar ketika Lead CRM dikembangkan.
2. Customer, Consumer Application, Kavling, proses transaksi, history, search, dan responsive workspace sudah mempunyai fondasi production-grade.
3. Lead dapat melakukan handoff ke Consumer Application pada event UTJ.
4. Business rules penting dari Database Master V3 dipertahankan jika masih relevan.
5. Google Sheets tetap hidup sebagai compatibility layer selama masa transisi.
6. Existing OASIS yang sudah benar tidak ditulis ulang hanya demi menyesuaikan nama atau bentuk PRD.

> **V2 supersedes the former UTJ-first assumption.** UTJ is a transaction fact recorded in PSJB/early process. Lead conversion is the explicit `Jadikan Konsumen` handoff, NUP is a separate waiting-list domain, and historical data may set its current position without fabricated milestones.

---

# 2. SOURCE OF TRUTH HIERARCHY

Jika terjadi perbedaan informasi, gunakan urutan berikut:

1. **Current executable code + current database migrations**
2. **Current automated tests**
3. **Current repository instructions / AGENTS.md**
4. **Master PRD OASIS**
5. **Legacy documentation**
6. **Historical assumptions**

Interpretasi:

- Current code menentukan apa yang benar-benar berjalan.
- PRD menentukan arah produk yang harus dicapai.
- Agent harus membuat gap antara kondisi sekarang dan target PRD.
- Jangan menghapus implementasi current hanya karena struktur namanya berbeda jika semantics-nya sudah benar.

---

# 3. NON-NEGOTIABLE ARCHITECTURAL PRINCIPLES

## 3.1 Oasis First

OASIS menjadi operational source utama.

Google Sheet:

- compatibility,
- transition,
- import/export,
- backup operational,
- reconciliation.

Google Sheet tidak boleh menjadi dependency wajib untuk create/update Consumer Application baru.

---

## 3.2 Customer ≠ Transaction

Harus ada pemisahan jelas:

```text
Customer
    │
    ├── Lead 1
    ├── Lead 2
    ├── Application 1
    └── Application 2
```

Satu Customer boleh mempunyai beberapa Lead dan beberapa Consumer Application.

Jangan membuat unique constraint yang memaksa:

```text
1 Customer = 1 Application
```

---

## 3.3 UTJ adalah Conversion Event

Lead resmi masuk domain Consumer Database ketika UTJ terjadi.

```text
SalesLead
   ↓ UTJ
Customer resolved/created
   ↓
ConsumerApplication created
```

Jangan membuat conversion hanya berupa perubahan `current_status`.

---

## 3.4 Immutable Transaction Identity

`ConsumerApplication` / `id_transaksi` tidak berubah karena:

- pindah kavling,
- ganti bank,
- revisi,
- milestone baru,
- correction.

Ganti konsumen membuat application baru dan menutup/merelasikan application lama.

---

## 3.5 Append History, Mutable Current State

Current record boleh diperbaiki.

Tetapi perubahan penting harus mempunyai audit trail.

Pattern:

```text
Current state = editable
History/audit = append-only
```

---

## 3.6 Permissive Operations, Protected Integrity

Jangan membuat approval flow berlebihan.

User sesuai scope boleh mengoreksi data lama.

Tetapi backend tetap melindungi:

- immutable IDs,
- double kavling assignment,
- relation integrity,
- audit log,
- destructive delete,
- concurrent writes,
- access lintas scope.

---

# 4. CURRENT OASIS ASSUMPTIONS TO VERIFY

Agent harus membuktikan ini dari current code sebelum implementasi.

Dari audit sebelumnya, OASIS sudah mempunyai beberapa komponen yang kemungkinan dapat digunakan:

- `ConsumerApplication`
- `Customer`
- typed records untuk proses konsumen
- `ConsumerOperationalService`
- `ConsumerKavlingLifecycleService`
- migration/reconciliation services
- `ConsumerLegacyIdentity`
- stage events
- multiple bank attempt support pada migration path
- Sales Lead domain
- Sales Lead lifecycle
- Google Sheet bridge/reconciliation
- role/permission framework
- activity log
- responsive/PWA foundation
- local Consumer Progress read adapter, tetapi rollout sebelumnya masih legacy-first

Agent **tidak boleh mengasumsikan semuanya benar atau lengkap**.

Audit ulang.

---

# 5. KNOWN AREAS THAT REQUIRE SPECIAL AUDIT

## 5.1 Native Bank Process Semantics

Past audit found that the migration importer understood bank attempts better than the native operational workflow.

Verify whether native:

```text
Pemberkasan
↓
Proses Bank
```

creates separate `ConsumerBankProcess` records when it should enrich or continue one bank attempt.

Target semantics:

```text
Bank Attempt #1
    pemberkasan
    process
    SP3K/reject

Ganti Bank

Bank Attempt #2
    pemberkasan
    process
    SP3K/reject
```

Do not silently merge separate attempts.

Do not split one attempt into unrelated rows.

---

## 5.2 BAST Readiness

Verify current OASIS behavior.

Required business rule:

```text
BAST READY only when:
Akad exists
AND
Ready100 exists
```

SLA anchor:

```text
MAX(akad_date, ready_100_date)
```

Do not allow current-stage transition to BAST to bypass required readiness unless the user is using an explicit authorized override policy defined later.

---

## 5.3 Akad Readiness

Verify whether current OASIS already models:

- building readiness,
- electricity,
- water,
- technical readiness,
- consumer obstacle,
- bank obstacle,
- DP status,
- ready date.

Do not duplicate existing fields unnecessarily.

---

## 5.4 Ganti Konsumen

Verify whether current lifecycle has a formal replacement application relation.

Target:

```text
Old Application
status = REPLACED
        │
        └── replacement_application_id
                      ↓
             New Application
```

Never mutate the old Customer into the new Customer.

---

## 5.5 CASH and DP

Verify whether equivalent canonical models already exist.

If not already production-required for Monday milestone, schema must remain compatible with future:

- payment contract,
- schedule,
- payment transactions.

Avoid forcing CASH through KPR bank stages.

---

# 6. DELIVERY PRIORITIES

## P0 — REQUIRED FOR CONSUMER DATABASE V1

Must be production-safe before milestone is declared PASS:

1. Customer ↔ ConsumerApplication separation
2. Immutable application identity
3. Consumer lifecycle/stage
4. Kavling active assignment integrity
5. Pindah Kavling
6. Mundur
7. Ganti Konsumen
8. Multiple bank attempts
9. SLIK
10. PSJB
11. Pemberkasan
12. SP3K
13. PPJB
14. Akad
15. BAST readiness guard
16. Activity/audit history
17. Consumer workspace
18. Responsive desktop/tablet/mobile
19. Global consumer search
20. Lead → UTJ → Consumer Application contract
21. Google compatibility preserved
22. Automated tests for critical lifecycle

---

## P1 — SHOULD HAVE

Implement after P0 is stable:

- saved views
- filtering/sorting/grouping
- task core
- notification inbox
- import/export workflow
- file attachment
- comments/@mention
- KPI summary
- basic dashboard

---

## P2 — POST MILESTONE / NICE TO HAVE

Do not delay P0 for:

- custom fields
- advanced Kanban
- Calendar
- visual automation builder
- WhatsApp provider
- external lead API
- round-robin assignment
- custom dashboard builder
- advanced reporting builder

Architecture may prepare extension points, but do not build these prematurely.

---

# 7. IMPLEMENTATION PHASES

---

# PHASE 0 — REPOSITORY & PRODUCTION BASELINE

## Goal

Know exactly what exists before changing anything.

## Actions

1. Read repository instructions.
2. Record:
   - branch,
   - HEAD SHA,
   - PHP/Laravel versions,
   - DB driver used in tests,
   - relevant feature flags.
3. Run current test suite or the largest safe relevant subset.
4. Identify current migrations for:
   - customers,
   - consumer applications,
   - kavling assignments,
   - bank processes,
   - process records,
   - lead-to-consumer links,
   - activity/audit.
5. Map relevant controllers/services/models.
6. Inspect current Consumer Progress read source.
7. Inspect current Google compatibility configuration.
8. Do not modify production data.

## Deliverable

`CURRENT_STATE_AUDIT.md` or equivalent worklog section with:

| Domain | Existing | Status | Reuse? | Gap |
|---|---|---|---|---|
| Customer | ... | GOOD/PARTIAL | YES | ... |
| ConsumerApplication | ... | ... | ... | ... |
| Kavling | ... | ... | ... | ... |

## Gate

No implementation starts until current state is mapped.

---

# PHASE 1 — CANONICAL DOMAIN CONTRACT

## Goal

Freeze semantics before UI work.

## Required canonical relationships

```text
Customer 1 ─── * SalesLead

Customer 1 ─── * ConsumerApplication

SalesLead 0..1 ─── 0..1 ConsumerApplication

ConsumerApplication 1 ─── * StageEvent

ConsumerApplication 1 ─── * KavlingAssignment

ConsumerApplication 1 ─── * BankAttempt / BankProcess

ConsumerApplication 1 ─── * Process Records
```

## Decisions

### Customer

Identity represents person, not transaction.

No status like SP3K/Akad belongs directly to Customer.

### ConsumerApplication

Contains:

- branch/project,
- sales owner,
- current stage,
- current application status,
- payment route,
- current kavling relation,
- transaction identity.

### Stage/Event

Keep append-only process history.

### Current Stage

Prefer derived or service-controlled current stage.

Do not expose unrestricted user dropdown for canonical stage.

## Gate

Automated model/service tests prove one Customer can have multiple applications.

---

# PHASE 2 — APPLICATION LIFECYCLE SERVICE

## Goal

One service layer owns critical business transitions.

Avoid lifecycle rules scattered across controllers.

Recommended architecture:

```text
ConsumerApplicationLifecycleService
```

or reuse/refactor existing equivalent.

Responsibilities:

- application create
- transition
- correction
- withdraw
- replace customer
- assign/reassign kavling
- bank attempt handling
- critical audit

Controller responsibility:

```text
Authorize
Validate request
Call domain service
Return response
```

Controllers must not directly orchestrate multi-table business transactions.

## Gate

Every critical transition executes inside DB transaction.

---

# PHASE 3 — KAVLING LIFECYCLE

## Required invariants

1. Application max one active kavling assignment.
2. Kavling max one active application assignment.
3. Moving kavling is atomic.
4. Old assignment remains history.
5. Akad/BAST completed transaction preserves sold history.
6. Mundur releases according to current business policy.

## Required actions

### Assign Kavling

```text
lock target
validate project
validate availability
create assignment
update current state
activity log
```

### Pindah Kavling

```text
lock application
lock old assignment
lock target
release old
assign new
record event
update application
activity log
commit
```

## Tests

- two simultaneous assignments cannot claim same kavling
- project mismatch rejected
- pindah retains transaction ID
- old assignment retained
- mundur release works

---

# PHASE 4 — CONSUMER PROCESS RECORDS

Implement/reconcile process flow:

```text
UTJ
↓
SLIK
↓
PSJB
↓
Pemberkasan
↓
Bank Attempt
↓
SP3K
↓
PPJB
↓
Akad
↓
BAST
```

Do not make every process a generic JSON blob if typed domain models already exist.

Each milestone should support:

- date
- actor/source
- notes
- relevant structured attributes
- audit
- append history where needed.

Correction of current record is allowed but audit must preserve before/after.

---

# PHASE 5 — BANK ATTEMPTS

## Canonical model

An application may have N bank attempts.

Each attempt owns its process.

Example:

```text
Application A
│
├── Attempt 1 BTN
│      ├── submitted
│      └── rejected
│
└── Attempt 2 BSN
       ├── submitted
       └── approved / SP3K
```

## Ganti Bank

Must create a new attempt.

Must not overwrite old attempt.

## Gate

Tests cover:

- reject bank 1
- switch bank
- bank 2 approved
- history contains both attempts
- current bank resolves from latest active/canonical attempt

---

# PHASE 6 — AKAD & BAST READINESS

## Akad

Audit existing readiness.

Do not duplicate fields if current domain already contains equivalent facts.

## BAST guard

Required condition:

```text
hasAkad == true
AND
ready100_date != null
```

Anchor:

```text
max(akad_date, ready100_date)
```

## Gate

Tests:

- Akad only → BAST not ready
- Ready100 only → BAST not ready
- both → ready
- anchor chooses later date
- completed/MUNDUR does not keep active SLA aging

---

# PHASE 7 — GANTI KONSUMEN

User action:

```text
Ganti Konsumen
```

Required implementation:

1. lock old application
2. resolve/create new Customer
3. create replacement ConsumerApplication
4. preserve old application
5. mark old application REPLACED
6. store relationship old → new
7. transfer only explicitly allowed facts
8. preserve original audit/history
9. handle kavling assignment intentionally
10. write activity

Do not mutate old Customer identity.

## Gate

Old and new transaction IDs differ.

Old transaction remains queryable.

---

# PHASE 8 — LEAD → UTJ HANDOFF

This is the minimum Lead work required in Consumer Database V1.

## Action

```text
Mark as UTJ
```

## Transaction

Within one DB transaction:

1. lock lead
2. ensure it is not already converted idempotently
3. resolve Customer
4. if ambiguous, require explicit resolution before final conversion
5. create ConsumerApplication
6. copy branch/project/sales ownership
7. record UTJ date
8. optionally assign kavling
9. link SalesLead ↔ Customer/Application
10. create history/activity
11. update system lead status
12. commit

## Idempotency

Double click/retry must not create duplicate Application.

Use stable operation identity or unique conversion relationship.

## Duplicate phone

Phone duplicate does not block conversion.

Person resolution should use stronger evidence when available.

Do not silently merge Customer records only by phone.

---

# PHASE 9 — CONSUMER DATABASE WORKSPACE

## Desktop

Recommended structure:

```text
Sidebar
└── Database Konsumen

Header
├── View selector
├── Search
├── Filter
├── Sort
└── Actions

Main
└── Data table

Right drawer
├── Overview
├── Process
├── Payment
├── Files
├── Activity
└── Comments
```

## Interaction

- row click opens drawer
- no full page reload for common edits
- after mutation update affected record only
- preserve filters and scroll state
- loading states are local, not full screen where avoidable

## Table

P0:

- column visibility
- filter
- sort
- pagination or virtualization
- sticky identity
- status/stage badges
- quick actions

Do not block P0 on advanced custom views builder.

---

# PHASE 10 — RESPONSIVE UI

## Desktop

Full data workspace.

## Tablet

Collapsible sidebar + adaptive columns.

## Mobile

Do not render desktop table as mandatory horizontal-scroll workflow.

Default mobile consumer list:

```text
Name
Kavling • Stage
Bank / Payment
Sales
Primary action
```

Record detail opens full-screen sheet/page.

Critical actions must work with touch.

## Gate

Manual smoke at:

- desktop width
- tablet width
- phone width

---

# PHASE 11 — GLOBAL SEARCH

P0 search targets:

- customer name
- phone
- NIK
- id_transaksi
- kavling

P1:

- lead ID
- sales
- project
- task

## Privacy

Search results respect branch/project/user scope.

Do not expose cross-branch data through autocomplete if the user cannot view it.

---

# PHASE 12 — AUDIT & ACTIVITY

Use existing `ActivityLog` if semantics are sufficient.

User-facing activity examples:

```text
Admin Magelang memindahkan kavling
A-12 → B-08
```

```text
Tanggal SP3K diubah
30 Sep 2026 → 1 Okt 2026
```

Critical event metadata should retain machine-readable before/after.

Do not expose raw JSON as the primary UX.

---

# PHASE 13 — GOOGLE COMPATIBILITY

Do not remove legacy bridge during Consumer Database V1.

Required:

- existing lead bridge continues
- migration/reconciliation remains usable
- existing branch configs remain valid
- new local canonical writes do not silently overwrite legacy records without a defined bridge contract

Any read-source switch must be explicit and reversible.

Do not change default production read source until parity tests and acceptance pass.

---

# PHASE 14 — TASK & NOTIFICATION FOUNDATION (P1)

Implement lightweight linked tasks:

```text
Task
├── linked_type
├── linked_id
├── assignee
├── due_at
├── status
├── priority
└── notes
```

Notifications:

- in-app canonical
- mention
- assigned task
- due/overdue
- milestone

Do not block Consumer Database P0 for WhatsApp.

Provider architecture may support future:

- Email
- PWA push
- WhatsApp

---

# PHASE 15 — IMPORT / EXPORT (P1)

## Import

Required workflow:

```text
Upload
→ Preview
→ Mapping
→ Validation
→ Conflict Review
→ Confirm
→ Report
```

Never import blind.

## Export

Support:

- current view/filter
- selected rows
- XLSX/CSV

Apply permission/scope.

---

# 8. ROLE MODEL FOR V1

Use configurable permissions.

Default recommendation:

| Role | Default Scope |
|---|---|
| Sales | Own leads; linked consumer visibility as needed |
| Koordinator | Team leads; team monitoring |
| SPV | Full branch lead + consumer operational edit |
| BM/Admin Cabang | Full branch operational |
| Pusat/Direksi | Multi-branch read; selected management |
| Superadmin | System configuration |

Do not hard-code SPV capability permanently.

Permission examples:

```text
lead.create
lead.edit
lead.reassign
consumer.view
consumer.edit
consumer.pindah_kavling
consumer.mundur
consumer.ganti_konsumen
consumer.export
automation.manage
```

---

# 9. SECURITY MODEL

The application is internal, but internal does not mean unprotected.

## Keep simple

Avoid:

- approval for routine corrections
- field-level permission explosion
- repeated re-authentication
- excessive confirmation dialogs

## Keep strict

Protect:

- login/session
- branch/project scope
- immutable IDs
- audit
- kavling uniqueness
- destructive actions
- credentials/secrets
- permission changes
- external integration secrets

---

# 10. MIGRATION STRATEGY

No big-bang.

Per branch:

```text
Inventory
↓
Snapshot
↓
Import
↓
Reconciliation
↓
Acceptance
↓
Cutover
↓
Legacy compatibility / archive
```

Never infer identity from names/phones alone.

Preserve lineage.

---

# 11. TEST STRATEGY

## Unit Tests

- stage resolver
- BAST readiness
- SLA anchors
- status derivation
- Customer/Application identity
- permission helpers

## Feature Tests

- create application
- UTJ conversion
- pindah kavling
- mundur
- ganti konsumen
- bank switch
- SLIK
- SP3K
- PPJB
- Akad
- BAST
- correction + audit
- search scope

## Concurrency Tests

Where practical:

- duplicate kavling claim
- repeated UTJ conversion
- simultaneous critical action

## Migration Tests

- old migration data imports
- repeated import idempotent
- ambiguous identity creates reconciliation issue
- Google compatibility remains intact

## Responsive Smoke

Manual or browser tests for main workflow.

---

# 12. PRODUCTION SAFETY GATES

Do not declare a phase complete because UI renders.

Every production-bearing phase must pass:

1. migration review
2. tests
3. data integrity check
4. rollback assessment
5. compatibility check
6. user workflow smoke
7. no critical regression

No direct production destructive migration without backup/rollback strategy.

---

# 13. MONDAY MILESTONE DEFINITION

The milestone is **not** “CRM complete”.

The milestone is successful if these are true:

## Domain

- Customer/Application architecture final
- Consumer Application lifecycle works
- id_transaksi immutable
- kavling lifecycle safe
- critical process stages usable
- bank attempt history safe
- BAST readiness rule implemented or verified

## UX

- Consumer Database workspace usable
- search works
- responsive key workflow works
- activity understandable

## Integration

- Lead → UTJ contract implemented or production-ready behind controlled rollout
- Google compatibility not broken

## Quality

- automated P0 tests pass
- no known critical data-integrity blocker

---

# 14. DO NOT DO

Agent must NOT:

- rewrite OASIS from scratch
- replace Laravel architecture unnecessarily
- create duplicate models for existing semantics
- remove Google compatibility
- switch production read source silently
- introduce complex approval workflow
- hard-code one Customer = one Application
- block duplicate phone
- overwrite bank history
- overwrite transaction identity on pindah kavling
- hide critical data correction behind superadmin-only access
- implement P2 before P0
- claim completion without tests
- mutate production data just to test

---

# 15. IMPLEMENTATION REPORT FORMAT

After every meaningful phase, report:

```markdown
## Phase X — <name>

### Status
PASS / PARTIAL / BLOCKED

### Existing code reused
- ...

### Changes
- ...

### Migrations
- ...

### Tests
- test name — PASS
- ...

### Data-integrity checks
- ...

### Remaining gaps
- ...

### Production impact
- NONE / LOW / MEDIUM / HIGH

### Rollback
- ...
```

---

# 16. FINAL ACCEPTANCE CHECKLIST

## Customer
- [ ] One Customer can have multiple Leads
- [ ] One Customer can have multiple Applications
- [ ] No silent person merge by phone

## Application
- [ ] Immutable transaction identity
- [ ] Current stage reliable
- [ ] Current status reliable
- [ ] History retained

## Kavling
- [ ] One active owner
- [ ] Pindah atomic
- [ ] Old history retained
- [ ] Mundur release policy correct

## Bank
- [ ] Multiple attempts
- [ ] Ganti Bank creates new attempt
- [ ] SP3K belongs to correct attempt

## Lifecycle
- [ ] SLIK
- [ ] PSJB
- [ ] Pemberkasan
- [ ] SP3K
- [ ] PPJB
- [ ] Akad
- [ ] BAST

## Readiness
- [ ] BAST requires Akad + Ready100
- [ ] SLA anchor correct

## Lead handoff
- [ ] UTJ creates/links Customer
- [ ] UTJ creates one Application idempotently
- [ ] Lead retained

## Audit
- [ ] Correction allowed
- [ ] Actor retained
- [ ] Before/after retained

## UX
- [ ] Desktop
- [ ] Tablet
- [ ] Mobile
- [ ] Search
- [ ] Activity

## Compatibility
- [ ] Existing Google bridge unaffected
- [ ] Migration/reconciliation unaffected
- [ ] Rollout reversible

---

# 17. DEFINITION OF DONE

Consumer Database V1 is DONE only when:

> A branch can operate a consumer from UTJ through the critical lifecycle in OASIS, correct operational mistakes without destructive data loss, preserve immutable transaction and history integrity, safely manage kavling and bank attempts, find the consumer quickly from any supported device, and continue using Google compatibility during transition.

Anything beyond that is an enhancement—not a prerequisite for declaring the Consumer Database core architecture valid.
