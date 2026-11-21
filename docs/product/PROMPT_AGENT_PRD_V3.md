# Prompt Agent — Implementasi PRD OASIS v3 (end-to-end)

> Salin seluruh isi di bawah garis ke AI coding agent (Claude Code / opencode) yang dijalankan di root repo `oasiscrm`.
> Pastikan dulu tiga file ini sudah ada di repo: `docs/product/PRD_OASIS_CRM_V3.md`, `AGENTS.md` (versi baru), dan `docs/UI_STANDARDS.md`.

---

## Peran dan tujuan

Kamu adalah engineer senior Laravel yang bertugas mengubah OASIS CRM **secara struktural dan end-to-end** agar sesuai dengan `docs/product/PRD_OASIS_CRM_V3.md` (PRD v3). Kerjakan Fase 1, Fase 2, dan Fase 3 sampai seluruh item berlabel **[Perbaikan]** dan **[Baru]** di PRD v3 terimplementasi, teruji, dan terdokumentasi. Satu-satunya pengecualian adalah tindakan operator produksi (lihat "Batas wewenang").

Sebelum mulai, baca utuh dan patuhi:
1. `AGENTS.md`: aturan kerja wajib, urutan sumber kebenaran, larangan, dan checklist.
2. `docs/product/PRD_OASIS_CRM_V3.md`: terutama §3 Glosarium, §4 Role, §6 Aturan Bisnis (BR-xx), §7 Modul, §9 Arsitektur, §10 Skema, §12 Fase, §13 Keputusan.
3. `docs/UI_STANDARDS.md`: untuk setiap perubahan tampilan.

PRD v3 adalah **izin eksplisit** untuk mengubah perilaku produksi pada item yang disebutkannya. Untuk hal lain, pertahankan perilaku yang ada.

## Batas wewenang

- **Boleh:** membuat migrasi additive, model, service, policy, permission, route, view, modul Alpine, perintah artisan, feature flag, tes, dokumentasi, dan entri Changelog.
- **Tidak boleh (siapkan saja, jangan eksekusi):** mengaktifkan mode kanonis graf organisasi di produksi, mematikan sinkron Google untuk cabang mana pun, membekukan sheet Talangan, menghapus role lama, menghapus kolom lama, atau menjalankan perintah apa pun terhadap database produksi.
- **Berhenti dan tanya** bila: sebuah keputusan tidak diatur PRD v3 dan menentukan bentuk data; ada potensi kehilangan data; atau tes lama harus diubah maknanya, bukan sekadar disesuaikan. Catat dulu di log, lanjutkan paket kerja lain yang tidak bergantung, lalu ajukan pertanyaannya.

## Cara bekerja

1. **Paket kerja 0 (wajib sebelum kode):**
   - Buat `docs/product/PRD_V3_IMPLEMENTATION_LOG.md` berisi:
     - tabel semua paket kerja di bawah (ID, fase, BR terkait, status, cabang git, PR);
     - **register konflik** antara kode dan PRD v3 hasil pemeriksaanmu;
     - **peta dampak** (file, tabel, permission, route, tes yang tersentuh per paket).
   - Inventaris semua angka/aturan yang di-hardcode dan termasuk aturan ⚙ di PRD v3. Contohnya `SalesDailyPlan::REQUIRED_WORK_MINUTES = 420`, jam mulai `09:00` di service Daily Plan, dan pesan validasi "420 menit".
   - Jalankan `composer test` untuk mencatat baseline (berapa tes, apa yang sudah gagal sebelum kamu mulai).
2. **Satu fase = satu cabang git** (`prd-v3/fase-1-fondasi`, `prd-v3/fase-2-pemantauan-data`, `prd-v3/fase-3-integrasi`). Setiap paket kerja di-commit terpisah dengan pesan `[WP-x.y] ringkasan`. Fase berikutnya dimulai dari fase sebelumnya setelah fase itu hijau.
3. **Setiap paket kerja** mengikuti alur `AGENTS.md` §3: telusuri → rencana → ubah → tes → catat. Setiap aturan BR yang disentuh punya tes yang menyebut ID-nya. Aturan ⚙ dites dengan nilai bawaan dan nilai yang diubah.
4. **Perubahan perilaku berisiko dipasang di balik feature flag** di Pengaturan (WP-1.2). Nilai bawaan flag di produksi = **mati**, kecuali paket kerjanya menyatakan lain. Tes mencakup keadaan flag hidup dan mati.
5. **Migrasi data lama** selalu disertai perintah artisan `--dry-run` yang mencetak ringkasan (jumlah baris per kategori dan daftar kasus yang perlu ditinjau) sebelum versi tanpa `--dry-run`. Migrasi skema harus aman dijalankan di database yang sudah berisi data.
6. **Di akhir setiap paket kerja**, perbarui log: status, file utama, tes BR, catatan, dan konflik baru.
7. **Di akhir setiap fase**, tulis ringkasan fase di log, jalankan checklist `AGENTS.md` §9 secara penuh, lalu laporkan (format di bagian bawah).

