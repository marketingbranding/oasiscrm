# Rencana WP-2.6 Kembali ke NUP

## Audit
- `ConsumerNupService::returnFromTransaction()` sudah menolak transaksi selain `LANJUT`, melepas kavling, membuat NUP `returned`, menutup transaksi sebagai `DITUTUP`, dan mencatat audit.
- `ConsumerIssue` sudah mendukung buka/selesai kendala melalui `ConsumerProcessService`.
- UI detail transaksi belum menampilkan aksi Kembali ke NUP, riwayat provenance NUP, atau form selesai kendala.

## Paket kerja
1. Data detail + riwayat provenance NUP dan kendala, tetap memakai scope detail existing.
2. UI drawer detail: aksi Kembali ke NUP, kendala wajib, konfirmasi; aksi Selesaikan Kendala, penyelesaian wajib.
3. Tes BR-20–BR-22, scope, migration gate, idempotensi, kavling, UI contract.
4. Verifikasi focused/full suite, Pint, view cache, diff, commit dan push.

## Acceptance
- Transaksi lama `DITUTUP`, kavling dilepas, NUP baru tercatat dengan alasan dan tautan transaksi lama.
- Konversi ulang menampilkan transaksi baru pada riwayat NUP.
- Kendala selesai mewajibkan penyelesaian dan mencatat audit.
- Cabang `sheet`/migrasi menolak mutasi; pembacaan tetap tersedia.

## Checkpoint
- Paket 1: focused tests hijau.
- Paket 2: UI contract + focused tests hijau.
- Selesai: full suite hijau, branch pushed.
