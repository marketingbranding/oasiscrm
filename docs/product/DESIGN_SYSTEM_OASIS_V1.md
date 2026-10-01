# DESIGN SYSTEM — OASIS V1

**Product:** OASIS  
**Company:** Marison Regency Group  
**Document Type:** UI/UX Design System + Workspace Contract  
**Primary Scope:** Lead, Consumer Database, Dana Talangan, Tasks, Notifications  
**Product Direction:** Modern internal workspace inspired by Lark-style productivity patterns, without copying Lark  
**Implementation Priority:** Consumer Database V1 first  
**Platform:** Responsive Web Application / PWA  
**Design Principle:** Dense where scanning matters, spacious where thinking matters

---

# 1. DESIGN VISION

OASIS adalah **operational workspace**, bukan kumpulan form dan bukan sekadar dashboard.

User harus merasa bahwa seluruh pekerjaan utama dapat dilakukan dari satu workspace yang konsisten:

> **Cari → Buka → Pahami → Lakukan Tindakan → OASIS menjaga histori dan integritas.**

Backend OASIS boleh kompleks. User tidak perlu melihat kompleksitas seperti transaction locking, stage event, reconciliation, bank attempt lineage, audit metadata, atau migration identity.

User cukup melihat bahasa bisnis yang sederhana:
- Pindah Kavling
- Ganti Bank
- Buat Task
- Konsumen Mundur
- Ganti Konsumen
- Koreksi Data

Tujuan desain OASIS bukan terlihat “canggih”. Tujuannya adalah:

> **Terasa ringan, cepat, jelas, dan mudah dipakai setiap hari.**

---

# 2. DESIGN CHARACTER

OASIS harus terasa:
- modern
- profesional
- internal-workspace oriented
- cepat
- data-dense
- tidak ramai
- tidak terlalu dekoratif
- tidak menyerupai website marketing
- konsisten antar modul

OASIS boleh mengambil inspirasi dari pola interaction workspace modern seperti Lark, Airtable, Notion database, Linear, dan internal operations tools, tetapi tidak menyalin identitas visual atau layout produk tertentu.

---

# 3. CORE UX PRINCIPLES

## 3.1 Workspace First
Jangan memindahkan user ke halaman baru untuk setiap pekerjaan kecil. Gunakan record drawer, side panel, modal, popover, bottom sheet di mobile, dan quick action.

## 3.2 Preserve Context
Ketika user membuka record, melakukan tindakan, lalu menutup drawer, filter, search, sort, selected view, dan posisi scroll harus tetap.

## 3.3 One Clear Primary Action
Setiap screen harus memiliki primary action yang jelas. Contoh:
- Lead → `+ Tambah Lead`
- Task → `+ Task`

## 3.4 Progressive Disclosure
Tampilkan data penting terlebih dahulu. Data detail muncul ketika diperlukan.

## 3.5 Business Language First
Gunakan istilah operasional yang dipahami user:
- Belum Akad
- Pindah Kavling
- Ganti Bank
- Konsumen Mundur
- Perlu Perhatian

Hindari internal naming seperti `akad_status = null`, `consumer_stage_event`, dan `exception_event`.

## 3.6 Fast Correction
Routine operational correction harus cepat. Field sederhana boleh inline/lightweight edit. Critical business event harus memakai dedicated action.

## 3.7 Audit Without Friction
Audit kuat di backend tetapi tidak menghambat workflow normal.

## 3.8 Reuse Before New UI
Sebelum membuat component baru, audit component yang sudah tersedia.

---

# 4. APPLICATION SHELL

Semua module OASIS menggunakan shell yang sama.

## 4.1 Desktop Shell

Target utama `>= 1280px`.

```text
┌─────────────────┬────────────────────────────────────────────────┐
│                 │ Workspace Header                               │
│                 ├────────────────────────────────────────────────┤
│     SIDEBAR     │                                                │
│                 │                MAIN WORKSPACE                  │
│                 │                                                │
└─────────────────┴────────────────────────────────────────────────┘
```

