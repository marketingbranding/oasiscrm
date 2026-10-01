# PRD — OASIS CRM & Consumer Database Platform

**Product:** OASIS  
**Company:** Marison Regency Group  
**Document Status:** Master Product Requirement  
**Product Direction:** Oasis First  
**Primary Milestone:** Consumer Database V1  
**Architecture Direction:** Lead → Customer → Consumer Application → Transaction Lifecycle  
**Target Platform:** Responsive Web Application / PWA  
**Primary Users:** Sales, Koordinator, SPV, BM, Admin Cabang, Marketing Pusat, Direksi, Superadmin

---

## 1. Product Vision

OASIS adalah sistem kerja internal Marison Regency Group yang berfungsi sebagai pusat data operasional Marketing dan Consumer Database.

OASIS tidak ditujukan menjadi aplikasi kolaborasi selengkap Lark. Konsep Lark digunakan sebagai inspirasi pada pengalaman kerja: cepat, fleksibel, kolaboratif, searchable, mudah berpindah konteks, dan nyaman dipakai dari berbagai perangkat.

Fokus utama OASIS adalah:

**Lead Management → Consumer Database → Proses Penjualan → Monitoring → Collaboration → Reporting.**

OASIS harus menjadi tempat utama user bekerja, bukan hanya dashboard yang membaca data dari sistem lain.

Google Sheets tetap dipertahankan untuk kebutuhan compatibility, backup operasional, import/export, dan masa transisi, tetapi arah jangka panjang adalah:

> **OASIS menjadi operational source of truth utama Marison.**

---

## 2. Product Principles

### 2.1 Oasis First
Aktivitas operasional baru sebisa mungkin dilakukan langsung dari OASIS. Google Sheets tidak menjadi pusat workflow baru. Jika Google Sheet masih digunakan, fungsinya adalah sebagai compatibility layer, migration bridge, backup, atau export surface.

### 2.2 One Customer, Many Journeys
Satu orang tidak sama dengan satu transaksi.

**Customer / Person** dapat memiliki:
- beberapa lead,
- beberapa proyek yang diminati,
- beberapa Sales Owner,
- beberapa histori transaksi,
- transaksi yang pernah mundur,
- transaksi cash,
- transaksi komersial,
- atau transaksi KPR.

Contoh:

**Budi Santoso**
- Lead Magelang — Sales A
- Lead Boyolali — Sales B
- Transaksi 2026 — Mundur
- Transaksi 2028 — Cash Komersial

Semua tetap berhubungan ke orang yang sama tanpa menghapus histori sebelumnya.

### 2.3 Lead Becomes Consumer at UTJ
Titik konversi resmi:

**LEAD → UTJ → CONSUMER APPLICATION**

Sebelum UTJ, data masih berada di domain Lead. Saat UTJ terjadi:
1. Oasis mencari atau membuat Customer.
2. Oasis membuat Consumer Application.
3. Oasis menghubungkan Lead dengan Consumer Application.
4. Perjalanan transaksi resmi dimulai.

UTJ tidak boleh hanya menjadi perubahan label status Lead. UTJ adalah **business event**.

### 2.4 Flexible Operation, Strong Audit
OASIS tidak boleh terlalu membatasi user internal. Kesalahan data operasional boleh diperbaiki tanpa approval berlapis.

Oasis wajib menyimpan:
- nilai sebelumnya,
- nilai sesudahnya,
- user yang mengubah,
- waktu perubahan,
- sumber perubahan.

> **Lebih banyak audit, lebih sedikit larangan.**

### 2.5 Protect Integrity, Not Bureaucracy
Pembatasan keras hanya diterapkan pada hal yang dapat merusak struktur sistem:
- ID transaksi canonical,
- audit history,
- double assignment kavling,
- duplicate active assignment,
- hubungan antar-record,
- credential,
- permission administration,
- concurrent update,
- destructive deletion,
- immutable system identity.

---

## 3. Product Goals

