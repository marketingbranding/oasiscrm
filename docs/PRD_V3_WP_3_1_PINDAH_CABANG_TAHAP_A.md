# Laporan Tahap A — Pemetaan Pindah Proyek Antar Cabang

Tanggal: 26 November 2026  
Cabang git: `prd-v3/wp-3.1-pindah-cabang`  
Basis: `prd-v3/fase-2-pemantauan-data`  
Status: Tahap A selesai; Tahap B menunggu persetujuan owner.

> Nama kerja ini berbeda dari WP-3.1 PRD v3 yang berarti WhatsApp satu nomor. Log implementasi memakai `WP-3.1-PC` agar tidak mencampur dua paket.

## 1. Batas pemeriksaan

- Tidak ada perubahan kode aplikasi, migration, data, atau status cutover.
- Tidak ada koneksi ke production.
- Query memakai sesi read-only pada DB lokal `oasis_gladi` di `127.0.0.1:3307`.
- DB terdeteksi `11.8.10-MariaDB`, `read_only=1`, 120 tabel terapan pada dump gladi. Tidak ada koneksi production.
- Tidak ada nama konsumen, nomor HP, NIK, alamat, email, token, atau isi pesan yang dilaporkan.

## 2. Pencocokan nama dan ID DB

Tidak ada baris `branches` bernama KC Tegal, KC Kandeman, atau KC Pekalongan. Ketiganya muncul sebagai proyek di `KC BATANG`.

| Entitas | ID | Nama DB | Kode | `sheet_id` | Status proyek |
|---|---:|---|---|---|---|
| Cabang | 7 | KC BATANG | `PKL` | tersedia | aktif |
| Proyek | 27 | Marison Kandeman | — | memakai sheet cabang 7 | aktif; `sheet_project_name = Kandeman Batang` |
| Proyek | 28 | Marison Tegal | — | memakai sheet cabang 7 | aktif; `sheet_project_name = Tegal` |
| Proyek | 29 | Wonopringgo Pekalongan | — | belum ada alias sheet | nonaktif; tidak ada data operasional |
| Proyek | 30 | Sarengat Batang | — | belum ada alias sheet | nonaktif; tidak ada data operasional |

Cabang lain yang tersedia: ID 1 KC MALANG, 2 KC MADIUN, 3 KC SOLO, 4 KC MAGELANG, 5 KC PURWOREJO, 6 KC JEPARA, 8 KC BANDUNG, dan 9 Kantor Pusat. Tidak ada cabang tujuan yang boleh ditebak dari nama proyek; wizard Tahap B wajib meminta cabang tujuan eksplisit.

## 3. Dampak data per proyek

| Tabel / relasi | Proyek 27 Kandeman | Proyek 28 Tegal | Proyek 29 Pekalongan | Proyek 30 Sarengat | Aturan kepemilikan |
|---|---:|---:|---:|---:|---|
| `kavlings` | 403 | 245 | 0 | 0 | ikut proyek; seluruh riwayat status/assignment ikut |
| `project_user` | 7 | 6 | 0 | 0 | mapping user wajib ditinjau manual |
| `sales_leads` | 549 | 805 | 0 | 0 | ikut proyek; identitas `id`/`external_sync_id` tidak berubah |
| `sales_lead_status_histories` melalui Lead | 564 | 829 | 0 | 0 | histori ikut; tidak dibuat histori baru |
| `sales_lead_site_visits` melalui Lead | 1 | 9 | 0 | 0 | histori ikut |
| `consumer_applications` | 0 | 0 | 0 | 0 | tidak ada pada DB gladi |
| `consumer_nups` | 0 | 0 | 0 | 0 | tidak ada pada DB gladi |
| `content_items.sales_project_id` | 1.383 | 1.023 | 0 | 0 | ikut proyek; `branch_id` wajib konsisten |
| `dana_talangans.project_id` | 12 | 0 | 0 | 0 | ikut relasi proyek, tetapi bridge Sheet global |
| `expenses.project_id` | 0 | 0 | 0 | 0 | ikut proyek bila ada |
| `consumer_import_batches` | 0 | 0 | 0 | 0 | tidak ada pada DB gladi |
| `consumer_migration_reconciliations` | 0 | 0 | 0 | 0 | tidak ada pada DB gladi |
| `organization_assignments.project_id` | 0 | 0 | 0 | 0 | tidak ada assignment project-scoped |

Ringkasan dua proyek aktif: 648 kavling, 13 assignment proyek, 1.354 Lead, 1.393 histori status Lead, 10 kunjungan lokasi, 2.406 agenda/kegiatan, dan 12 Dana Talangan.