---

## Fase 1 — Rapikan Fondasi

**WP-1.1 Pengaturan Aturan (§7.2.4)**
- Buat tabel `app_settings` dan service pengaturan bertipe (nilai bawaan ada di kode, override di database, di-cache dan dibersihkan saat disimpan). Setiap perubahan pengaturan diaudit.
- Buat halaman khusus Superadmin dengan permission baru di `PermissionCatalog`.
- Pindahkan semua aturan ⚙ dari inventaris WP-0 ke service ini. Nilai bawaan mengikuti PRD v3.

**WP-1.2 Feature flag**
- Buat flag di Pengaturan untuk: `org_hierarchical_monitoring`, `lead_stages_v3`, `work_hours_v3`, `transaction_events_v3`.
- Sediakan helper tunggal untuk membaca flag, dan jangan membaca konfigurasi langsung di view.

**WP-1.3 Role dan atribut user (§4.1, D6, D21, D25)**
- Tambah role `ass_manager`.
- Tambah kolom `users.sales_type`, `users.staff_type`, `users.scope_level` (nullable, dengan backfill yang masuk akal).
- Gabungkan `admin` → `staff` + `staff_type=admin_cabang`, `sas` → `sales`, `sam` → `marketing`, dan `staff` lama → `umum`.
- Permission Staff Admin dipetakan **per jenis**, sehingga hasil efektif setiap pengguna **sama persis** dengan `tests/Fixtures/permission_snapshot_pre_v3.json`. Buktikan dengan tes.
- Setelah paritas terbukti, di commit terpisah: beri SAS hak input NUP & Proses Penjualan (D32).
- Sediakan perintah `oasis:roles-v3-migrate --dry-run`. Role lama **tidak dihapus**, hanya ditandai usang.
- Perbarui form pengguna, impor pengguna massal, undangan, dan semua pemeriksaan `sas`/`sam` di kode, policy, dan Blade.

**WP-1.4 Graf organisasi kanonis + pemantauan berjenjang (§4.2–4.3, BR-50–52)**
- Siapkan semua prasyarat mode kanonis (backfill, laporan paritas, proyeksi kompatibilitas) dan perintah verifikasinya. **Jangan aktifkan di produksi.**
- Di balik flag `org_hierarchical_monitoring`, ubah scope pemantauan Supervisor/Branch Manager/Manager/Ass. Manager dari "satu cabang" menjadi **turunan pada graf ∩ cakupan kerja**, dengan pengecualian `scope_level=pusat` untuk pimpinan = semua cabang (BR-52).
- Terapkan **detail menurun sesuai jarak** (bawahan langsung penuh, turunan jauh ringkas) di Lead Tim, Rencana Harian Tim, dan Riwayat Tim, termasuk ekspor dan KPI.
- Hanya Koordinator yang bisa mengubah data tim. Semua role pemantau lain hanya membaca (tes pada setiap endpoint mutasi).
- Isolasi antar tim Koordinator diuji eksplisit.

**WP-1.5 Jam kerja dan Rencana Harian Sales (BR-01–09, D2, D19)**
- Jam masuk, istirahat, dan target per hari dibaca dari Pengaturan: Senin–Kamis 08.00–17.00 dengan istirahat 12–13; Jumat 07.30–17.00 dengan istirahat 11.30–13.00; keduanya 480 menit.
- Sabtu, Minggu, dan tanggal di tabel `holidays` (nasional + per cabang) **bebas**: tanpa target, tanpa kewajiban kirim, tidak dihitung di capaian, tidak memicu penanda "belum kirim". Kegiatan tetap boleh dicatat dan pengingat tetap jalan.
- **Rencana lama tidak boleh menjadi tidak valid.** Simpan `target_minutes` dan jadwal yang berlaku pada setiap Daily Plan saat dibuat/dikirim, lalu validasi koreksi memakai target yang tersimpan di rencana itu. Backfill rencana lama dengan 420.
- Perbarui semua pesan validasi, UI, ekspor, dan pengingat (BR-06).
- Buat halaman pengelolaan daftar hari libur.

