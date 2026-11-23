# WP-2.4 Branch Migration Design

Tanggal: 2026-11-23
Status: Menunggu persetujuan desain
Pemilik produk: disetujui secara percakapan; implementasi belum dimulai

## Intent

Menyediakan kontrol migrasi data per cabang tanpa mengubah status cabang oleh agent atau command otomatis. Semua cabang mulai pada state `sheet`. Operator Superadmin dapat memindahkan state dengan alasan, audit, dan konfirmasi. Saat state `oasis_penuh`, Database dan Konsumen Progress menjadi read-only, lalu sinkron terjadwal per cabang dilewati tanpa menghapus data.

## Goals

- Tabel `branch_migration_states` dengan default idempoten `sheet` untuk semua cabang.
- State machine berurutan:
  `sheet -> migrasi -> paralel -> oasis_penuh`.
- Mundur hanya satu tahap per perubahan, dengan alasan wajib.
- ActivityLog hanya untuk perubahan state.
- Guard per cabang aktif hanya pada `oasis_penuh`.
- Scheduler mencatat skip per cabang di application log, maksimum satu ringkasan per cabang per hari; tidak membuat ActivityLog per putaran scheduler.
- Laporan parity per cabang dari data OASIS dan cermin sheet, tersedia melalui Artisan, halaman Superadmin, dan XLSX.
- Definisi metrik mengikuti Dashboard Marison:
  unit terjual, akad per bulan, posisi per proses, ready stock bangunan 100%, gugur per alasan kanonis, dan lead -> UTJ -> akad jika tautan tersedia.
- Selisih ditampilkan per baris/ID tanpa data pribadi, dengan status lulus/tidak per metrik.
- Operator runbook menjelaskan penggunaan parity sebelum perubahan state.

## Non-goals

- Agent tidak mengubah state cabang produksi atau lokal melalui seeder/command otomatis.
- Tidak ada penghapusan data.
- Tidak ada penyembunyian menu Database/Konsumen Progress; itu WP-3.6.
- Tidak ada perubahan sumber data global atau cutover otomatis.
- Tidak ada perubahan WP-2.9 selain pencatatan rencana yang disetujui.
- Tidak ada data pribadi dalam parity report atau ekspor.

## Constraints

- Laravel 13, Blade + Alpine.js, Tailwind yang sudah ada, PhpSpreadsheet yang sudah terpasang.
- Otorisasi Superadmin diuji pada URL langsung dan JSON; non-Superadmin harus 403.
- Semua perubahan schema additive dan migration idempotent.
- `ActivityLog` memakai pola audit yang sudah ada.
- Guard harus mempertahankan perilaku lama 100% untuk cabang `sheet`.
- Scheduler guard harus melewati cabang `oasis_penuh` untuk Database, Konsumen Progress, dan lifecycle lead.
- Nilai raw `transaction_status` tetap tersedia sebagai catatan; pemetaan kanonis reject SLIK/Bank menjadi `MUNDUR`.

## Approaches considered

1. Satu paket besar: ditolak karena sulit direview dan risiko regresi tinggi.
2. Fondasi -> parity -> integrasi: dipilih karena guard dan state dapat direview sebelum parity, lalu parity menjadi dasar operator sebelum cutover.
3. Parity dulu: ditolak karena belum menyediakan state/guard yang diperlukan untuk menguji perilaku transisi.

## Paket A — State, audit, guard, scheduler

Branch: `prd-v3/wp-2.4-a-branch-migration-state`

### Data

- Migration menambah `branch_migration_states` dengan `branch_id` unique, `state`, `changed_by` nullable, `changed_at`, `notes`, timestamps.
- State enum/value canonical: `sheet`, `migrasi`, `paralel`, `oasis_penuh`.
- Migration mengisi satu row `sheet` untuk setiap cabang yang ada, idempoten.
- Tidak ada default yang mengubah cabang setelah migration pertama.

### Service and authorization

- Satu service menjadi sumber aturan transisi dan status cabang.
- Transition validator mengizinkan maju satu tahap atau mundur satu tahap saja.
- Alasan wajib untuk setiap perubahan; actor wajib Superadmin dan akun aktif.
- Update state + ActivityLog berada dalam satu transaksi.
- ActivityLog menyimpan state lama, state baru, alasan, actor, dan branch ID.
- Tidak ada API/command tanpa actor terotorisasi yang dapat mengubah state.

### Read-only guard

- Guard aktif hanya ketika branch state `oasis_penuh`.
- Database dan Konsumen Progress mutating routes/service menolak perubahan dengan error yang sesuai.
- Read routes tetap berjalan.
- Cabang `sheet`, `migrasi`, dan `paralel` mempertahankan perilaku lama.
- Tidak ada delete path baru.