Tidak ditemukan ketidaksesuaian `branch_id` versus branch proyek pada `sales_leads`, `consumer_applications`, `content_items`, atau `dana_talangans` untuk proyek 27–30.

## 4. Data cabang dan data tanpa proyek

Baris cabang 7 yang ditemukan:

| Tabel | Baris cabang 7 | Keputusan awal |
|---|---:|---|
| `lead_master` | 5 | hanya proyek yang dipilih ikut wizard |
| `users` | 14 | tidak ikut otomatis; owner memilih Pindah/Tetap/Keduanya |
| `branch_user` | 14 | keanggotaan cabang dipetakan terpisah dari `project_user` |
| `content_items` | 2.406 | semua punya `sales_project_id`; aman dikelompokkan per proyek |
| `dana_talangans` | 12 | semua punya `project_id`; tetap tunduk pada bridge global |
| `sales_leads` | 1.354 | semua punya `project_id` |
| `sales_daily_plans` | 2 | branch-only; jangan pindah otomatis bersama proyek |
| `promos` | 12 | branch-only; jangan pindah otomatis bersama proyek |
| `sales_agenda_evidence_archives` | 5 | branch/week archive; perlu kebijakan eksplisit, bukan tebak proyek |
| `organization_units` | 1 | unit cabang; bukan data proyek |
| `organization_assignments` | 1 | branch-scoped; tidak ada `project_id` pada row aktif |
| `promo_import_batches` | 1 | branch/import-scoped; tidak pindah otomatis |
| `branch_migration_states` | 1 | state cabang tetap milik cabang |
| `database_sheet_records` | 7.425 | cache branch/sheet; tidak dipindah sebagai baris proyek tanpa parser/matching |
| `konsumen_progress_sheet_rows` | 4.845 | cache branch/sheet; sistem terpisah, tidak digabung dengan Database sync |
| `database_sheet_sync_statuses` | 1 | status cabang tetap pada cabang |
| `konsumen_progress_sync_statuses` | 1 | status cabang tetap pada cabang |
| `sales_lead_status_histories` | 1.393 | ikut melalui Lead; audit tidak dihapus |
| `sales_lead_site_visits` | 10 | ikut melalui Lead |

Kaidah usulan untuk data tanpa proyek:

1. Data dengan FK proyek ikut bila proyek dipilih dan semua invariant branch/proyek lulus.
2. Data branch-only tidak ikut otomatis. Wizard menampilkan daftar dan meminta keputusan eksplisit: tetap di cabang asal, ikut sebagai paket cabang, atau perlu tinjau.
3. Data cache Sheet bukan sumber identitas bisnis. Jangan memindahkan berdasarkan nomor baris, nama konsumen, atau tebakan isi JSON.
4. Jika satu row memiliki `branch_id` dan `project_id`, periksa bahwa proyek memang milik cabang asal sebelum masuk batch. Mismatch menjadi blocker.
5. Tidak ada penghapusan permanen. Row audit, histori, dan sinkronisasi dipertahankan; pemindahan memakai update ter-audit atau tabel arsip batch.

### Lampiran inventaris seluruh tabel dengan `branch_id`

Angka `0` berarti tidak ada row cabang 7 pada DB gladi; bukan berarti tabel aman dipindah tanpa pemeriksaan relasi.

| Tabel | Row cabang 7 |
|---|---:|
| `ai_chat_conversations` | 0 |
| `branch_migration_states` | 1 |
| `branch_user` | 14 |
| `consumer_applications` | 0 |
| `consumer_import_batches` | 0 |
| `consumer_lead_link_decisions` | 0 |
| `consumer_migration_reconciliations` | 0 |
| `consumer_nups` | 0 |
| `consumer_parity_snapshots` | 0 |
| `content_items` | 2.406 |
| `dana_talangans` | 12 |
| `database_sheet_records` | 7.425 |
| `database_sheet_sync_statuses` | 1 |
| `db_v2_akad` | 0 |
| `db_v2_bast` | 0 |
| `db_v2_bi_checking` | 0 |
| `db_v2_data_konsumen` | 0 |
| `db_v2_pemberkasan` | 0 |
| `db_v2_ppjb_dev` | 0 |
| `db_v2_proses_bank` | 0 |
| `db_v2_psjb` | 0 |
| `expenses` | 0 |
| `feedback_reports` | 0 |
| `historical_process_import_batches` | 0 |
| `holidays` | 0 |
| `konsumen_progress_sheet_rows` | 4.845 |
| `konsumen_progress_sync_statuses` | 1 |
| `leads` | 0 |
| `lead_master` | 5 |
| `organization_assignments` | 1 |
| `organization_units` | 1 |
| `promos` | 12 |
| `promo_import_batches` | 1 |
| `sales_agenda_evidence_archives` | 5 |
| `sales_daily_plans` | 2 |
| `sales_leads` | 1.354 |
| `sales_lead_akad_links` | 0 |
| `sales_lead_bridge_settings` | 0 |
| `sales_lead_consumer_links` | 0 |
| `sales_lead_freelance_links` | 0 |
| `sales_lead_lifecycle_reconciliation_items` | 0 |
| `sales_lead_lifecycle_sync_statuses` | 0 |
| `sales_lead_site_visits` | 10 |
| `sales_lead_slik_attempts` | 0 |
| `sales_lead_stage_histories` | 0 |
| `sales_lead_status_histories` | 1.393 |
| `sales_onboarding_proposals` | 0 |
| `sales_sheet_identities` | 0 |
| `users` | 14 |
| `user_presences` | 0 |