Sidebar target width: `220–240px`  
Collapsed width: `56–64px`

## 4.2 Sidebar Information Architecture

```text
OASIS

Home

WORKSPACE
Lead
Database Konsumen
Dana Talangan

PRODUCTIVITY
Tasks
Notifications

More
```

Admin/configuration ditempatkan di area More/Settings/Admin.

## 4.3 Sidebar Rules
Sidebar tidak boleh berubah total berdasarkan role. Role menentukan data dan action availability, bukan membuat struktur aplikasi terasa berbeda.

---

# 5. WORKSPACE HEADER

Contoh:

```text
Database Konsumen                         [Search]        [+]
Kelola perjalanan konsumen dari UTJ sampai BAST
```

Second row:

```text
All Consumers | My View | SP3K Belum Akad

Table   Kanban   Calendar   Dashboard

Filter   Sort   Group   Fields   More
```

Header harus compact agar area data tetap dominan.

---

# 6. RESPONSIVE STRATEGY

## Desktop (`>= 1200px`)
- full sidebar
- data table primary
- right drawer
- multi-column filters
- dense information

## Tablet (`768px – 1199px`)
- collapsible sidebar
- reduced columns
- wider drawer
- toolbar may wrap
- advanced controls masuk More

## Mobile (`< 768px`)
- desktop table bukan primary interface
- gunakan card/list
- full-screen record detail
- bottom sheet untuk actions
- touch targets cukup besar

---

# 7. MOBILE PRINCIPLE

Jangan hanya mengecilkan desktop.

Consumer mobile list:

```text
Budi Santoso

A-12 · SP3K
BTN
Sales: Andi

3 hari di tahap ini                         >
```

Primary information:
1. Name
2. Kavling / Stage
3. Bank/payment context
4. Sales
5. Attention state

---

# 8. INFORMATION DENSITY

Desktop data-table row: `44–48px`  
Mobile card: `72–96px`

> **Dense where scanning matters. Spacious where thinking matters.**

---

# 9. TYPOGRAPHY SYSTEM

Gunakan existing product font stack. Jangan menambah external font dependency tanpa alasan.

| Usage | Size |
|---|---:|
| Page title | 20–24px |
| Large metric | 20–28px |
| Section title | 16–18px |
| Body | 14px |
| Table | 13–14px |
| Supporting text | 12–13px |
| Badge | 11–12px |

---

# 10. SPACING TOKENS

```text
space-1 = 4px
space-2 = 8px
space-3 = 12px
space-4 = 16px
space-5 = 20px
space-6 = 24px
space-8 = 32px
space-10 = 40px
```

Hindari arbitrary spacing kecuali diperlukan.

---

# 11. RADIUS TOKENS

```text
radius-sm = 6px
radius-md = 8px
radius-lg = 12px
radius-xl = 16px
```

---

# 12. SURFACE SYSTEM

```text
surface-base
surface-subtle
surface-raised
surface-overlay
surface-selected

border-default
border-subtle
border-strong
```

Jangan memakai shadow untuk semua section.

---

# 13. COLOR PHILOSOPHY

Gunakan semantic color:
- neutral
- info
- success
- warning
- danger

Color tidak boleh menjadi satu-satunya informasi.

---

# 14. STATUS BADGES

Semua module memakai sistem badge yang sama:

```text
[ SLIK ]
[ SP3K ]
[ AKAD ]
[ BAST ]
[ MUNDUR ]
```

Compact, consistent, tidak terlalu saturated.

---

# 15. BUTTON HIERARCHY

- Primary → satu primary action per area
- Secondary → common action
- Ghost → toolbar/minor action
- Danger → high-impact/destructive

Jangan menampilkan banyak primary button sekaligus.

---

# 16. DATA TABLE — CORE COMPONENT

Required V1:
- sticky header
- row hover
- selected state
- sort
- filter
- column visibility
- pagination/virtualization
- responsive reduction
- quick actions

Later:
- column resize
- grouping
- custom saved views