### Scheduler guard

- Database sync, Konsumen Progress sync, dan lifecycle lead memfilter `oasis_penuh` sebelum integrasi dipanggil.
- Skip mencatat alasan melalui `Log::info` atau ringkasan harian maksimal satu baris per cabang.
- Tidak membuat ActivityLog untuk skip scheduler.
- Tes membuktikan integrasi tidak dipanggil untuk `oasis_penuh` dan tetap dipanggil untuk `sheet`.

### UI

- Halaman Superadmin menampilkan semua cabang, state, waktu perubahan, actor, dan alasan.
- Form perubahan memakai konfirmasi, alasan wajib, dan named routes.
- Tidak menyembunyikan menu Database/Konsumen Progress.

### Paket A tests

- Migration default semua cabang `sheet`, idempotent.
- Transisi maju valid.
- Mundur satu tahap valid dengan alasan.
- Lompatan state ditolak.
- Mundur lebih dari satu tahap ditolak.
- Alasan kosong ditolak.
- ActivityLog hanya untuk perubahan state.
- `oasis_penuh` read-only; state lain perilaku lama.
- Scheduler skip `oasis_penuh`; scheduler normal `sheet`.
- Non-Superadmin 403 pada URL langsung dan JSON.

## Paket B — Parity report

Branch: `prd-v3/wp-2.4-b-branch-parity`

### Source and service

- Service parity menerima branch dan periode bila metrik memerlukannya.
- OASIS query dan sheet mirror query dipisah; tidak mengubah data.
- Google Sheets API di-mock dalam tes.
- Identitas perbandingan memakai ID bisnis/stabil yang tersedia, bukan nomor baris sheet sebagai identitas permanen.
- Output tidak memuat nama, nomor HP, NIK, alamat, atau data pribadi.

### Metrics

- Unit terjual.
- Akad per bulan.
- Posisi per proses.
- Ready stock hanya bangunan 100%.
- Gugur per alasan kanonis; REJECT SLIK dan REJECT BANK -> `MUNDUR`, raw `transaction_status` tetap dicatat sebagai metadata non-pribadi bila tersedia.
- Lead -> UTJ -> akad bila tautan tersedia.

### Result shape

- Per metric: OASIS total, sheet total, delta, status `lulus`/`tidak_lulus`.
- Detail mismatch per row/ID tanpa data pribadi.
- Missing, duplicate, unknown mapping, dan link unavailable dibedakan dari angka nol.
- Report disimpan/ditampilkan melalui service tanpa mengubah state cabang.

### Interfaces

- Artisan command parity per branch, read-only, dengan output ringkas dan lokasi report.
- Halaman Superadmin untuk menjalankan/melihat report.
- XLSX export memakai PhpSpreadsheet yang sudah ada.
- Semua route memakai permission/policy Superadmin.

### Paket B tests

- Data tanpa selisih menghasilkan semua metric lulus.
- Data dengan selisih menghasilkan delta dan detail ID.
- Ready stock memakai bangunan 100%.
- Reject SLIK/Bank dipetakan ke MUNDUR dan raw status tidak hilang.
- Lead/UTJ/akad link tersedia dan tidak tersedia.
- Output tidak mengandung data pribadi.
- Command, halaman, dan XLSX memakai report yang sama.
- Non-Superadmin 403 URL langsung dan JSON.

## Paket C — Integration, runbook, implementation log

Branch: `prd-v3/wp-2.4-c-integration`

### Scope

- Tes integrasi lintas Paket A dan B.
- Verifikasi state tetap tidak berubah saat parity command/report berjalan.
- Verifikasi `oasis_penuh` menjalankan guard dan scheduler skip tanpa menu hiding.
- Verifikasi operator flow: parity lulus -> Superadmin mengubah state dengan alasan -> audit tercatat.
- Runbook operator diperbarui untuk backup, parity, review mismatch, perubahan state, smoke test, observasi, dan mundur satu tahap.
- Log PRD mencatat WP-2.4 sebagai paket yang dikerjakan sesuai status aktual.
- Log mencatat rencana disetujui untuk WP-2.9, belum dikerjakan.

### WP-2.9 approved plan entry

Selama transisi spreadsheet tetap dipakai admin; OASIS mencerminkan Data Konsumen sampai BAST secara read-only; halaman menampilkan Konsumen belum tertaut; sistem menyarankan kandidat lead berdasarkan UTJ, proyek sama, serta kemiripan nama/HP sebagai SARAN; konfirmasi manusia tersedia untuk Admin Cabang/SAS/Koordinator; pilihan Tanpa Lead dan Data Lama untuk 250 konsumen lama; pegangan `[SYS] ID Konsumen` / `ID Konsumen Awal`; data janggal diberi label perlu tinjau tanpa memblokir; uji coba memakai salinan workbook dan lead dummy.

