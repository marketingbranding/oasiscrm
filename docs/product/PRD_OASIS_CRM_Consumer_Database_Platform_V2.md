# PRD — OASIS CRM & Proses Penjualan Platform V2

**Product:** OASIS  
**Company:** Marison Regency Group  
**Document Status:** Master Product Requirement — V2  
**Date:** 02 October 2026  
**Product Direction:** OASIS First  
**Primary Scope:** Lead, NUP/Waiting List, Data Konsumen, Proses Penjualan, Mundur, Kendala, Form Garansi, Reporting  
**Target Platform:** Responsive Web Application / PWA  
**Primary Users:** Sales, Koordinator, SPV, BM, Admin Cabang, Marketing Pusat, Direksi, Superadmin

> **This document supersedes the previous PRD assumption that the sales lifecycle starts at UTJ and that Lead converts to Consumer specifically at UTJ.**

---

# 1. Product Vision

OASIS adalah operational workspace utama Marison Regency Group untuk:

**Lead → Data Konsumen → Proses Penjualan → After Sales / Garansi → Selesai**

Lead tetap merupakan modul akuisisi yang berdiri sendiri.  
Proses Penjualan merupakan modul operasional konsumen yang dimulai dari **Data Konsumen**, bukan dari UTJ.

OASIS harus mendukung tiga kondisi nyata sekaligus:

1. **Data baru** yang mengikuti proses normal dari awal.
2. **Data lama/migrasi** yang saat pertama masuk OASIS sudah berada di tengah atau akhir proses.
3. **Waiting list/NUP** yang belum memiliki kavling dan belum menjadi transaksi konsumen aktif.

Google Sheets tetap menjadi compatibility/migration reference selama masa transisi, tetapi workflow baru diarahkan ke OASIS.

> **OASIS menjadi source of truth operasional; spreadsheet menjadi referensi, compatibility layer, import/export, atau backup selama transisi.**

---

# 2. Key Product Principles

## 2.1 OASIS First
Aktivitas operasional baru dilakukan di OASIS sebisa mungkin.

## 2.2 Lead Is Separate From Sales Process
Lead bukan tahap Proses Penjualan.

Lead menangani acquisition/follow-up.

Ketika Lead siap masuk operasional penjualan, user menjalankan action:

**Jadikan Konsumen / Lanjut ke Proses Penjualan**

Data Lead yang relevan dibawa ke Data Konsumen tanpa input ulang.

Konversi ini **tidak lagi terikat secara eksklusif pada UTJ**.

## 2.3 Data Konsumen Is The First Sales-Process Stage
Semua transaksi konsumen aktif memiliki titik awal UI:

**Data Konsumen**

Data Konsumen berisi identitas dan konteks transaksi awal.

## 2.4 One Customer, Many Transactions
Satu orang dapat mempunyai lebih dari satu perjalanan/transaksi sepanjang waktu.

Customer/person tidak sama dengan Consumer Transaction.

## 2.5 Admin Inputs New Facts Only
Admin tidak diminta mengisi ulang informasi yang sudah diketahui OASIS.

Contoh:
- nama sudah ada → jangan isi ulang pada PSJB,
- NIK sudah ada → jangan isi ulang pada SLIK,
- kavling aktif sudah ada → jangan pilih ulang di tiap form,
- Sales sudah diketahui → jangan ketik ulang,
- No. BAST sudah ada → Form Garansi membaca otomatis.

## 2.6 Historical Data Must Be Easy To Onboard
Data lama tidak dipaksa mengulang proses fiktif.

Admin dapat:
1. input Data Konsumen,
2. pilih **Posisi Konsumen Saat Ini**,
3. isi riwayat yang diketahui,
4. meninggalkan data historis yang memang tidak diketahui tetap kosong.

OASIS tidak boleh mengarang milestone sejarah.

## 2.7 Events Are Not Main Stages
Hal berikut adalah business events/actions, bukan tahap linear utama:
- Pindah Kavling
- Ganti Konsumen / Ganti Nama
- Ganti Bank
- Mundur
- Koreksi Data

Semua event harus dapat dilihat kembali dalam histori.

## 2.8 Flexible Operation, Strong Audit
Koreksi tidak memerlukan approval birokratis berlapis.

OASIS menyimpan:
- before,
- after,
- actor,
- timestamp,
- source,
- action.

## 2.9 Protect Integrity, Not Bureaucracy
Hard block hanya untuk integritas struktural:
- duplicate active kavling,
- immutable transaction ID,
- invalid replacement relation,
- concurrency conflict,
- unauthorized scope,
- destructive deletion,
- credential/security.