OASIS harus mampu:
1. Menjadi database konsumen utama Marison.
2. Menyatukan perjalanan Lead sampai BAST.
3. Mengurangi ketergantungan operasional terhadap Google Sheets.
4. Menyediakan satu tempat untuk mencari seluruh data konsumen.
5. Menjaga histori transaksi tanpa kehilangan jejak.
6. Memberikan pengalaman kerja sederhana seperti workspace modern.
7. Mendukung kerja Sales, Koordinator, SPV, Cabang, dan Pusat dengan tampilan sesuai kebutuhan masing-masing.
8. Mendukung monitoring target dan KPI Marketing.
9. Mempermudah follow-up melalui task, reminder, dan notification.
10. Menjadi CRM-ready untuk integrasi lead eksternal.
11. Mendukung data lintas cabang dan lintas proyek.
12. Tetap fleksibel terhadap perubahan struktur organisasi Marison.

---

## 4. Non-Goals
Untuk tahap awal OASIS tidak ditujukan menjadi pengganti penuh Lark, Slack, WhatsApp, Google Drive, accounting system, HRIS, project management kompleks, document editor, video meeting, atau ERP penuh.

Fitur kolaborasi hanya dibuat jika berhubungan langsung dengan Lead, Consumer Database, Dana Talangan, Task, atau proses penjualan.

---

## 5. Product Architecture

```text
Lead
↓
UTJ
↓
Customer
↓
Consumer Application
↓
SLIK
↓
PSJB
↓
Pemberkasan
↓
Bank / SP3K
↓
PPJB Developer
↓
Akad
↓
BAST
```

Consumer Application adalah pusat perjalanan transaksi. Customer adalah identitas orang. Lead adalah sumber acquisition.

---

## 6. Primary Domain Model

### 6.1 Customer
Mewakili satu individu.

Field inti:
- customer_id
- nama
- NIK
- nomor telepon utama
- nomor telepon alternatif
- tanggal lahir
- alamat
- pekerjaan
- metadata identitas
- created_at
- updated_at

Customer tidak memiliki status proses rumah. Status proses berada pada Consumer Application.

### 6.2 Sales Lead
Mewakili satu peluang pemasaran.

Field inti:
- lead_id
- lead_date
- customer_name
- phone
- normalized_phone
- branch_id
- project_id
- sales_owner_id
- source
- channel
- activity
- campaign
- promo
- current_status
- notes
- customer_id jika sudah ditemukan
- consumer_application_id jika telah UTJ
- created_by
- updated_by

Satu Customer dapat memiliki beberapa Sales Lead aktif. Nomor HP yang sama tidak otomatis berarti Lead yang sama. Duplicate hanya menjadi warning, bukan blocking rule.

---

## 7. Lead Ownership
Setiap Lead memiliki satu **Sales Owner**. Tidak diperlukan field Originator.

Jika Lead awalnya diperoleh Koordinator, SPV, atau sumber internal lain, secara operasional Lead tersebut harus diberikan kepada Sales. Sales yang menerima menjadi Sales Owner resmi di sistem.

Ownership dapat dipindahkan oleh user yang memiliki permission dan harus tercatat dalam history.

---

## 8. Lead Status

### Manual Lead Status
- No Response
- Discussion
- Face to Face
- Site Visit

### System / Business Event Status
- UTJ
- SLIK
- SLIK Rejected
- Akad

Status system harus muncul karena event bisnis terkait benar-benar dilakukan, bukan sekadar dropdown.

---

## 9. Lead Duplicate Policy
Duplicate tidak boleh menjadi hard block.

Jika nomor telepon sudah ditemukan, OASIS memberikan informasi lead lain terkait:
- nama,
- Sales Owner,
- cabang,
- proyek,
- tanggal lead,
- status.

User tetap boleh menyimpan Lead baru.

---