---

# 17. DEFAULT CONSUMER TABLE

Recommended columns:

```text
Nama
ID Transaksi
Project
Kavling
Stage
Bank / Payment
Sales
SLA / Attention
Updated
```

Jangan menampilkan semua field secara default.

---

# 18. TABLE ROW INTERACTION

Primary:
`Click row → open record drawer`

Row hover boleh menampilkan:
`Open` dan `⋯`

Jangan memenuhi row dengan banyak icon buttons.

---

# 19. TABLE INLINE EDITING

Allowed:
- phone
- email
- simple non-critical date correction
- notes
- classification

Not allowed inline:
- Pindah Kavling
- Ganti Bank
- Mundur
- Ganti Konsumen
- Akad
- BAST completion
- transaction identity

---

# 20. RECORD DRAWER

Desktop width: `440–520px`

```text
┌────────────────────────────────────────┐
│ Budi Santoso                       ×   │
│ TRX-MGL-01H...                         │
│ A-12 · SP3K                            │
│                                        │
│ [Overview] [Process] [Payment]         │
│ [Files] [Activity] [Comments]          │
│                                        │
│ Project          Magelang              │
│ Kavling          A-12                  │
│ Sales            Andi                  │
│ Bank             BTN                   │
│                                        │
│ [Create Task]                    [⋯]   │
└────────────────────────────────────────┘
```

---

# 21. DRAWER TABS

Consumer Application:
1. Overview
2. Process
3. Payment
4. Files
5. Activity
6. Comments

Jangan membuat terlalu banyak tab.

---

# 22. OVERVIEW TAB

Show:
- Customer
- Project
- Sales
- Kavling
- Current Stage
- Payment Method
- Current Bank
- Primary status
- Attention/SLA state

---

# 23. PROCESS TAB

```text
✓ UTJ
  01 Oct 2026

✓ SLIK
  Lolos

✓ PSJB
  03 Oct 2026

● Bank
  BTN
  Sedang proses

○ SP3K
○ PPJB
○ Akad
○ BAST
```

Retry/history tersedia tanpa mengganggu summary utama.

---

# 24. ACTIVITY TAB

Gunakan human language:

```text
01 Oct · 16:30
Andi mengganti bank
BTN → BSN
```

```text
01 Oct · 15:21
Admin Magelang mengubah tanggal SP3K
30 Sep → 01 Oct
```

Raw metadata bukan primary UX.

---

# 25. ACTION MENU

Gunakan satu menu:

`⋯ Tindakan`

Consumer actions:
- Pindah Kavling
- Ganti Bank
- Ganti Konsumen
- Konsumen Mundur
- Koreksi Data

Jangan render semua menjadi permanent button.

---

# 26. CRITICAL BUSINESS ACTION UX

Example Ganti Bank:

```text
Ganti Bank

Bank Saat Ini
BTN

Bank Baru
[ BSN                         ▼ ]

Tanggal
[ 01/10/2026 ]

Alasan
[________________________________]

Catatan
[________________________________]

[ Batal ]                     [ Ganti Bank ]
```

Success toast:
`✓ Bank berhasil diganti`

Activity:
`BTN → BSN`

---

# 27. PINDAH KAVLING UX

```text
Pindah Kavling

Kavling Saat Ini
A-12

Kavling Tujuan
[ B-08                        ▼ ]

Tanggal
[ 01/10/2026 ]

Alasan
[________________________________]

Catatan
[________________________________]

[ Batal ]                [ Pindah Kavling ]
```

Jika unit berubah availability:
> Kavling B-08 baru saja digunakan konsumen lain. Pilih kavling lain.

---

# 28. GANTI KONSUMEN UX

High-impact action.

Explain:
> Transaksi lama akan tetap disimpan sebagai histori. OASIS akan membuat transaksi baru untuk konsumen pengganti.

Jangan menggunakan bahasa "replace row".

---

# 29. MUNDUR UX

Form:
- tanggal
- alasan
- notes
- summary consequence kavling