---

# 3. Product Architecture

## 3.1 Acquisition Layer

```text
Lead
  │
  └── [Jadikan Konsumen]
          ↓
     Data Konsumen
```

Lead dapat tetap tersimpan sebagai source/acquisition history.

---

## 3.2 Waiting List Layer

```text
NUP / Waiting List
        │
        └── [Lanjut jadi Konsumen]
                 ↓
            Data Konsumen
```

NUP belum memiliki kavling.

Data identitas tidak perlu diinput ulang ketika dilanjutkan menjadi konsumen.

---

## 3.3 Direct Entry

```text
Tambah Data Konsumen
        ↓
Data Konsumen
```

Direct entry digunakan untuk:
- konsumen baru yang tidak berasal dari Lead,
- data lama,
- data migrasi,
- transaksi historis yang sudah memiliki progress.

---

# 4. Canonical Sales Process

Default business sequence:

```text
Data Konsumen
    ↓
PSJB
    ↓
BI Checking / SLIK
    ↓
Pemberkasan
    ↓
Proses Bank
    ↓
SP3K
    ↓
PPJB Developer
    ↓
Akad
    ↓
BAST
    ↓
Form Garansi
    ↓
Selesai
```

## 4.1 PSJB and BI Checking Flexibility

Default rule saat ini:

```text
PSJB → BI Checking / SLIK
```

Namun praktik dapat berbeda tergantung:
- cabang,
- bank,
- kebijakan operasional,
- kondisi transaksi.

OASIS tidak boleh meng-hard-code urutan ini sebagai global irreversible FSM.

Arsitektur harus mendukung policy/configuration sehingga PSJB dan BI Checking dapat:
- PSJB dahulu,
- BI Checking dahulu,
- atau berjalan paralel,

tanpa kehilangan history.

Default UI tetap menampilkan urutan bisnis yang berlaku saat ini: **PSJB kemudian BI Checking/SLIK**.

---

# 5. Main Navigation Concept

Recommended structure:

```text
PENJUALAN

Lead

NUP / Waiting List

Proses Penjualan
  ├── Data Konsumen
  ├── PSJB
  ├── BI Checking / SLIK
  ├── Pemberkasan
  ├── Proses Bank
  ├── SP3K
  ├── PPJB
  ├── Akad
  ├── BAST
  ├── Form Garansi
  └── Selesai

Mundur
Kendala
Laporan
```

**Permohonan Edit Data tidak digunakan.**

Koreksi dilakukan langsung oleh user yang berwenang dan tercatat dalam audit.

---

# 6. Lead Module

Lead adalah CRM acquisition workspace.

Lead dapat dibuat dari:
- manual entry,
- Meta,
- TikTok,
- website,
- WhatsApp/integration,
- import,
- future external API.

Lead tetap memiliki Sales Owner.

Lead tidak dipaksa menjadi bagian dari sidebar Proses Penjualan.

---

# 7. Lead → Data Konsumen Handoff

Action utama:

**Jadikan Konsumen**

Ketika dilakukan:
1. Resolve/create Customer.
2. Create Consumer Transaction/Application.
3. Copy/mapping field Lead yang relevan.
4. Preserve source Lead.
5. Preserve Sales Owner.
6. Assign branch/project.
7. Buka Data Konsumen untuk melengkapi field yang belum tersedia.
8. Lead tetap dapat dilihat sebagai acquisition history.

Action harus idempotent: Lead yang sudah dikonversi tidak boleh membuat transaksi ganda tanpa explicit user action.

---

# 8. Direct Data Konsumen Entry

User dapat membuat Data Konsumen tanpa Lead.

Form meminta hanya data yang memang perlu diinput.

Setelah data dasar disimpan, OASIS menanyakan:

**Posisi Konsumen Saat Ini**

Pilihan mengikuti canonical process:
- Data Konsumen
- PSJB
- BI Checking / SLIK
- Pemberkasan
- Proses Bank
- SP3K
- PPJB
- Akad
- BAST
- Form Garansi
- Selesai

Untuk transaksi baru, default posisi adalah **Data Konsumen**.

Untuk historical/migration entry, admin dapat memilih posisi yang sebenarnya.

---

# 9. Entry Mode

Saat membuat Data Konsumen, OASIS membedakan:

## 9.1 Konsumen Baru
- memulai dari Data Konsumen,
- mengikuti normal process rules,
- tidak bebas melompati milestone tanpa action/process yang benar.

