# WP-2.4 Penyesuaian Matriks Input Status Cabang

## Intent

Tetapkan tempat input berdasarkan status migrasi cabang tanpa mengganti saklar global `OASIS_NEW_WORKSPACE_ENABLED`.

## Matriks perilaku

| Status | Input Workspace Konsumen OASIS | Tulis cermin sheet | Sinkron baca cermin sheet |
|---|---|---|---|
| `sheet` | Hanya-baca | Diizinkan seperti sekarang | Berjalan |
| `migrasi` | Hanya-baca | Diizinkan seperti sekarang | Berjalan |
| `paralel` | Aktif | Ditolak | Berjalan untuk bahan paritas |
| `oasis_penuh` | Aktif | Ditolak | Berhenti |

`OASIS_NEW_WORKSPACE_ENABLED` tetap menentukan ketersediaan fitur Workspace secara global. Status cabang menentukan izin per cabang.

## Pendekatan dipilih

Gunakan satu policy service di `BranchMigrationStateService`:

- `allowsOasisConsumerInput(Branch|int $branch): bool`
- `allowsSheetWrite(Branch|int $branch): bool`
- `allowsSheetSync(Branch|int $branch): bool`

Guard dipasang di boundary service, bukan hanya HTTP route:

- Workspace OASIS: `ConsumerEntryService`, `ConsumerOperationalService`, `ConsumerNupService`.
- Cermin sheet: `DatabaseSheetSyncService`, `DatabaseSheetWriteService`, `KonsumenProgressSyncService`, `SalesLeadLifecycleSyncService`.

`allowsOasisConsumerInput()` bernilai true hanya untuk `paralel` dan `oasis_penuh`. `allowsSheetWrite()` bernilai false untuk `paralel` dan `oasis_penuh`. `allowsSheetSync()` bernilai false hanya untuk `oasis_penuh`.

## Pesan error

Penolakan input Workspace pada status `sheet` atau `migrasi` memakai pesan:

> Cabang ini masih memakai spreadsheet untuk proses penjualan.

Penolakan tulis sheet pada status `paralel` atau `oasis_penuh` memakai pesan operasional yang menjelaskan bahwa input sudah diarahkan ke OASIS. Sinkronisasi `oasis_penuh` tetap skip dengan log deduplikasi yang ada.

## Tes

Tambah coverage status silang:

- `sheet`: input OASIS ditolak; tulis sheet berjalan; sinkron sheet berjalan.
- `migrasi`: input OASIS ditolak; tulis sheet berjalan; sinkron sheet berjalan.
- `paralel`: input OASIS berjalan; tulis sheet ditolak; sinkron sheet berjalan.
- `oasis_penuh`: input OASIS berjalan; tulis sheet ditolak; sinkron sheet skip.

Tes tidak mengubah `PrdV3D32ConsumerInputTest` yang sudah ada. Full suite wajib 0 failure.

## Dokumentasi operator

Perbarui runbook migrasi dengan langkah wajib: cek dan set `OASIS_NEW_WORKSPACE_ENABLED=true` di produksi sebelum cabang pertama dipindahkan ke `paralel`. Tidak ada perintah otomatis yang mengubah status cabang.

## Non-goals

- Tidak memindahkan sumber data produksi otomatis.
- Tidak mengubah status cabang atau menjalankan cutover produksi.
- Tidak membuat middleware baru atau policy kedua.