**WP-1.6 Tahap lead v3 (BR-10–16, D3, D4)**
- Tahap: Lead Baru → Dihubungi → Tatap Muka → Survey → UTJ, ditambah status **Gugur** dengan alasan dari Pengaturan.
- `documents_completed_at` dan `akad_at` **tidak lagi menjadi tahap**: datanya dipertahankan dan ditampilkan sebagai keterangan dari transaksi yang tertaut.
- Follow-up menjadi pengingat/kegiatan dan daftar "Perlu dihubungi", bukan tahap.
- Sales terkunci setelah UTJ (BR-14), baik di server maupun UI.
- Sediakan perintah migrasi tahap lama `--dry-run` dengan pemetaan yang dilaporkan per kategori.
- Perhatikan sinkron lifecycle ke workbook cabang yang masih aktif: perubahan tahap tidak boleh merusak kontrak sheet. Bila kontraknya bertentangan, catat sebagai konflik dan pertahankan kolom sheet lama lewat pemetaan.

**WP-1.7 NUP, status, dan event transaksi (BR-20–36, D10, D11, D14, D22–D24, D26)**
- Status transaksi: **Lanjut/Mundur/Ditutup**. Ditutup hanya lewat event Ganti Nama atau Kembali ke NUP, dan tidak dihitung aktif maupun gugur. Selesai diturunkan dari proses. Mundur wajib beralasan, dengan daftar di Pengaturan yang mencakup Reject SLIK dan Reject Bank.
- "Diganti Konsumen" diubah menjadi **event Ganti Nama**. Pindah Kavling dan Ganti Bank tetap event.
- NUP versi baru: berstatus sudah UTJ dan tanpa kavling. Masuk dari lead UTJ, atau lewat event baru **Kembali ke NUP** (kavling dilepas atomik, transaksi ditutup dengan event, kendala wajib tertaut, kolom `source_application_id`, `issue_id`, `entry_type`).
- **Jadikan Konsumen** sebelum UTJ hanya oleh Admin Cabang, SAS, dan Koordinator, dengan alasan wajib yang tercatat sebagai pengecualian (BR-23).
- Pertahankan invarian satu kavling = satu transaksi Lanjut (tes konkurensi/lock) dan konversi yang idempoten.

**WP-1.8 Istilah dan navigasi (§3, D15)**
- Ganti label menu, judul halaman, dan pesan sesuai glosarium: "Rencana Harian Sales", "Tugas & Konten", "Gugur", dll.
- Jangan mengubah nama route atau tabel hanya demi istilah. Cukup ganti label tampilan.

**WP-1.9 Data pribadi untuk AI dan backup (BR-70, §11)**
- Tambahkan filter di jalur AI Chat yang membuang NIK/HP/alamat dari konteks dan hasil alat sebelum dikirim ke penyedia. Toggle kirim nama ada di Pengaturan.
- Tes membuktikan data tersebut tidak pernah ada di payload ke penyedia.
- Sediakan perintah backup database ke disk yang dapat dikonfigurasi terpisah dari server, beserta dokumentasi jadwal dan uji pemulihan. Jangan menyimpan kredensial di repo.

**WP-1.10 Penutup Fase 1:** Changelog per kelompok perubahan, pembaruan README dan AGENTS.md (bagian FAKTA yang berubah), `composer test` hijau, build, dan laporan fase.

---

## Fase 2 — Pemantauan dan Data

**WP-2.1 Alih Lead (BR-54, BR-55, D27)**
- Tabel `sales_lead_transfers`. Aksi tunggal dan massal hanya oleh Koordinator, di dalam timnya, dengan alasan dari Pengaturan.
- Notifikasi ke Sales tujuan. Riwayat tidak dapat dihapus dan tampil di lead dan Riwayat Tim.
- Saat menonaktifkan Sales, sistem memaksa alih lead atau memasukkannya ke antrean "Lead tanpa pemilik aktif".

**WP-2.2 Izin Akses Khusus (BR-53)**
- Tabel `access_grants` dengan masa berlaku. Superadmin mengelolanya.
- Grant terintegrasi ke `OrganizationScopeService` (bukan logika paralel) dan diaudit.

**WP-2.3 Penanda perhatian**
- Lead tanpa aktivitas > X hari ⚙ dan rencana belum dikirim setelah jam ⚙ (hanya hari kerja, mematuhi BR-08).

**WP-2.4 Status peralihan cabang (§9.3)**
- Tabel `branch_migration_states` dengan keadaan Sheet → Migrasi → Paralel → OASIS penuh, halaman Superadmin, dan audit.
- Modul Database/Konsumen Progress/sinkron lifecycle membaca status ini: cabang berstatus "OASIS penuh" menjadi hanya-baca dan sinkronnya dilewati. Perubahan status hanya dilakukan operator.
- Sediakan **laporan paritas** per cabang: unit terjual, akad per bulan, posisi per proses, gugur per alasan. Definisinya disamakan dengan Dashboard Marison: ready stock = bangunan 100%; gugur = Mundur/Reject SLIK/Reject Bank.