## 10. Lead → UTJ Conversion
Ketika user melakukan action **Mark as UTJ**, OASIS:
1. Validasi Lead.
2. Cari Customer existing.
3. Jika tidak ditemukan, buat Customer.
4. Jika ambigu, user dapat memilih Customer atau membuat baru.
5. Buat Consumer Application.
6. Assign project.
7. Assign Sales Owner.
8. Jika kavling sudah dipilih, assign kavling.
9. Catat tanggal UTJ.
10. Hubungkan Lead ke Consumer Application.
11. Buat activity history.
12. Update lifecycle Lead.

Lead tetap tersimpan dan menjadi sumber acquisition dari Consumer Application.

---

## 11. Consumer Application
Consumer Application adalah representasi satu perjalanan pembelian rumah.

Field inti:
- id_transaksi / application_id
- customer_id
- source_lead_id
- branch_id
- project_id
- sales_owner_id
- current_kavling_id
- payment_method
- application_status
- current_stage
- booking / UTJ date
- SLIK status
- PSJB status
- bank status
- SP3K status
- PPJB status
- akad status
- BAST status
- migration metadata
- created_by
- updated_by

---

## 12. Transaction Identity
`id_transaksi` harus immutable.

Tidak boleh berubah hanya karena pindah kavling, ganti bank, revisi dokumen, atau perubahan status.

**Ganti Konsumen / Ganti Nama** harus menghasilkan Consumer Application baru jika identitas pembelinya benar-benar berubah. Transaksi lama tetap disimpan.

---

## 13. Consumer Application Status
Minimal status:
- ACTIVE
- REVIEW
- WITHDRAWN / MUNDUR
- REPLACED / GANTI NAMA
- COMPLETED
- REJECTED jika dibutuhkan secara bisnis

Status tidak dihapus. Perubahan status tersimpan dalam history.

---

## 14. Current Stage
Current Stage dihitung dari milestone proses:
- DATA
- UTJ
- SLIK
- PSJB
- PEMBERKASAN
- BANK
- SP3K
- PPJB
- AKAD
- BAST

Stage sebaiknya derived dari business facts, bukan hanya dropdown manual.

---

## 15. Kavling Lifecycle
Status minimal:
- AVAILABLE
- RESERVED
- SOLD

Consumer Application dapat memiliki maksimal satu assignment aktif. Kavling juga hanya dapat memiliki maksimal satu owner aktif.

---

## 16. Pindah Kavling
User menjalankan **Action → Pindah Kavling**.

Form:
- Kavling sekarang
- Kavling tujuan
- Tanggal
- Alasan
- Catatan

Backend:
1. Lock transaksi.
2. Validasi kavling tujuan.
3. Release assignment lama.
4. Assign kavling baru.
5. Update current kavling.
6. Simpan kavling history.
7. Simpan activity.
8. Refresh reporting/readiness.

`id_transaksi` tetap.

---

## 17. Mundur
Action: **Konsumen Mundur**

Input:
- tanggal,
- alasan,
- catatan.

Backend:
- transaction status → MUNDUR
- active SLA berhenti
- kavling dilepas sesuai policy
- history disimpan
- transaksi tidak dihapus

---

## 18. Ganti Konsumen
UI menggunakan istilah **Ganti Konsumen**.

Sistem:
1. Tutup Consumer Application lama.
2. Buat Consumer Application pengganti.
3. Hubungkan replacement relationship.
4. Transfer data yang diperbolehkan.
5. Jangan menghapus transaksi lama.
6. Proses current kavling sesuai rule.
7. Catat hubungan transaksi lama → baru.

---

## 19. Bank Attempt
Bank tidak boleh disimpan hanya sebagai satu field yang terus ditimpa.

Contoh:
```text
Application: TRX-001

Bank Attempt #1
BTN
REJECT

Bank Attempt #2
BSN
PROCESS

Bank Attempt #3
BSN
APPROVED
```

Semua histori tetap ada.

---

## 20. Ganti Bank
Action: **Ganti Bank**

Input:
- bank baru,
- tanggal,
- alasan,
- catatan.

Sistem membuat bank attempt baru. Attempt lama tidak ditimpa.