## Review gates

1. Implementasi dan tes Paket A, push sub-branch, review manusia.
2. Hanya setelah Paket A disetujui, implementasi Paket B, push sub-branch, review manusia.
3. Hanya setelah Paket B disetujui, implementasi Paket C, push sub-branch, review manusia.
4. Tidak merge Paket A/B/C durante review. Merge dilakukan hanya setelah review eksplisit.

## Resolved implementation decisions

### State lifecycle

- Missing state row resolves to `sheet`; this protects new branches and deployments where the row has not yet been created.
- Branch creation writes a `sheet` state through the existing branch creation service when possible. Resolver fallback remains mandatory.
- Migration backfill uses `insertOrIgnore` or equivalent non-overwriting insert. Rerun never overwrites an operator-changed state.
- Inactive branches remain visible in Superadmin state and parity pages; no branch is silently omitted.

### Authorization and audit

- State management and parity UI/export use a new registered permission, mapped only to `superadmin`; literal Superadmin remains required by policy. `pusat` does not inherit access.
- Direct URL and JSON requests use existing Laravel authorization behavior and return 403.
- State audit uses `ActivityLog` with `subject_type = Branch::class`, `subject_id = branch.id`, event `branch_migration_state_changed`, and properties `branch_id`, `from_state`, `to_state`, `reason`, `actor_id`, `changed_at`.
- Audit reason stores validated operator text; no actor name, phone, NIK, address, or other unnecessary personal data.
- `changed_at` is operator transition time. No-op transition is rejected and creates no audit.
- State update locks the state row with `lockForUpdate()` inside a transaction. A stale expected state fails validation and does not overwrite the newer state.

### Transition and errors

- State order is fixed: `sheet`, `migrasi`, `paralel`, `oasis_penuh`.
- Only adjacent forward/backward transitions are allowed.
- Reason is required for every change, trimmed, and bounded by the existing project validation convention; the exact bound is defined in the FormRequest and tested.
- Read-only guard uses the existing JSON-vs-redirect rendering path and a domain exception mapped to HTTP 403 with Indonesian message. It applies at service boundaries and HTTP/command entry points, not only hidden UI controls.

### Scheduler

- `oasis_penuh` filtering applies to every branch-aware Database sync, Konsumen Progress sync, and lead lifecycle sync entry point, including explicit `--branch` commands.
- Lead bridge writes are not lifecycle pulls and are separately reviewed in Paket A reconnaissance; no unverified path is silently opened.
- Skip logging uses an application log message with a cache deduplication key scoped by branch and application timezone date. Cache flush may cause a duplicate; it must never create ActivityLog. Message includes branch ID/name, sync type, state, and reason.
- Existing sync status tables are not mutated to fake success. A skipped run leaves last-successful data unchanged; command output reports `skipped`.

### UI and confirmation

- State page uses existing Superadmin administration route conventions, named routes, pagination, and existing CRM modal/Alpine confirmation pattern. It does not use browser `confirm()`.
- Confirmation requires explicit operator action; reason is validated inline and server-side.
- Database/Konsumen Progress navigation remains unchanged. Menu hiding is WP-3.6.

### Parity contract

- Paket B first maps existing OASIS fields, mirror sheet contracts, and Dashboard Marison definitions. It may implement only confirmed mappings.
- Unknown sheet headers, unavailable links, duplicate IDs, missing IDs, and unknown status mappings are explicit `needs_review`/`unavailable` results, never treated as equal or zero.
- Comparison identity precedence: stable OASIS/sheet sync ID or contract-defined business ID; sheet row number is never permanent identity.
- Parity is computed on demand and returned as a bounded report object; no persistent report file or database record is required in WP-2.4. XLSX is generated from the same object and streamed/downloaded through authorized routes.
- XLSX contains summary and mismatch sheets with metric, stable ID, side, delta/status, raw transaction status where non-PII, and review reason. No names, phones, NIK, addresses, raw payloads, or arbitrary untrusted headers are exported. Report size is bounded by server pagination/row limits and reports truncation as `needs_review`.
- CLI parity is read-only and server-operator controlled. UI/export require the registered Superadmin permission. CLI does not change migration state.

## Remaining design boundary

Paket A can proceed from confirmed existing service boundaries. Paket B must complete source-contract reconnaissance before metric code: exact mirror tabs/headers, OASIS query columns, Dashboard Marison period/timezone, and process/status mapping. If a source is unavailable, report `unavailable` rather than inventing a mapping.