Copy:
> Konsumen akan ditandai Mundur. Histori transaksi tetap disimpan.

---

# 30. SEARCH DESIGN

Global search: `Ctrl/Cmd + K`

Groups:
- Customer
- Application
- Lead
- Kavling
- Task

Example:

```text
Budi Santoso
Customer
0812xxxx

TRX-MGL-01H...
Application
A-12 · SP3K

A-12
Kavling
Magelang
```

---

# 31. FILTER UX

Prefer chips:

```text
Project: Magelang ×
Stage: SP3K ×
Bank: BTN ×
```

Advanced filter opens panel.

---

# 32. SAVED VIEW UX

Examples:
- All Consumers
- My Consumers
- SP3K Belum Akad
- Akad Bulan Ini
- SLA Overdue

Save:
- filters
- sort
- group
- visible fields
- layout

Scope:
- Private
- Team
- Branch
- Global if allowed

---

# 33. KANBAN VIEW

Grouping:
- Stage
- Status
- Sales
- Bank
- Project

Card compact, not overloaded.

---

# 34. CALENDAR VIEW

Possible anchors:
- follow-up date
- site visit
- task due date
- payment due
- target Akad
- milestone

Calendar secondary to Table for database work.

---

# 35. DASHBOARD VIEW

Avoid chart wall.

Dashboard answers:
- What needs attention?
- What is progressing?
- Are we on target?
- Where is the bottleneck?

Recommended widgets:
- Active Consumers
- Akad MTD
- SP3K Belum Akad
- SLA Overdue
- Stage Distribution
- Target vs Actual

---

# 36. ROLE-BASED HOME

Home is a work queue, not merely analytics.

## Sales
```text
Hari Ini
12 Lead perlu follow-up
3 Task jatuh tempo
2 Site Visit

Pipeline
No Response    22
Discussion     18
Site Visit      5
UTJ             3

Target
Lead       148 / 200
UTJ          6 / 9
```

## Koordinator
- team lead volume
- team follow-up
- conversion
- team tasks
- needs attention

## SPV/BM
- branch pipeline
- target
- akad
- overdue
- data quality
- team performance

## Pusat/Direksi
- branch comparison
- sales funnel
- akad
- SLA
- attention list

---

# 37. LEAD WORKSPACE CONTRACT

Views:
- Table
- Kanban
- Calendar
- Dashboard

Record detail:
- Overview
- Activity
- Agenda
- Files
- Comments

Primary action:
`+ Tambah Lead`

UTJ harus visually distinct dari simple status change.

---

# 38. LEAD MOBILE UX

```text
Budi Santoso
TikTok · Live
No Response

Project Magelang
Sales Andi

Follow-up hari ini                      >
```

Quick actions:
- Call
- WhatsApp if integrated
- Update status
- Create task
- Site visit
- UTJ

---

# 39. DANA TALANGAN WORKSPACE

Gunakan shell yang sama.

```text
Dana Talangan

All
Aktif
Jatuh Tempo
Selesai

Table | Dashboard
```

Reuse table, filter, badge, drawer, activity, task primitives.

---

# 40. TASK SYSTEM UX

Keep lightweight.

```text
My Tasks

Today
□ Follow up Budi
□ Persiapan PPJB — Rina

Upcoming
□ Site Visit Dimas
```

No complex project-management features in V1.

---

# 41. NOTIFICATION CENTER

Actionable notifications.

```text
Andi mentioned you
Budi Santoso

"Dokumen BTN sudah masuk."

2 menit lalu
```

```text
Task jatuh tempo hari ini
Persiapan PPJB — Rina
```

Click opens related record.

---

# 42. COMMENTS & @MENTION

Compact:
- avatar/name
- timestamp
- mention
- simple reply

Comments bukan structured process data.

---

# 43. FILE ATTACHMENT

Suggested categories:
- KTP
- KK
- Slip Gaji
- Dokumen Bank
- Lainnya

Show file name, uploader, date, category.

---