---

## 21. SLIK
Data minimal:
- tanggal,
- hasil,
- keputusan,
- catatan,
- source,
- actor.

SLIK reject tidak selalu berarti transaksi mundur. User dapat lanjut, revisi, ganti skenario, atau mundur.

---

## 22. PSJB
Data relevan:
- tanggal,
- Sales,
- Koordinator jika diperlukan,
- harga,
- UTJ,
- DP,
- metode pembayaran,
- promo,
- catatan,
- reference number.

---

## 23. Pemberkasan
Field umum:
- tanggal,
- bank,
- nomor/reference,
- status,
- plafond,
- tenor,
- catatan,
- completeness status.

---

## 24. SP3K / Bank
SP3K canonical berasal dari proses bank. PPJB tidak boleh menjadi sumber canonical SP3K jika hanya menyimpan mirror data.

---

## 25. PPJB
Data:
- tanggal,
- reference,
- catatan,
- actor.

---

## 26. Akad
Data Akad mencakup:
- tanggal,
- readiness,
- status DP,
- kondisi bangunan,
- utilitas,
- kendala konsumen,
- kendala bank,
- catatan.

---

## 27. BAST
BAST baru dianggap ready jika:
- Akad sudah terjadi, DAN
- Ready100 sudah tersedia.

Anchor SLA:
**MAX(Tanggal Akad, Tanggal Ready100)**

---

## 28. SLA Engine
Baseline:
- PSJB → Pemberkasan: 5 hari
- Pemberkasan → SP3K: 10 hari
- SP3K → PPJB: 10 hari
- PPJB → Akad: 3 hari
- Akad + Ready100 → BAST: 1 hari

SLA berhenti pada terminal event, tidak terus aging pada transaksi selesai, dan mendukung due date, overdue, serta reminder.

---

## 29. Payment Domain
Payment method minimal:
- KPR
- CASH
- CASH BERTAHAP
- KOMERSIAL
- BELUM DITENTUKAN

Arsitektur:
```text
Payment Contract
↓
Payment Schedule
↓
Payment Transaction
```

---

## 30. DP Management
DP harus memiliki:
- total obligation,
- installment schedule,
- verified payments,
- outstanding,
- overdue,
- paid status.

Status:
- LUNAS
- SEBAGIAN
- BELUM JATUH TEMPO
- TERLAMBAT

---

## 31. Cash Management
Route:
```text
UTJ
↓
PSJB
↓
Cash Payment / Pemberkasan
↓
PPJB
↓
Akad
↓
BAST
```

---

## 32. Data Quality
Issue dapat berupa:
- warning,
- attention,
- critical integrity problem.

Contoh:
- NIK tidak lengkap
- nomor HP kosong
- transaction tanpa project
- SP3K tanpa bank attempt
- duplicate active kavling
- Akad tanpa required readiness
- data legacy ambigu

Data Quality idealnya tampil sebagai **Needs Attention**.

---

## 33. UI Philosophy
Prinsip:
- minim reload,
- cepat,
- data-heavy tetapi mudah dibaca,
- actions dekat dengan data,
- side panel/detail drawer,
- keyboard/search friendly,
- responsive.

---

## 34. Main Navigation
Sidebar utama V1:
- Home
- Lead
- Database Konsumen
- Dana Talangan
- Tasks
- Notifications
- More

---

## 35. Database Workspace
Toolbar:
**Table | Kanban | Calendar | Dashboard**

Kemudian:
**Filter | Sort | Group | Fields | Views | Automate | Export**

---

## 36. Table View
Fitur:
- inline editing,
- resize column,
- hide/show field,
- sort,
- filter,
- grouping,
- multi-select,
- quick action,
- saved view,
- pagination atau virtualization,
- sticky identity column.

---

## 37. Record Detail
Klik record membuka detail panel/drawer.

Tab:
- Overview
- Process
- Payment
- Files
- Activity
- Comments