## 9.2 Data Lama / Migrasi
- admin memilih current process,
- historical forms dapat diisi jika diketahui,
- unknown history tidak wajib,
- special historical events dapat ditambahkan,
- source dicatat sebagai historical/manual migration.

UI tidak perlu terasa seperti migration tool teknis. Cukup pilihan sederhana:

```text
Jenis Data
( ) Konsumen Baru
( ) Data Lama / Migrasi
```

Jika Data Lama:

```text
Posisi Saat Ini
[ SP3K ▼ ]
```

---

# 10. Historical Special Events

Untuk Data Lama/Migrasi, admin dapat mencatat riwayat khusus:

- Pernah Pindah Kavling
- Pernah Ganti Konsumen / Ganti Nama
- Pernah Ganti Bank
- Pernah Mundur
- Koreksi data historis

Event harus append-only secara historis walaupun current state mutable.

Admin tidak wajib memilih checkbox panjang pada form pertama. UX yang disarankan:

**Tambah Riwayat Khusus**

lalu pilih event yang relevan.

---

# 11. NUP / Waiting List

NUP adalah module terpisah.

NUP bukan Consumer Transaction aktif.

NUP menyimpan data identitas mirip Data Konsumen tetapi:
- belum memiliki kavling,
- belum mengunci unit,
- berada dalam kategori Waiting List.

Minimal data:
- nama,
- NIK bila tersedia,
- tanggal lahir bila tersedia,
- pekerjaan,
- alamat,
- nomor HP,
- kontak darurat bila diperlukan,
- cabang,
- proyek/minat proyek bila ada,
- nomor urut/NUP,
- tanggal masuk waiting list,
- catatan.

Ketika dilanjutkan:
**Lanjut jadi Konsumen**

OASIS membuat/resolve Customer + Consumer Transaction dan membawa field yang sudah diketahui.

Tidak boleh minta admin input identitas ulang.

---

# 12. Customer Domain

Customer = individu/orang.

Customer field canonical:
- customer_id
- nama
- NIK
- nomor HP
- tanggal lahir
- pekerjaan
- detail pekerjaan
- alamat
- kelurahan
- kecamatan
- kabupaten/kota
- kontak darurat
- nomor kontak darurat
- metadata identitas

Customer tidak memiliki:
- current sales stage,
- current kavling,
- bank active,
- status transaction.

Semua itu milik Consumer Transaction/Application.

---

# 13. Consumer Transaction / Application

Setiap perjalanan penjualan memiliki record transaksi sendiri.

Field canonical:
- id_transaksi
- customer_id
- branch_id
- project_id
- sales_owner_id
- current_kavling_assignment
- payment_method
- current_process
- transaction_status
- acquisition_source
- source_lead_id nullable
- source_nup_id nullable
- entry_mode
- created_at
- updated_at

`id_transaksi` immutable.

---

# 14. Transaction Status

Status transaksi terpisah dari Proses.

Recommended:
- LANJUT
- MUNDUR
- DIGANTI_KONSUMEN
- SELESAI

UI labels:
- Lanjut
- Mundur
- Diganti Konsumen Baru
- Selesai

Jangan mencampur `Akad`, `SP3K`, atau `BAST` sebagai status terminal; itu adalah proses/milestone.

---

# 15. Data Konsumen Form

Source reference: current spreadsheet `data_konsumen`.

Admin-input fields:
- Kavling / unit untuk Consumer Transaction aktif
- NIK
- Nama Konsumen
- Tanggal Lahir
- Pekerjaan
- Detail Pekerjaan
- Alamat
- Kelurahan
- Kecamatan
- Kabupaten/Kota
- No. HP
- Nama Kontak Darurat
- No. HP Kontak Darurat
- Keterangan

Transaction-level field:
- Cara Pembayaran / kategori transaksi jika dibutuhkan pada awal proses

Legacy `status_cash` tidak diperlakukan sebagai property Customer; mapping diarahkan ke transaction/payment method.

Auto/derived:
- Umur
- Proses Terakhir
- Status Terakhir
- Status Kelengkapan
- ID internal/customer identity
- audit metadata

Untuk NUP, **Kavling tidak diminta**.

---

# 16. PSJB Form

Source reference: current spreadsheet `PSJB`.

Admin input/factual fields:
- Tanggal PSJB
- Harga Unit
- Tanggal UTJ
- Nominal UTJ
- Tanggal DP KLT
- DP All In
- Nominal Cicilan
- Jumlah Cicilan
- Luas KLT
- Harga KLT/m
- Harga KLT Total
- Cara Pembayaran
- Nama Promo

