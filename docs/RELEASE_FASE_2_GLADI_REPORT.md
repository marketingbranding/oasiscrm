# Laporan Gladi Fase 2

Tanggal gladi: 25 November 2026.

## Lingkungan

- Engine: MariaDB 11.8.10, port 3307.
- Database: `oasis_gladi`.
- Dump: dipulihkan tanpa menampilkan data pribadi.
- Tabel hasil restore: 120.
- `migrate:fresh` DB kosong: lulus.
- `migrate`: lulus, 318,96 detik.
- Duplikat kavling aktif/terjual: 0.
- Portable MariaDB dihentikan setelah pemeriksaan.
- XAMPP 3306 tidak disentuh.

## Parity cabang

Status semua cabang `TIDAK_LENGKAP`. Command exit 0 tidak dipakai sebagai bukti lulus.

| Branch ID | Status | Sheet only | OASIS only | Mismatch |
|---:|---|---:|---:|---:|
| 1 | TIDAK_LENGKAP | 232 | 0 | 0 |
| 2 | TIDAK_LENGKAP | 234 | 0 | 0 |
| 3 | TIDAK_LENGKAP | 197 | 0 | 0 |
| 4 | TIDAK_LENGKAP | 179 | 246 | 0 |
| 5 | TIDAK_LENGKAP | 0 | 0 | 0 |
| 6 | TIDAK_LENGKAP | 469 | 0 | 0 |
| 7 | TIDAK_LENGKAP | 399 | 0 | 0 |
| 8 | TIDAK_LENGKAP | 189 | 0 | 0 |
| 9 | TIDAK_LENGKAP | 0 | 0 | 0 |

## SQL agregat read-only

```sql
-- Transaksi per cabang dan status legacy
SELECT branch_id, application_status, COUNT(*) AS total
FROM consumer_applications
GROUP BY branch_id, application_status
ORDER BY branch_id, application_status;

-- Transaksi kavling konflik: ID kavling legacy yang muncul lebih dari sekali.
SELECT branch_id, id_kavling, COUNT(*) AS total
FROM consumer_applications
WHERE branch_id = 4 AND id_kavling IS NOT NULL AND TRIM(id_kavling) <> ''
GROUP BY branch_id, id_kavling
HAVING COUNT(*) > 1
ORDER BY id_kavling;

-- Kandidat carry-over per cabang: aplikasi dengan keputusan aktif pada subjek NUP/Sheet.
SELECT application.branch_id, COUNT(DISTINCT application.id) AS total
FROM consumer_applications AS application
JOIN consumer_lead_link_decisions AS decision
  ON decision.is_active = 1
 AND (decision.consumer_application_id = application.id
      OR decision.branch_id = application.branch_id)
GROUP BY application.branch_id
ORDER BY application.branch_id;

-- Dana Talangan belum ditemukan, dikelompokkan alasan umum
SELECT branch_id,
       CASE WHEN kavling_id IS NULL THEN 'tanpa_kavling'
            ELSE 'kavling_tidak_cocok' END AS alasan,
       COUNT(*) AS total
FROM dana_talangans
WHERE deleted_at IS NULL AND consumer_application_id IS NULL
GROUP BY branch_id, alasan
ORDER BY branch_id, alasan;

-- Organization unresolved: kolom diagnostik aktual dibaca dari output command.
-- Query dasar relasi legacy yang belum canonical:
SELECT branch_id, relationship_type, COUNT(*) AS total
FROM organization_assignments
WHERE relationship_type = 'sales_team_member_of'
GROUP BY branch_id, relationship_type
ORDER BY branch_id, relationship_type;
```

Catatan hasil yang tersedia dari command/output gladi:

- Transaksi per cabang: branch ID 4 = `draft` 117 dan `migration_pending` 129; total perlu tinjau 245 jika 1 status `Lanjut` ikut dihitung.
- Preview kavling branch ID 4: 39 conflict dari 246 kandidat. Service memakai ID kavling legacy sebagai kunci konflik; alasan umum: ID kavling muncul lebih dari sekali. Query menemukan 18 ID kavling legacy duplikat: 15 grup berjumlah 2 dan 3 grup berjumlah 3, total 39 kandidat konflik.
- Carry-over: 239 kandidat global. Command tidak menyediakan agregasi branch. Query agregasi branch ada di atas; distribusi branch belum terverifikasi.
- Dana Talangan tidak ditemukan: branch ID 6 = 5 tanpa kavling; branch ID 7 = 12 tanpa kavling; branch ID 8 = 3 tanpa kavling. Total 20.
- Organization unresolved `sales_team_member_of`: branch ID 2 = 3, branch ID 3 = 9, branch ID 7 = 1, branch ID 8 = 4. Total 17 relasi. Schema tidak memiliki kolom `classification`; jenis masalah yang tersedia hanya `relationship_type`.

## `migrate --pretend`

Exit 1. Lima baris error terakhir:

```text
RuntimeException
Required permissions missing for consumer migration reconciliation.
at database/migrations/2026_11_21_230001_add_consumer_migration_reconcile_permission.php:30
Illuminate\Database\Migrations\Migrator::runMethod(...)
Migration ... 2026_11_21_230001_add_consumer_migration_reconcile_permission.php::up()
```

Penyebab: dump sudah memiliki schema/data legacy, tetapi migration pretend berjalan dari state yang tidak memenuhi prerequisite permission. `migrate` normal sesudah restore lulus.

## Flag

Tetap mati:

- `org_hierarchical_monitoring=false`
- `lead_stages_v3=false`
- `transaction_events_v3=false`
- `dana_talangan_primary_source=false`