Action menu:
- Pindah Kavling
- Ganti Bank
- Mundur
- Ganti Konsumen
- Koreksi Data
- Export
- Create Task

---

## 38. Kanban View
Grouping:
- Stage
- Status
- Bank
- Sales
- Project

Card menampilkan:
- nama,
- kavling,
- Sales,
- project,
- overdue badge.

---

## 39. Calendar View
Calendar dapat menggunakan:
- follow-up date,
- target Akad,
- task due date,
- site visit,
- payment due,
- milestone date.

---

## 40. Dashboard View
Widget awal:
- total active consumers,
- stage distribution,
- akad month-to-date,
- overdue SLA,
- SP3K belum akad,
- target vs actual,
- Sales performance.

---

## 41. Custom Views
User boleh membuat view seperti:
- Lead TikTok Oktober
- SP3K Belum Akad
- Konsumen BTN Magelang
- Follow Up Hari Ini

View menyimpan:
- filters,
- sort,
- grouping,
- visible fields,
- layout.

Scope:
- Private
- Team
- Branch
- Global jika permission sesuai

---

## 42. Custom Fields
Model Hybrid.

### Core Field
Tidak dapat dihapus atau diganti tipe.

### Custom Field
Tipe V1:
- text
- number
- select
- multi-select
- date
- checkbox
- user
- URL

Custom field tidak boleh menjadi primary ID, menentukan lifecycle canonical, memodifikasi SLA canonical, atau menggantikan system field.

---

## 43. Comments & @Mention
User dapat:
- menulis comment,
- mention `@user`,
- reply sederhana,
- melihat timestamp.

Mention menghasilkan notification.

---

## 44. Activity History
Activity harus terbaca manusia dan menampilkan perubahan penting beserta actor dan timestamp.

---

## 45. File Attachment
V1 attachment terutama untuk:
- Agenda Sales
- Consumer Application
- Dana Talangan jika relevan

Metadata:
- uploader,
- timestamp,
- category,
- description,
- linked record.

---

## 46. Task System
Field:
- task title
- linked record
- assignee
- creator
- due date
- status
- priority
- notes

Status:
- OPEN
- IN PROGRESS
- DONE
- CANCELLED

---

## 47. Task Relationship
Task dapat dikaitkan ke:
- Lead
- Consumer Application
- Customer
- Dana Talangan

---

## 48. Automation Engine
Model:
```text
WHEN
[event]

IF
[condition]

THEN
[action]
```

Contoh:
- Lead 2 hari masih No Response → buat task follow-up
- SP3K masuk → buat task Persiapan PPJB
- SLA overdue → notify owner

---

## 49. Automation Actions V1
Minimal:
- create task
- send notification
- assign user
- update allowed field
- add tag
- send email jika provider tersedia

WhatsApp disiapkan sebagai extension.

---

## 50. Notification Center
Jenis:
- mention
- assigned task
- due task
- overdue
- automation
- consumer milestone
- data issue

Notification memiliki unread/read, target record, dan action link.

---

## 51. Notification Channel
Channel:
- In-app
- Push/PWA
- Email
- WhatsApp

In-app wajib menjadi canonical. User preference menentukan channel tambahan.

---

## 52. Global Search
Search mendukung:
- nama
- nomor HP
- NIK
- ID transaksi
- kavling
- lead ID
- Sales
- project

Result dikelompokkan:
- Lead
- Customer
- Application
- Kavling
- Task

---

## 53. Command Search
`Ctrl/Cmd + K`

Contoh:
- Cari konsumen
- Tambah Lead
- Tambah Task
- Buka Database
- Buka Dana Talangan
- Pindah ke Notification

---

## 54. Role-Based Home

### Sales
- lead pribadi
- follow-up
- tasks
- target lead
- target UTJ
- akad
- site visit

### Koordinator
- team pipeline
- Sales performance
- lead tim
- tasks overdue
- conversion

### SPV
- branch pipeline
- Sales + Koordinator
- target branch
- bottleneck

