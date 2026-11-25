# PR: Fase 1 + Fase 2 — Pemantauan Data

## Ringkasan

Menggabungkan `prd-v3/fase-2-pemantauan-data` ke `main`.

- Fase 1: Pengaturan, flag, atribut role, Staff Admin, tahap Lead, status transaksi, glossary, dan hardening AI/backup.
- Fase 2: Alih Lead, antrean tanpa pemilik, izin akses khusus, penanda perhatian, status migrasi cabang, paritas, Dana Talangan, kembali ke NUP, Riwayat Tim, serta penautan Lead ke Konsumen Sheet/NUP.
- Perbaikan MariaDB: FK pendek, kompatibilitas penghapusan index, generated column yang kompatibel dengan MariaDB 11.8.

## Dokumen

- [Ringkasan Fase 1+2](RELEASE_FASE_2_SUMMARY.md)
- [Laporan Gladi Fase 2](RELEASE_FASE_2_GLADI_REPORT.md)
- [Runbook rilis](RELEASE_RUNBOOK_FASE_1_2.md)

## Hasil gladi

- MariaDB 11.8.10 portable, port 3307.
- `migrate:fresh` DB kosong: lulus.
- Restore dump: 120 tabel.
- `migrate`: lulus, 318,96 detik.
- Duplikat kavling aktif/terjual: 0.
- Parity cabang 1–9: semua `TIDAK_LENGKAP`; detail ada di laporan gladi.
- Konflik kavling branch ID 4: 39.
- Transaksi perlu tinjau: 245.
- Dana Talangan tidak ditemukan: 20.
- Relasi organization unresolved: 17.

## Flag tetap mati

- `org_hierarchical_monitoring=false`
- `lead_stages_v3=false`
- `transaction_events_v3=false`
- `dana_talangan_primary_source=false`

Merge oleh pemilik produk setelah review hasil gladi dan gerbang cutover cabang. Jangan merge otomatis.