# 44. LOADING STATES

Avoid full-screen spinner for minor actions.

Use:
- table skeleton
- drawer skeleton
- button loading
- row-level state
- optimistic update if safe

---

# 45. TOAST SYSTEM

Success:
- `✓ Perubahan tersimpan`
- `✓ Kavling berhasil dipindahkan`

Warning:
- `Data berubah sejak halaman ini dibuka.`

Error:
- `Kavling B-08 sudah digunakan konsumen lain.`

No browser alert dialogs.

---

# 46. CONFIRMATION RULES

Confirmation only for:
- Mundur
- Ganti Konsumen
- destructive archive/delete
- high-impact bulk action

No unnecessary confirmation after a dedicated action form.

---

# 47. ERROR UX

Never expose raw SQL or exception text.

Good:
> Kavling B-08 sudah digunakan konsumen lain. Pilih kavling lain.

Good:
> Kamu tidak memiliki akses untuk mengubah data cabang ini.

---

# 48. EMPTY STATES

Bad:
`No data`

Good:
```text
Belum ada konsumen SP3K.

Konsumen akan muncul di sini setelah proses bank mencapai SP3K.
```

---

# 49. DATA QUALITY UX

Use:
`Perlu Perhatian`

Examples:
- NIK belum lengkap
- Bank belum dipilih
- SP3K belum memiliki tanggal
- Dokumen belum lengkap

Warn rather than block unless integrity is at risk.

---

# 50. DARK MODE

Not required for Consumer Database V1.

Avoid hard-coded colors so future dark mode stays possible.

---

# 51. ACCESSIBILITY MINIMUM

- visible keyboard focus
- adequate touch targets
- important actions not icon-only
- status not color-only
- adequate contrast
- form errors linked to fields

---

# 52. PERFORMANCE UX

- drawer opens quickly
- common mutation does not reload full workspace
- filters respond quickly
- dashboard may use cached summaries
- long tables use pagination/virtualization

---

# 53. FORM DESIGN

Use grouped sections.

Avoid giant 30–50 field forms.

```text
IDENTITAS
Nama
NIK
Phone

TRANSAKSI
Project
Sales
Payment

CATATAN
...
```

Mobile uses one column.

---

# 54. FORM VALIDATION

Validate near the field when practical. Backend remains authoritative.

---

# 55. DATE & NUMBER FORMAT

Display:
`01 Okt 2026`

Money:
`Rp1.070.000`

Keep canonical values internally.

---

# 56. MICROCOPY

Use short, direct Indonesian.

Prefer:
- `Simpan`
- `Konsumen Mundur`
- `Ganti Bank`

Avoid unnecessarily technical English.

---

# 57. ICONS

Use existing icon library.

Important actions should include labels, not icon-only controls.

---

# 58. DESIGN TOKENS IMPLEMENTATION

Before UI implementation:
1. audit current CSS/Tailwind/component system
2. map existing values to semantic tokens
3. define only missing tokens
4. reuse current architecture

Do not create a parallel styling system unnecessarily.

---

# 59. SCREEN CONTRACT — HOME

**Purpose:** show today's priorities and key progress.  
**Primary interaction:** open work item.

Desktop:
- role summary
- work queue
- core metrics
- attention list

Mobile:
- priority cards
- compact KPI
- quick actions

Don't fill page with decorative charts.

---

# 60. SCREEN CONTRACT — LEAD

**Purpose:** manage acquisition and follow-up.  
**Primary action:** `+ Tambah Lead`  
**Default desktop view:** Table  
**Record interaction:** Drawer  
**Important actions:** update manual status, create task, site visit, UTJ  
**Mobile:** Card/list

---

# 61. SCREEN CONTRACT — DATABASE KONSUMEN

**Purpose:** operate current consumer lifecycle.  
**Primary interaction:** search/open existing consumer.  
**Desktop:** Table + record drawer.  
**Mobile:** Consumer cards + full-screen detail.