Context carried automatically where already known:
- Konsumen
- Kavling
- Sales
- Koordinator / organization context if resolvable
- Project
- transaction ID

Jika historical data membutuhkan override Sales/Koordinator, gunakan relational selection, bukan free-text duplicate jika memungkinkan.

UTJ adalah fakta di PSJB/early transaction process, **bukan first lifecycle stage**.

---

# 17. BI Checking / SLIK Form

Source reference: current spreadsheet `bi_checking`.

UI label utama disarankan:
**SLIK**

Admin input:
- Tanggal SLIK
- Hasil SLIK
- Keputusan bila digunakan
- Keterangan

Auto:
- Nama Konsumen
- NIK
- Kavling/current unit
- transaction identity

Retry/recheck membuat history baru; tidak overwrite history lama.

---

# 18. Pemberkasan Form

Source reference: current spreadsheet `pemberkasan`.

Admin input:
- Tanggal Terima Bank
- Bank
- KC / Unit
- Request Plafond
- Request Tenor
- Tipe Pemberkasan

Auto:
- Konsumen
- Kavling
- Project
- transaction identity

Pemberkasan membuat/reuse bank attempt aktif sesuai canonical bank-attempt logic.

---

# 19. Proses Bank Form

Source reference: current spreadsheet `proses_bank`.

Admin input/facts:
- Jenis Respon
- Approved Plafond
- Approved Tenor
- Kategori Revisi
- Detail Revisi
- Kendala

Data SP3K yang saat ini tersebar pada spreadsheet dipresentasikan di UI pada stage SP3K agar sesuai bahasa bisnis.

Proses Bank memperbarui bank attempt aktif; tidak overwrite attempt lama setelah Ganti Bank.

---

# 20. SP3K Form

SP3K adalah process/milestone tersendiri di OASIS.

Source mapping dapat berasal dari kolom yang secara fisik berada di sheet Proses Bank/PPJB legacy.

Admin input:
- No. SP3K
- Tanggal SP3K
- Approved Plafond bila belum tersedia/berubah
- Approved Tenor bila belum tersedia/berubah
- Catatan bila diperlukan

Auto:
- Bank aktif
- Konsumen
- Kavling
- Project
- current bank attempt

OASIS UI dikelompokkan berdasarkan business process, bukan wajib meniru lokasi fisik kolom spreadsheet.

---

# 21. PPJB Developer Form

Source reference: current spreadsheet `ppjb_dev`.

Admin input:
- Tanggal TTD PPJB

Auto:
- Tanggal SP3K dari canonical SP3K
- No. SP3K
- Konsumen
- Kavling
- transaction identity

UI label dapat menggunakan **PPJB** selama konteks jelas.

---

# 22. Akad Form

Source reference: current spreadsheet `akad`.

Admin input/factual fields:
- Tanggal Akad
- Kualitas Akad
- Status Bangunan
- Status DP Konsumen
- Status Utilitas
- Status Konsumen bila masih diperlukan sebagai legacy operational fact
- Keterangan Terlambat

Semakin payment/readiness domain matang, status yang dapat dihitung otomatis harus menjadi derived, bukan pilihan manual.

Auto:
- Konsumen
- Kavling
- PPJB identity
- bank/transaction context

---

# 23. BAST Form

Source reference: current spreadsheet `bast`.

Admin input:
- Tanggal BAST

Auto:
- Konsumen
- Kavling
- No. PPJB/Akad
- transaction identity

BAST readiness mengikuti canonical rule yang sudah dibangun:
- Akad ada
- Rumah Siap 100% ada

BAST SLA anchor:
`MAX(tanggal_akad, tanggal_rumah_siap_100)`

---

# 24. Rumah Siap 100%

Rumah Siap 100% adalah canonical fact/event yang dapat digunakan untuk readiness.

UI label:
**Rumah Siap 100%**

Jangan tampilkan raw `Ready100`.

Ini tidak harus menjadi menu utama Proses Penjualan jika secara operasional lebih cocok sebagai action/fact pada Akad/BAST readiness.

---

# 25. Form Garansi

Form Garansi muncul setelah BAST.

Source reference: spreadsheet Form Garansi yang sekarang digunakan.

## 25.1 Auto-Carried Context
Tidak diinput ulang:
- ID Kavling
- Nama Konsumen
- No. BAST
- Project
- transaction identity

## 25.2 Admin-Input Fields
Sesuai source spreadsheet:
- Status Komplain
- `tgl_sales_ke_sam`
- `tgl_sam_ke_sat`
- `tgl_sat_ke_sam`
- `tgl_sam_ke_sales`
- `tgl_sales_ke_kons`
- Detail Garansi
- Tanggal Selesai
- Status Garansi