### BM / Admin
- operational branch
- consumer process
- data quality
- readiness

### Pusat / Direksi
- multi-branch dashboard
- KPI
- conversion
- akad
- SLA

---

## 55. Role & Permission
Permission tidak hard-coded terlalu rigid.

### Sales
- view own lead
- create own lead
- operate allowed Lead actions
- view linked consumer sesuai policy
- update agenda

### Koordinator
- view team Lead
- create Lead untuk anggota tim
- reassign dalam team
- monitor team KPI

### SPV
Default V1:
- view all branch Lead
- view Consumer Database branch
- edit operational data branch
- reassign Sales
- input Lead

Semua permission SPV configurable.

### BM / Admin Cabang
- full branch operational access

### Pusat / Direksi
- all branch read
- selected manage permissions

### Superadmin
- platform configuration
- role permission
- integration
- system maintenance

---

## 56. Permission Configuration
Permission configurable:
- create Lead
- edit Lead
- reassign Lead
- edit Consumer
- execute pindah kavling
- execute mundur
- export
- create custom field
- create team view
- manage automation

Tanpa perubahan source code.

---

## 57. Security Principle
**Operational access is permissive.**  
**Structural access is restrictive.**

User boleh mengubah data operasional sesuai scope, tetapi tidak boleh:
- mengubah immutable ID,
- menghapus audit log,
- bypass kavling lock,
- mengubah permission sendiri,
- mengakses branch di luar scope tanpa permission.

---

## 58. Authentication
Minimal:
- secure login
- session management
- password policy normal
- optional remember device

---

## 59. Soft Delete
Business data penting tidak hard delete.

Gunakan:
- soft delete,
- archive,
- tombstone,
- close status.

Hard delete hanya untuk superadmin dan kasus khusus.

---

## 60. Responsive Design
OASIS responsive dari awal dan tidak sekadar mengecilkan desktop UI.

---

## 61. Desktop
- full sidebar
- table workspace
- side drawer
- multi-column filters
- dashboards

---

## 62. Tablet
- collapsible sidebar
- simplified toolbar
- adaptable table
- detail drawer

---

## 63. Mobile
Gunakan:
- cards
- compact list
- quick action
- filter drawer
- bottom action sheet

---

## 64. PWA
OASIS diarahkan menjadi installable PWA:
- home screen shortcut
- browser push
- app-like navigation

Online-first tetap menjadi architecture.

---

## 65. Google Sheets Strategy
Target:
**OASIS PRIMARY**  
**GOOGLE COMPATIBILITY**

Google digunakan untuk transition, backup/export, legacy branch, reconciliation, dan external operational dependency sementara.

---

## 66. Google Sync Modes
Per branch:
- OFF
- PUSH ONLY
- BIDIRECTIONAL

Jangka panjang: push/export oriented, bukan dependency permanen dua arah.

---

## 67. Conflict Handling
Status seperti:
- conflict
- needs review
- duplicate
- source changed

harus diarahkan ke reconciliation interface.

---

## 68. Import Excel / CSV
Workflow:
```text
Upload
↓
Preview
↓
Mapping
↓
Validation
↓
Conflict Detection
↓
Confirm Import
↓
Import Report
```

---

## 69. Export
Export:
- seluruh view
- current filter
- selected records

Output:
- XLSX
- CSV

---

## 70. External Lead API
Future sources:
- Meta Ads
- TikTok
- Website
- Marketplace
- WhatsApp
- Landing Page
- External agency

Sediakan **Lead Ingestion Layer** untuk API/Webhook.

---

## 71. External Lead Normalization
Inbound Lead dinormalisasi ke:
- source
- channel
- activity
- campaign
- name
- phone
- project
- owner jika tersedia

Jika owner belum tersedia, lead masuk ke configurable lead queue.

---

## 72. Lead Assignment Future
Future assignment engine:
- manual assignment
- round robin
- project based
- branch based
- campaign based

Tidak wajib V1.

---