Important actions:
- Pindah Kavling
- Ganti Bank
- Ganti Konsumen
- Mundur
- Corrections
- Create Task

---

# 62. SCREEN CONTRACT — DANA TALANGAN

**Purpose:** manage Dana Talangan records.  
**Desktop:** Table + dashboard + drawer.  
**Mobile:** Compact list + detail.  
**Rule:** reuse OASIS shell/components.

---

# 63. SCREEN CONTRACT — TASKS

**Purpose:** personal/team work queue.

Views:
- Mine
- Team if permitted
- Today
- Upcoming
- Completed

---

# 64. SCREEN CONTRACT — NOTIFICATIONS

**Purpose:** surface events requiring awareness/action.

Sections:
- Unread
- All

Click opens related record.

---

# 65. FORBIDDEN PATTERNS

Agent must NOT:
- create a different sidebar for every module
- make Dana Talangan visually isolated
- use desktop horizontal table as mandatory mobile workflow
- full reload after every small mutation
- expose technical IDs as the only primary label
- show raw SQL/exception messages
- use excessive colors
- repeatedly stack modal over modal
- create giant single-page forms
- duplicate existing components
- use confirmation for routine edits
- show every action as permanent button
- fill dashboard with charts without operational purpose
- hide critical action behind unclear icon-only control
- reset filters after closing record detail

---

# 66. COMPONENT REUSE CHECKLIST

Before adding a component:
- [ ] Existing component searched
- [ ] Existing utility classes reviewed
- [ ] Similar screen reviewed
- [ ] Semantic token reuse considered
- [ ] Responsive behavior defined
- [ ] Loading/error/empty states defined

---

# 67. DESIGN ACCEPTANCE — GLOBAL

PASS if:
- one consistent app shell
- common workflows avoid unnecessary page changes
- desktop remains data-efficient
- mobile does not depend on desktop table
- actions use business language
- status visuals are consistent
- common corrections are fast
- critical actions use dedicated workflow
- errors are understandable
- context persists after drawer close
- components are reused

---

# 68. DESIGN ACCEPTANCE — CONSUMER DATABASE

PASS if an authorized user can:
1. locate a consumer quickly
2. understand current state from list/drawer
3. open process history
4. perform business action without many page changes
5. correct ordinary data quickly
6. see resulting activity
7. return to exact previous table context
8. perform key workflow from phone

---

# 69. DESIGN ACCEPTANCE — MOBILE

PASS if:
- consumer search works
- list scanning works
- record detail works
- Pindah Kavling works
- Ganti Bank works
- Task creation works
- no critical workflow requires wide-table horizontal scrolling

---

# 70. IMPLEMENTATION PRIORITY

```text
1. App shell
2. Reusable toolbar/table/drawer primitives
3. Consumer Database desktop
4. Consumer detail drawer
5. Mobile consumer list/detail
6. Search
7. Business action forms
8. Activity
9. Lead workspace reuse
10. Dana Talangan reuse
11. Tasks/Notifications
12. Kanban/Calendar/Dashboard enhancements
```

Do not build every surface simultaneously.

---

# 71. RELATIONSHIP WITH OTHER DOCUMENTS

Use together with:
1. `PRD_OASIS_CRM_Consumer_Database_Platform.md`
2. `IMPLEMENTATION_PLAN_OASIS_V1.md`
3. `AGENT_PROMPT_CONSUMER_DATABASE_V1.md`
4. `DESIGN_SYSTEM_OASIS_V1.md`

Priority:
- PRD defines product behavior.
- Implementation Plan defines technical order.
- Design System defines user experience.
- Current code/tests define actual technical baseline.

---

# 72. FINAL DESIGN STATEMENT

The ideal OASIS screen does not make the user think about software architecture.

The user sees:

```text
Budi Santoso
A-12
SP3K
BTN
Sales Andi

[Create Task] [⋯ Tindakan]
```

The system handles:

```text
transactions
locks
bank attempts
stage events
audit
SLA
reconciliation
permissions
```

> **Complex system, simple workspace.**