User-facing label sementara:
- Status Komplain
- Tanggal Sales ke SAM
- Tanggal SAM ke SAT
- Tanggal SAT ke SAM
- Tanggal SAM ke Sales
- Tanggal Sales ke Konsumen
- Detail Garansi
- Tanggal Selesai
- Status Garansi

Raw source keys tetap boleh digunakan untuk migration mapping.

## 25.3 Status Komplain
Source spreadsheet menggunakan nilai seperti:
- YA
- TIDAK
- belum dipilih

UI dapat menggunakan:
- Ada Komplain
- Tidak Ada Komplain
- Belum Dipilih

## 25.4 Status Garansi
Source spreadsheet menunjukkan kondisi seperti:
- BELUM DIPILIH
- PROSES
- TIDAK ADA KOMPLAIN
- SELESAI

UI labels:
- Belum Dipilih
- Proses
- Tidak Ada Komplain
- Selesai

## 25.5 Garansi Completion
Jika:
- tidak ada komplain, atau
- proses komplain selesai,

transaction dapat memenuhi kondisi menuju **Selesai** sesuai business rule yang dikunci kemudian.

---

# 26. Selesai Module

Selesai bukan database duplikat.

Selesai adalah view dari Consumer Transactions yang memenuhi terminal completion rule.

Menampilkan:
- Konsumen
- Project
- Kavling
- Akad
- BAST
- Garansi
- tanggal selesai
- relevant summary

---

# 27. Pindah Kavling

Pindah Kavling adalah action/event.

Rules:
- `id_transaksi` tetap
- assignment lama disimpan
- current kavling berubah
- target kavling harus available
- history dapat dilihat user

Historical entry harus dapat mencatat Pindah Kavling masa lalu tanpa mengarang tanggal bila tidak diketahui.

---

# 28. Ganti Konsumen / Ganti Nama

Ganti Konsumen adalah replacement event.

Rules:
- transaksi lama tetap disimpan
- transaksi lama terminal `DIGANTI_KONSUMEN`
- customer lama tidak diubah menjadi customer baru
- transaction baru dibuat dengan `id_transaksi` baru
- lineage old → replacement dapat dilihat
- kavling dapat dialihkan secara aman

UI dapat menggunakan istilah:
**Ganti Konsumen**

Jika operasional masih menyebut “Ganti Nama”, dapat ditampilkan sebagai alias/help text.

---

# 29. Ganti Bank

Ganti Bank bukan edit field Bank.

Rules:
- attempt lama tetap history
- attempt baru dibuat
- attempt number meningkat
- current bank menjadi attempt terbaru aktif
- reason/date/actor tercatat

Historical bank attempts dapat dimasukkan untuk data lama jika diketahui.

---

# 30. Mundur Module

Mundur adalah:
1. action pada transaksi aktif, dan
2. dedicated aggregate module untuk melihat data yang Mundur.

Tidak ada separate duplicate database.

Current transaction action:
**Konsumen Mundur**

Effects:
- status = MUNDUR
- active kavling released
- active SLA stops
- history preserved

## 30.1 Historical Mundur Direct Entry
Module Mundur menyediakan:
**Tambah Data Mundur Lama**

Admin dapat memasukkan data historical consumer yang sudah mundur tanpa harus memalsukan semua milestone sebelumnya.

Minimal:
- Data Konsumen / resolve Customer
- Project
- last known process
- tanggal Mundur jika diketahui
- alasan
- catatan
- known historical kavling if applicable
- known process history optional

Backend tetap membuat canonical Consumer Transaction + Mundur event.

---

# 31. Kendala Module

Kendala bukan stage utama.

Kendala adalah aggregate operational workspace yang menampilkan issue terkait transaksi/proses.

Kendala dapat berasal dari:
- Proses Bank
- Akad
- BAST
- Garansi
- proses lain

Minimal data:
- related transaction
- process
- category
- description
- opened_at
- PIC
- status
- resolution
- resolved_at

Tujuan:
user dapat melihat masalah lintas proses tanpa menggandakan canonical process data.

---

# 32. Historical Data UX

Setelah Data Konsumen dibuat sebagai Data Lama:

```text
Posisi Saat Ini
[ Akad ▼ ]

Riwayat yang tersedia
[ + Tambah PSJB ]
[ + Tambah SLIK ]
[ + Tambah Pemberkasan ]
[ + Tambah Bank/SP3K ]
[ + Tambah PPJB ]
[ + Tambah Event Khusus ]
```