Tabel project-scoped tanpa `branch_id`: `kavlings` 648 row pada proyek 27–30 dan `project_user` 13 row. Tabel global yang terdampak tidak boleh disimpulkan dari `branch_id`: `dana_talangan_sync_statuses` 1, `dana_talangan_reconciliation_items` 129 row dengan `status=open`, `dana_talangans` 85 total, serta tabel audit/activity yang memakai relasi polymorphic. Pada DB 11.8, tabel dengan project FK yang wajib ikut dipetakan juga mencakup `consumer_migration_reconciliations`, `marison_import_transactions.resolved_project_id`, `marison_project_mappings.oasis_project_id`, dan `sales_onboarding_proposals`; row proyek 27–28 untuk tabel-tabel ini berjumlah 0.

### Matriks sumber branch dan perlakuan

| Kelompok tabel | Tabel | Sumber branch | Project column | Perlakuan usulan |
|---|---|---|---|---|
| Project-derived | `lead_master`, `sales_leads`, `sales_lead_status_histories`, `sales_lead_stage_histories`, `sales_lead_site_visits`, `sales_lead_slik_attempts`, `sales_lead_consumer_links`, `sales_lead_freelance_links`, `sales_lead_akad_links`, `sales_lead_lifecycle_sync_statuses`, `sales_lead_lifecycle_reconciliation_items`, `sales_onboarding_proposals`, `consumer_applications`, `consumer_import_batches`, `consumer_migration_reconciliations`, `consumer_nups`, `content_items`, `dana_talangans`, `expenses`, `kavlings`, `project_user`, `organization_assignments` bila `project_id` terisi | FK proyek atau disalin saat pembuatan | ya, kecuali histori yang mengikuti parent | ikut proyek; branch diselaraskan atomik; histori ikut |
| User-derived | `users`, `branch_user`, `sales_sheet_identities`, `user_presences`, `organization_assignments` branch-scoped, `organization_units` | membership/user aktif atau assignment | tidak / opsional | hanya sesuai mapping Pindah/Tetap/Keduanya; bukan otomatis |
| Branch-only operational | `branch_migration_states`, `database_sheet_sync_statuses`, `konsumen_progress_sync_statuses`, `sales_daily_plans`, `promos`, `promo_import_batches`, `sales_agenda_evidence_archives`, `holidays`, `feedback_reports` | branch dipilih/diisi proses modul | tidak / opsional | tetap asal kecuali owner memilih paket branch-only |
| Spreadsheet/cache-derived | `database_sheet_records`, `konsumen_progress_sheet_rows`, `db_v2_*`, `sales_sheet_identities` | sheet/cabang saat sync | tidak stabil atau tidak ada | tidak dipindah berdasarkan row number/nama; re-sync/matching terstruktur |
| Global/external | `dana_talangan_sync_statuses`, `dana_talangan_reconciliation_items`, `activity_log`, `comments`, `comment_revisions`, `comment_moderations` | global atau polymorphic subject | relasi tidak seragam | tetap global; audit ikut melalui subject/batch; Dana Talangan blocker sampai rekonsiliasi |

Kolom `active_branch_id` pada `feedback_reports` adalah konteks laporan aktif, bukan kepemilikan proyek. `sales_leads.remote_target_branch_id` adalah target kirim spreadsheet, bukan branch owner; wajib dipisahkan dalam batch. `branch_id` tidak boleh dipercaya sendirian jika ada `project_id`: validator wajib menolak mismatch.

## 5. User dan relasi organisasi

### User cabang 7 per role