**WP-2.5 Dana Talangan di OASIS (BR-42–44, D12, D28)**
- Tambah relasi `consumer_application_id` dan `kavling_id`, dengan backfill pencocokan `--dry-run` dan daftar yang perlu ditinjau.
- Tabel `dana_talangan_payments` dengan unggah bukti ke disk privat, verifikasi, sisa tagihan, dan status Lunas otomatis bila total terverifikasi ≥ nominal.
- Laporan per cabang/proyek/periode beserta ekspor.
- Siapkan mode "OASIS sumber utama" yang menghentikan tulis/tarik ke sheet `Talangan` secara **global** di balik flag. **Jangan aktifkan.**

**WP-2.6 Kembali ke NUP di Workspace Konsumen.** Lengkapi UI, riwayat NUP yang menampilkan transaksi sebelumnya, dan aksi Selesaikan kendala.

**WP-2.7 Penutup Fase 2** (sama seperti WP-1.10).

---

## Fase 3 — Integrasi dan Kecerdasan

**WP-3.1 WhatsApp 1 nomor (BR-60–64, D16)**
- Buat interface driver gateway dengan satu driver HTTP generik yang dapat dikonfigurasi untuk gateway tidak resmi. Driver *fake* untuk tes.
- Pengaturan nomor (QR/status), kirim dari lead dengan templat, webhook pesan masuk yang terverifikasi (secret), pencocokan nomor ternormalisasi, dan kotak **Belum Tertaut**.
- Batas kirim per menit/hari dan jeda acak ⚙. Tidak ada broadcast.
- Sales hanya melihat percakapan lead miliknya, Koordinator melihat percakapan timnya.
- Cadangan `wa.me` saat terputus, dan notifikasi ke Superadmin.
- Pengiriman berjalan lewat queue.

**WP-3.2 Asisten AI per peran (§7.13, D20)**
- Kembangkan `AiAssistantService`/`AiToolRegistry` yang ada menjadi profil Sales, Koordinator, Pimpinan, dan Umum (pemetaan role sesuai PRD v3; Ass. Manager memakai Pimpinan).
- Semua alat **hanya membaca**, memakai scope yang sama dengan layar, dan menyebut periode serta sumber angka.
- Filter data pribadi dari WP-1.9 tetap berlaku.

**WP-3.3 Push notification PWA dan preferensi**
- Tabel `push_subscriptions` dan `user_notification_preferences`.
- Jenis notifikasi sesuai §7.11.
- Service worker **tidak** meng-cache halaman/data CRM. Jika butuh dependensi Web Push, tulis alasannya di PR.

**WP-3.4 Kalender gabungan.** Hanya baca, menggabungkan Rencana Harian Sales (dalam scope) dan Tugas & Konten. Dibuat dengan Blade/Alpine tanpa library kalender baru, kecuali ada alasan kuat yang dicatat.

**WP-3.5 Dashboard Pimpinan (§7.12)**
- KPI per cabang/proyek/tim dalam scope pemantau, memakai definisi yang sama dengan laporan paritas WP-2.4.
- Unduh ringkasan rapat (XLSX via PhpSpreadsheet; PDF hanya bila sudah ada mekanisme cetak yang bisa dipakai).

**WP-3.6 Pensiun modul transisi.** Untuk cabang berstatus "OASIS penuh": sembunyikan menu Database/Konsumen Progress, jadwal sinkron melewatinya, dan sediakan pesan pengalihan. Kode tetap ada sampai semua cabang selesai.

**WP-3.7 Penutup akhir**
- Perbarui README, AGENTS.md (FAKTA → kondisi baru), dan PRD v3 bila ada klarifikasi.
- Lengkapi log implementasi: semua WP selesai atau tercatat alasannya.
- Tulis **runbook operator** di `docs/product/PRD_V3_CUTOVER_RUNBOOK.md` dengan urutan aman perintah produksi: backup → migrate → dry-run per perintah → aktifkan flag per tahap → peralihan per cabang, beserta cara rollback via flag.

---

## Format laporan di akhir setiap fase

```
## Laporan Fase N
- Cabang git / PR:
- Paket kerja selesai: WP-… (ringkas 1 baris per WP)
- Aturan BR yang diimplementasi + nama tesnya:
- Migrasi baru & perintah artisan (+ hasil --dry-run di data lokal/seed):
- Feature flag baru & nilai bawaan:
- Hasil: composer test (jumlah lulus/gagal vs baseline), pint, diff --check, npm run build
- Konflik / keputusan yang menunggu pemilik produk:
- Risiko & langkah operator yang dibutuhkan sebelum produksi:
```

Mulai dengan **Paket kerja 0**. Tunjukkan isi awal `PRD_V3_IMPLEMENTATION_LOG.md` (terutama register konflik dan inventaris aturan hardcode) sebelum menulis kode Fase 1.