Semua optional sesuai arsip yang tersedia.

Jangan tampilkan 10 form sekaligus.

---

# 33. Process Workspace UX

Sidebar menunjukkan process list.

Klik process seperti **SP3K** membuka queue/list konsumen yang:
- sedang di process itu,
- pernah memiliki record process itu,
- atau relevan berdasarkan filter/view.

Recommended workspace:
- search
- branch/project filters
- table/list
- drawer
- quick input/action

---

# 34. Simple Data Entry UX

Target:
admin dapat input data dengan sedikit klik.

Principles:
- prefill known values,
- auto-carry context,
- searchable selects,
- no duplicate identity typing,
- form grouped by business meaning,
- save without page reset,
- preserve context,
- clear success feedback.

---

# 35. UI Language

User-facing UI menggunakan Bahasa Indonesia operasional.

Examples:
- Customer → Konsumen
- Consumer Application → Transaksi Konsumen
- Stage → Proses
- Bank Attempt → Pengajuan Bank
- Activity → Riwayat
- Ready100 → Rumah Siap 100%
- Attention Required → Perlu Perhatian

Business terms retained:
- NUP
- UTJ
- SLIK
- PSJB
- SP3K
- PPJB
- Akad
- BAST
- KPR
- Cash
- DP
- Kavling

---

# 36. Consumer Workspace

Consumer record drawer tabs:
- Ringkasan
- Proses
- Pembayaran
- Riwayat
- Dokumen if ready
- Komentar if ready

Process timeline includes known facts only.

Unknown historical stage = not fabricated.

---

# 37. Process History

All process forms create/update canonical current state while preserving meaningful history.

User must be able to inspect:
- process date,
- result/status,
- actor,
- source,
- before/after corrections,
- repeated attempts,
- special events.

---

# 38. Current Process

`current_process` is a summary/pointer, not the sole history.

For new data, updated from actual actions.

For historical onboarding, it may be explicitly set by authorized admin with source `historical_entry`.

---

# 39. Branch-Specific Process Policy

OASIS must allow process prerequisite/config by branch/project/bank where necessary.

V1 minimum:
- default standard process sequence
- configurable exception for PSJB/SLIK ordering
- Cash skip behavior
- bank-specific requirements without rewriting core code

Avoid fully generic workflow-builder complexity in V1.

---

# 40. Cash Flow

Cash transaction does not use Bank/SP3K process.

Example:

```text
Data Konsumen
→ PSJB
→ relevant cash/payment process
→ PPJB if required
→ Akad / legal completion as applicable
→ BAST
→ Garansi
→ Selesai
```

Bank stages are marked **Tidak Berlaku**, not “Belum Lengkap”.

Do not force cash through KPR bank attempt logic.

---

# 41. Payment Method

Payment method belongs to transaction, not Customer.

Examples:
- KPR
- Cash
- Cash Bertahap
- other configured types

UTJ/DP/payment facts are linked to transaction.

---

# 42. Bank Attempt

One transaction may have multiple bank attempts.

```text
Transaksi
 ├── Pengajuan Bank #1 — BTN — Ditolak
 └── Pengajuan Bank #2 — BSN — SP3K
```

Historical attempts preserved.

---

# 43. Data Quality

Warn rather than block for non-structural incompleteness.

Examples:
- NIK kosong
- phone kosong
- historical date unknown
- bank details incomplete

Hard block only when integrity would break.

---

# 44. Audit & Corrections

No Permohonan Edit Data module.

Authorized users edit/correct directly.

For critical changes:
- before
- after
- actor
- time
- reason where meaningful

Routine typo fixes should remain lightweight.

---

# 45. Search

Global/consumer search should support:
- name
- phone
- NIK
- id_transaksi
- kavling
- lead ID
- NUP
- Sales
- Project

Respect organization scope.

---

# 46. Roles & Permission

Default scope:
- Sales → own records
- Koordinator → team
- SPV/BM/Admin Cabang → branch
- HQ/Direksi → broader/all branches according to permission
- Superadmin → system/config

Permissions configurable; do not hard-code future organizational decisions into domain rules.

---

# 47. Responsive Design

Desktop:
- table/workspace
- right drawer
- compact filters

Mobile:
- card/list
- full-screen detail
- filter sheet
- business action sheets
- no mandatory horizontal table scroll

---

# 48. NUP UX

Recommended NUP workspace:
- number/NUP
- date
- name
- phone
- project interest
- status waiting
- age in waiting list
- notes

Action:
**Lanjut jadi Konsumen**