| Role | Jumlah |
|---|---:|
| `admin` | 1 |
| `sales` | 10 |
| `sales_coordinator` | 2 |
| `supervisor` | 1 |
| **Total** | **14** |

Assignment proyek aktif:

| Proyek | Sales | Sales Coordinator | Supervisor | Total |
|---|---:|---:|---:|---:|
| 27 Kandeman | 5 | 1 | 1 | 7 |
| 28 Tegal | 5 | 1 | 0 | 6 |

Tidak ada user yang memegang kedua proyek. Tidak ada `organization_assignments` dengan `project_id` untuk dua proyek tersebut. Ada satu assignment aktif branch-scoped `sales_team_member_of` pada cabang 7; parent dan subject sama-sama berada di cabang 7.

Aturan mapping Tahap B:

- **Pindah:** user mendapat membership/assignment pada cabang tujuan sesuai keputusan owner.
- **Tetap:** user tetap pada cabang asal; akses ke riwayat proyek yang pindah hanya melalui scope/grant yang sah, bukan dengan memperluas permission.
- **Keduanya:** user mendapat membership pada kedua cabang dan assignment proyek tujuan; hindari duplikasi role/permission.
- Mapping berlaku untuk semua lini/peran yang terdampak, bukan hanya Sales.
- Perubahan reporting line `organization_assignments` bukan efek samping pemindahan proyek. Perlu aksi organisasi terpisah bila owner menyetujuinya.
- Wizard tidak boleh tersedia saat impersonate.

## 6. Sinkronisasi spreadsheet

### Database sync dan Konsumen Progress

Kedua sistem tetap terpisah.

- `database_sheet_records`: 7.425 row cabang 7, seluruhnya memakai `sheet_id` cabang 7 dan status `synced`.
- `konsumen_progress_sheet_rows`: 4.845 row cabang 7, seluruhnya memakai `sheet_id` cabang 7.
- ID spreadsheet cabang disimpan di DB tetapi sengaja tidak ditulis dalam laporan; sumber Tegal saat ini adalah spreadsheet cabang 7, bukan spreadsheet cabang terpisah.
- Status `database_sheet_sync_statuses`: `success`.
- Status `konsumen_progress_sync_statuses`: `success`.
- `branch_migration_states`: `sheet`.

Row cache tidak boleh dibagi per proyek hanya dari nomor baris atau string nama. Wizard perlu matching terstruktur per `oasis_sync_id`, identitas domain, dan kontrak tab. Parity pascapindah dihitung terhadap spreadsheet cabang tujuan memakai `sheet_id` cabang tujuan.

### Lead

- Semua 1.354 Lead cabang 7 berstatus `pending_create`.
- Semua memiliki `external_sync_id`, tetapi `last_synced_at` dan `delivery_attempted_at` belum terisi.
- Kandeman: 549 Lead pending delivery.
- Tegal: 805 Lead pending delivery.
- Tidak ada `remote_target_branch_id` pada hasil DB 11.8 ini.
- Angka ini dibaca ulang pada MariaDB 11.8.10 port 3307 dengan `read_only=1`; tidak ada baris yang ditulis.

Artinya, pemindahan tidak boleh menganggap Lead sudah terkirim ke Sheet. Tahap B wajib membekukan/menjadwalkan ulang delivery, menetapkan target branch/sheet tujuan secara eksplisit, lalu memverifikasi idempotensi sebelum queue dibuka.

### Dana Talangan

Dana Talangan memakai satu Sheet global, bukan Sheet per cabang.

- 12 row cabang 7 terkait proyek 27 Kandeman; semuanya `pending_update` dan sudah memiliki `oasis_sync_id`/`last_synced_at`.
- Status bridge global: `partial_success`.
- Global reconciliation dari laporan gladi sebelumnya: 70 `remote_create_pending_review`, 20 `remote_data_invalid`, 15 `remote_missing`.
- Global row status pada DB 11.8: 15 `conflict`, 3 `local`, 44 `pending_create`, 5 `pending_delete`, 15 `pending_update`, 3 `synced`.
- `dana_talangan_reconciliation_items`: 129 row dengan `status=open`; kolom status memakai `status`, bukan `reconciliation_status`.

Pemindahan proyek tidak boleh mengganti `remote_target_spreadsheet_id` menjadi Sheet cabang tujuan. Baris Talangan tetap di Sheet global; perubahan branch/project OASIS harus direkonsiliasi dan diaudit.

## 7. Blocker dan risiko Tahap B

Blocker minimum sebelum eksekusi:

1. Cabang tujuan belum ditentukan; jangan memakai nama proyek sebagai pengganti cabang.
2. 1.354 Lead masih pending delivery; perlu strategi queue dan target Sheet.
3. Cache Database/Konsumen Progress belum dapat dipetakan aman per proyek hanya dari metadata yang tersedia.
4. 12 Dana Talangan memakai bridge global dengan status partial/conflict.
5. User mapping Pindah/Tetap/Keduanya belum disetujui owner.
6. Data branch-only belum punya keputusan ikut/tetap.
7. DB angka ini MariaDB 10.4.32 port 3306, bukan environment MariaDB 11.8.10 port 3307 yang menjadi acuan release.

## 8. Usulan desain Tahap B

### Wizard

Wizard khusus `Pindah Proyek Antar Cabang`; Edit Proyek biasa tetap menolak pemindahan proyek berdata. Wizard menampilkan:

- cabang/proyek asal dan tujuan yang dipilih eksplisit;
- dry-run impact map per tabel, termasuk semua histori;
- mapping user per user: Pindah/Tetap/Keduanya;
- pilihan branch-only data;
- status Sheet/cache dan Dana Talangan;
- blocker keras, warning, dan keputusan owner;
- estimasi batch, hash snapshot sebelum eksekusi, dan tombol konfirmasi terpisah.

Wizard tidak tersedia saat impersonate.

### Dry-run blocker

Tolak eksekusi bila ada:

- proyek/cabang asal atau tujuan tidak aktif/tidak sah;
- branch/proyek mismatch;
- duplicate holder kavling aktif/terjual;
- identity Sheet ambigu atau target `sheet_id` kosong saat diperlukan;
- Lead sync identity duplicate pada scope tujuan;
- pending delivery tanpa rencana retarget yang disetujui;
- cache Sheet tanpa identity stabil yang bisa diparitas-kan;
- Dana Talangan global conflict yang terdampak;
- user mapping belum lengkap;
- assignment organisasi menghasilkan cycle atau scope di luar actor;
- wizard dipanggil saat impersonate;
- invariant `id_transaksi` berubah atau history hilang dari batch.

### Eksekusi dan rollback

- Maintenance mode cabang asal dan tujuan.
- Transaction + `lockForUpdate()` pada proyek, cabang, assignment proyek, Lead, kavling, dan row terdampak.
- Batch ID dan snapshot nilai lama sebelum setiap batch.
- Pindahkan seluruh riwayat terkait; jangan membuat histori palsu.
- Simpan ActivityLog dengan actor, batch, asal/tujuan, jumlah per tabel, hash, dan keputusan user mapping.
- Commit per batch hanya bila desain rollback batch disetujui; jika satu batch gagal, hentikan queue dan rollback batch itu dari snapshot. Jangan membuat rollback parsial diam-diam.
- Setelah commit: jalankan parity terhadap Sheet cabang tujuan, audit relasi organisasi, dan verifikasi invariant kavling/Lead/transaksi.
- Buka maintenance dan queue hanya setelah smoke test serta approval owner.

### Tes dan gladi

Tahap B wajib memiliki Feature/Unit test untuk:

- authorization, scope, direct URL, dan penolakan impersonate;
- dry-run blocker setiap kategori;
- Pindah/Tetap/Keduanya dan role tambahan yang tidak menaikkan hak;
- atomic lock, retry/idempotency, batch rollback, dan ActivityLog;
- semua tabel project-scoped dan branch-only policy;
- Lead pending delivery dan stable sync identity;
- dua cache Sheet terpisah dan parity tujuan;
- Dana Talangan global;
- invariant satu holder kavling, immutable `id_transaksi`, serta histori lengkap.

Gladi harus memakai salinan DB dengan engine/versi target MariaDB 11.8.10, integrasi eksternal mock/non-production, dan dataset uji ber-prefix `UAT-`. Agent tidak menjalankan cutover production.

## 9. Keputusan owner yang diperlukan

1. Cabang tujuan untuk Kandeman/Tegal.
2. Apakah proyek 27 dan 28 dipindah bersama atau terpisah.
3. Mapping 14 user cabang 7 dan user lain yang muncul pada relasi proyek: Pindah/Tetap/Keduanya.
4. Kebijakan 2 Rencana Harian, 12 Promo, 5 arsip bukti, dan data branch-only lain.
5. Perlakuan 1.354 Lead pending delivery.
6. Perlakuan 12 Dana Talangan global dan 15 global conflict yang sudah ada.
7. Persetujuan desain wizard, maintenance dua cabang, batch rollback, dan parity.

Tahap B tidak dimulai sebelum keputusan di atas disetujui.