## 73. KPI
Target dapat berada pada:
- Sales
- Koordinator
- SPV
- Branch
- Project

Metric:
- Lead
- UTJ
- SLIK
- SP3K
- Akad

---

## 74. Sales Target
Target rules tidak boleh hard-coded selamanya. Gunakan target configuration.

---

## 75. KPI Dashboard
Tampilan:
- Actual
- Target
- Achievement %

---

## 76. Dana Talangan
Dana Talangan tetap menjadi workspace prioritas bersama Lead dan Consumer Database. Dapat memiliki link ke Customer / Application jika relevan.

---

## 77. Database V3 as Business Rule Reference
Rule yang harus dipertahankan jika relevan:
- immutable id_transaksi
- append history
- pindah kavling
- mundur
- ganti konsumen
- ganti bank
- bank attempt
- SLA
- cash lifecycle
- DP lifecycle
- akad readiness
- BAST readiness
- Ready100
- payment history
- data quality logic

OASIS mengadopsi business rules, bukan bentuk spreadsheet-nya.

---

## 78. Exception UX
Backend tetap dapat menggunakan event model, tetapi user melihat **Actions**:
- Pindah Kavling
- Ganti Bank
- Mundur
- Ganti Konsumen
- Koreksi Data

---

## 79. Audit Model
Setiap critical update memiliki:
- entity
- entity_id
- action
- actor
- timestamp
- before
- after
- metadata
- source

---

## 80. Collaboration Model
V1:
- Comment
- @Mention
- Task
- Notification
- Activity
- Attachment

Tidak membangun chat system baru.

---

## 81. Performance Requirements
Target:
- first usable screen cepat
- table tidak reload penuh pada setiap perubahan
- partial mutation
- lazy load
- cached summaries
- background reporting refresh
- pagination/virtualization

---

## 82. Data Consistency
Critical write menggunakan database transaction. Pindah Kavling harus atomic.

---

## 83. Concurrency
Gunakan:
- DB transaction
- row lock jika diperlukan
- optimistic locking untuk UI edit

---

## 84. Error UX
Tampilkan pesan bisnis yang mudah dipahami, bukan SQL error atau stack trace.

---

## 85. Empty State
Setiap workspace harus memiliki useful empty state dan quick action.

---

## 86. Quick Create
Global `+`:
- Lead
- Task
- Consumer jika permission
- Dana Talangan

---

## 87. Data Migration
Tahapan:
1. inventory data
2. snapshot
3. import
4. reconciliation
5. acceptance
6. cutover
7. archive legacy

---

## 88. Migration Identity
Jangan mengandalkan nama, nomor HP, row number, atau kavling saja. Gunakan source identity yang stabil.

---

## 89. Reporting
Transactional tables menjadi canonical. Dashboard dapat menggunakan reporting cache/read model.

---

## 90. V1 Delivery Strategy
Product architecture:
**C — End-to-End Lead → Database**

Delivery milestone:
**B+ — Consumer Database First**

---

## 91. Consumer Database V1 — Required
- Customer
- Consumer Application
- Transaction identity
- Project
- Sales
- Kavling
- UTJ linkage
- SLIK
- PSJB
- Pemberkasan
- Bank attempts
- SP3K
- PPJB
- Akad
- BAST
- Pindah Kavling
- Mundur
- Ganti Bank
- Ganti Konsumen
- Activity
- Search
- Responsive workspace

---

## 92. V1 — Should Have
- saved views
- table filtering
- dashboard dasar
- task
- notification inbox
- import/export
- file attachment
- comments / @mention
- target KPI dasar
- Google compatibility

---

## 93. V1 — Nice to Have
- Kanban
- Calendar
- basic automation
- PWA push
- custom fields
- custom dashboard builder

---

## 94. Post-V1

### V1.1 — Lead CRM Refinement
- full lead workspace
- ownership
- UTJ conversion
- custom view
- KPI
- task automation

### V1.2 — Collaboration
- comments
- mention
- advanced notification
- attachments