On conversion:
- carry person data,
- do not assign kavling unless admin selects available unit,
- preserve NUP history.

---

# 49. Lead UX

Lead remains similar to existing PRD:
- Table
- Kanban
- Calendar
- Dashboard
- activity
- agenda
- files
- comments

Action:
**Jadikan Konsumen**

Lead can exist without Consumer Transaction.

Consumer Transaction can exist without Lead.

---

# 50. Saved Views / Filters

Keep prior PRD concept:
- private/team/branch views
- filters
- sort
- fields
- grouping later

Examples:
- SP3K Belum Akad
- Garansi Proses
- Mundur Bulan Ini
- Data Lama Belum Lengkap
- Waiting List > 30 Hari

---

# 51. Task & Notification

Retain prior PRD lightweight task system.

Tasks can link to:
- Lead
- NUP
- Konsumen
- Transaction
- Kendala
- Garansi

Notifications:
- mention
- task due
- important process change
- assignment
- optionally WhatsApp/email later

---

# 52. Files & Comments

Retain prior PRD:
- file attachment
- categories
- comments
- @mention

Structured process facts must not exist only in comments.

---

# 53. Automation

Retain simple automation direction:

**WHEN → IF → THEN**

Examples:
- when SP3K recorded → create PPJB follow-up task
- when Garansi status becomes PROSES → notify PIC
- when NUP waiting threshold reached → notify Sales/Koordinator

Do not build full workflow engine in initial delivery.

---

# 54. Google Sheets Compatibility

Spreadsheet remains reference for:
- existing field mapping,
- migration,
- import/export,
- transitional sync.

New UI is grouped by business process, so fields may be presented more logically than their physical legacy sheet location.

No production external write from local/test by default.

---

# 55. Import Strategy

Import must support:
- Lead
- NUP
- Data Konsumen
- historical process records
- bank attempts
- process events
- Garansi

Never infer ambiguous identity from name only.

Use canonical transaction identity + source lineage.

---

# 56. Migration / Historical Onboarding

Migration mode must support consumers currently at:
- PSJB,
- SLIK,
- Bank,
- SP3K,
- PPJB,
- Akad,
- BAST,
- Garansi,
- Selesai,
- Mundur.

Do not require artificial prior records.

Missing historical fields remain unknown/null with source metadata.

---

# 57. SLA

SLA/lead time should be based on factual dates and applicable process path.

Do not calculate an impossible stage for:
- Cash when bank process not applicable,
- Mundur terminal transactions,
- Replaced transactions,
- historical missing dates without anchor.

Rules can depend on payment method and process applicability.

---

# 58. Form Input Contract

For every process form, fields are classified into:

## A. Admin Input
New facts entered at this stage.

## B. Auto-Carried
Existing facts shown for context and not retyped.

## C. Derived
Calculated by OASIS.

## D. System
IDs, lineage, timestamps, actor, audit.

UI should expose A and relevant read-only B.  
C and D are not routine manual fields.

---

# 59. Error UX

Use operational language.

Examples:
- “Kavling ini sudah digunakan konsumen lain.”
- “BAST belum bisa dilakukan karena Akad belum tercatat.”
- “Data tidak dapat dibuka karena Anda tidak memiliki akses.”

Never expose raw SQL/enum/model names.

---

# 60. V1 Required Scope — Revised

P0:
1. Lead remains separate.
2. NUP / Waiting List.
3. Direct Data Konsumen entry.
4. Lead → Data Konsumen handoff.
5. NUP → Data Konsumen handoff.
6. New vs Historical entry mode.
7. Current-process selection for historical data.
8. Process workspaces:
   - Data Konsumen
   - PSJB
   - SLIK
   - Pemberkasan
   - Proses Bank
   - SP3K
   - PPJB
   - Akad
   - BAST
   - Form Garansi
   - Selesai
9. Per-process admin forms based on spreadsheet field mapping.
10. Auto-carry existing data between process forms.
11. Pindah Kavling history/action.
12. Ganti Konsumen history/action.
13. Ganti Bank history/action.
14. Mundur module + historical direct entry.
15. Kendala module/basic aggregate.
16. Immutable transaction identity.
17. Activity/history/audit.
18. Search.
19. Responsive desktop/mobile workspace.
20. Cash path that skips bank stages.
21. Google compatibility preserved.

---

# 61. V1 Should Have

- files
- comments/@mention
- tasks
- notifications
- saved views
- dashboard summaries
- Excel import/export
- basic SLA/attention

---

# 62. Post-V1

- advanced custom fields
- advanced workflow configuration
- global automation builder
- WhatsApp delivery
- external lead API/webhooks
- advanced dashboards
- custom report builder
- round-robin lead assignment

---

# 63. Acceptance — Data Konsumen

PASS if:
- user can create new consumer directly without Lead,
- user can convert Lead without retyping known data,
- user can convert NUP without retyping known data,
- historical user can choose current position,
- identity fields are not duplicated at every process,
- transaction identity remains immutable.

---

# 64. Acceptance — Process Forms

PASS if:
- each process has a dedicated form/workspace,
- admin-input fields match required spreadsheet facts,
- known data is auto-carried,
- derived/system fields are not unnecessarily editable,
- correction history is retained.

---

# 65. Acceptance — Historical Data

PASS if:
- a consumer already at Akad can be entered without fake SLIK/PSJB dates,
- known historical records can be backfilled,
- unknown history remains unknown,
- Pindah Kavling/Ganti Konsumen/Ganti Bank history can be recorded,
- historical Mundur can be entered from Mundur module.

---

# 66. Acceptance — NUP

PASS if:
- waiting-list person can be recorded without kavling,
- NUP has its own category/workspace,
- converting to consumer preserves identity/history,
- no duplicate retyping is required.

---

# 67. Acceptance — Garansi

PASS if:
- BAST context is auto-carried,
- admin can record complaint status,
- internal handoff dates from spreadsheet can be recorded,
- detail garansi can be recorded,
- completion date/status can be recorded,
- “Tidak Ada Komplain” can be represented without dummy complaint,
- Garansi history is visible.

---

# 68. Acceptance — Special Events

PASS if:
- Pindah Kavling does not change transaction ID,
- Ganti Bank preserves attempts,
- Ganti Konsumen creates replacement transaction,
- Mundur preserves history and releases active kavling,
- all can be inspected historically.

---

# 69. Acceptance — Cash

PASS if:
- Cash does not require Bank/SP3K,
- skipped stages display as Not Applicable / Tidak Berlaku,
- SLA does not flag non-applicable bank stages as missing.

---

# 70. Acceptance — UX

PASS if:
- UI uses Indonesian operational terms,
- process sidebar follows business flow,
- mobile uses cards/list,
- admin sees only necessary input fields,
- form does not repeatedly ask name/NIK/kavling,
- historical onboarding is understandable without technical migration language.

---

# 71. Architecture Decision Summary

1. Lead is separate from Proses Penjualan.
2. Lead conversion trigger is explicit **Jadikan Konsumen**, not exclusively UTJ.
3. Proses Penjualan starts at **Data Konsumen**.
4. UTJ amount/date live in PSJB/transaction facts.
5. NUP is separate Waiting List and has no kavling.
6. Default current sequence: Data Konsumen → PSJB → SLIK → Pemberkasan → Bank → SP3K → PPJB → Akad → BAST → Garansi → Selesai.
7. PSJB/SLIK ordering is policy-flexible.
8. Data Lama may start at any current process without fake history.
9. Pindah Kavling, Ganti Konsumen, Ganti Bank, Mundur are events/actions, not main stages.
10. Mundur has a dedicated aggregate module but not duplicate canonical storage.
11. Cash skips bank/SP3K.
12. Forms reference spreadsheet admin-input facts, while duplicate/derived/system fields are auto-carried.
13. Form Garansi becomes a canonical stage after BAST.
14. Corrections are flexible with audit; no Permohonan Edit Data module.
15. OASIS remains the future operational source of truth.

---

# Final Product Statement

OASIS harus memungkinkan admin menangani kondisi nyata, bukan hanya alur ideal.

Untuk konsumen baru:

```text
Data Konsumen
→ PSJB
→ SLIK
→ Pemberkasan
→ Proses Bank
→ SP3K
→ PPJB
→ Akad
→ BAST
→ Form Garansi
→ Selesai
```

Untuk data lama:

```text
Data Konsumen
→ pilih "Posisi Saat Ini"
→ isi histori yang diketahui
→ lanjut operasional dari posisi sebenarnya
```

Untuk acquisition:

```text
Lead ───────→ Jadikan Konsumen ──→ Data Konsumen
NUP ────────→ Lanjut jadi Konsumen → Data Konsumen
Direct Entry ─────────────────────→ Data Konsumen
```

Untuk kejadian khusus:

```text
Pindah Kavling
Ganti Konsumen
Ganti Bank
Mundur
Kendala
```

semuanya tercatat sebagai history tanpa merusak perjalanan transaksi.

> **OASIS mengikuti kenyataan operasional Marison, bukan memaksa kenyataan mengikuti struktur database.**