### V1.3 — Automation
- rule builder
- WhatsApp
- external lead ingestion

### V2 — Advanced CRM
- lead distribution
- campaign attribution
- funnel analytics
- advanced KPI
- custom fields expansion
- reporting builder

---

## 95. Target Milestone — Monday
Target realistis:

> **Consumer Database core sudah menggunakan architecture final dan dapat menjalankan critical consumer lifecycle tanpa bergantung pada desain legacy.**

Minimum:
```text
Customer
↓
Consumer Application
↓
Kavling
↓
Process Lifecycle
↓
History
↓
Search
↓
Responsive UI
```

serta **Lead → UTJ → Consumer Application** sudah memiliki contract yang jelas.

---

## 96. Acceptance Criteria — Customer
PASS jika:
- satu Customer dapat memiliki beberapa Lead
- satu Customer dapat memiliki beberapa Application
- Customer tidak duplikat hanya karena transaksi baru
- histori lama tetap tersedia

---

## 97. Acceptance Criteria — Application
PASS jika:
- Application memiliki immutable ID
- stage dapat dihitung
- status tidak hilang
- exception tidak merusak history

---

## 98. Acceptance Criteria — Kavling
PASS jika:
- kavling tidak bisa double active owner
- pindah kavling atomic
- kavling lama tercatat
- mundur dapat melepaskan unit sesuai rule

---

## 99. Acceptance Criteria — Bank
PASS jika:
- multiple bank attempts didukung
- bank lama tidak ditimpa
- SP3K terkait attempt yang tepat

---

## 100. Acceptance Criteria — Audit
PASS jika:
- perubahan operasional dapat dilakukan tanpa approval berlebihan
- actor tercatat
- timestamp tercatat
- before/after tersedia

---

## 101. Acceptance Criteria — Responsive
PASS jika task utama dapat dilakukan pada desktop, tablet, dan mobile.

---

## 102. Acceptance Criteria — Search
PASS jika user dapat menemukan konsumen menggunakan:
- nama
- phone
- NIK
- id transaksi
- kavling

---

## 103. Acceptance Criteria — Lead Handoff
PASS jika:
- Lead dapat diubah menjadi UTJ tanpa input ulang seluruh identitas konsumen
- Lead tetap tersimpan
- Customer dibuat/dihubungkan
- Application baru dibuat

---

## 104. Product Success Metrics
- persentase transaksi baru yang dibuat di Oasis
- penurunan input ulang data
- penurunan penggunaan Sheet operasional
- pencarian konsumen lebih cepat
- jumlah duplicate/ambiguous record
- overdue follow-up
- overdue process
- adoption per branch
- data completeness

---

## 105. Architectural Decision Summary
- **OASIS = Operational System**
- **Customer = Person**
- **Lead = Acquisition Opportunity**
- **UTJ = Conversion Event**
- **Consumer Application = Transaction Journey**
- **Business Event = Action**
- **History = Immutable Audit**
- **Google Sheet = Compatibility Layer**
- **Permissions = Configurable**
- **Security = Internal-Friendly**
- **UX = Workspace, not Form Collection**
- **Desktop + Mobile = First-Class**
- **Database Consumer = First Delivery Priority**
- **Architecture remains Lead-to-BAST from day one**

---

## Final Product Statement

OASIS harus berkembang dari aplikasi internal yang terdiri dari modul-modul terpisah menjadi satu **Marison Operating Workspace**.

Bagi user, pekerjaannya sederhana:

> **Cari orang → buka record → lihat kondisi sekarang → lakukan tindakan → sistem menyimpan histori.**

Bagi sistem, setiap tindakan tetap:
- terstruktur,
- auditable,
- relational,
- scalable,
- CRM-ready,
- migration-safe.

Target akhirnya adalah:

> **membuat OASIS menjadi tempat kerja utama Marison untuk Lead, Consumer Database, proses penjualan, dan monitoring operasional — dengan pengalaman sesederhana workspace modern seperti Lark.**
