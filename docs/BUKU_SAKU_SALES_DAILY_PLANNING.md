# Buku Saku Sales: Daily Planning

## Alur Daily Plan

Sales membuat satu Daily Plan per hari kerja dan mengisi Agenda dengan kategori, durasi efektif, serta `task_detail`. Jam mulai dan selesai dibuat otomatis dari urutan Agenda, dimulai pukul 09:00. Waktu 12:00–13:00 adalah makan siang dan tidak dihitung sebagai menit kerja; satu Agenda dapat melintasi jeda tersebut tanpa dipecah.

Total planning harus tepat 420 menit untuk submit. Draft boleh kurang dari 420 menit, tetapi tidak boleh lebih. Setelah submit, Sales tidak dapat mengubah planning, namun tetap dapat mengisi `activity_result` dan menyelesaikan Agenda miliknya. Koordinator Sales dengan mapping `sales_coordinator_sales` aktif dan masih berlaku dapat mengoreksi planning Daily Plan Team yang sudah submit, dengan syarat total tetap tepat 420 menit. Tidak ada approval, rejected, atau backfill Agenda historis.

## Target lead bulanan

Target disimpan per Sales dan bulan. Achievement menghitung `sales_leads.sales_user_id` dan `lead_date` pada bulan yang diminta; lead soft-deleted tidak dihitung. Jika baris target belum ada, tampilan menggunakan **Belum ditetapkan**, menampilkan aktual, dan menggunakan **N/A** untuk achievement.

## Permission dan monitoring

Sales mendapat `sales_targets.view_own`, `sales_daily_plans.view_own`, `sales_daily_plans.manage_own`, dan `sales_daily_plans.submit_own`. Coordinator memakai scope TEAM dan mapping aktif. Supervisor, Branch Manager, SAS, SAM, dan Manager lama hanya mendapat monitoring BRANCH read-only. Superadmin mengelola target dan memiliki wildcard permission terdaftar. Pusat, Admin, Staff, supplemental role, atau `supervisor_user_id` tidak memperoleh akses Phase 2 secara otomatis.

## Kompatibilitas

`content_items.daily_plan_id` dan `sort_order` nullable. Agenda lama tetap dapat memiliki durasi, detail, atau waktu kosong/manual dan tetap terlihat pada monitoring. Work Planner dan jalur sinkronisasi/import tidak terkena validasi Daily Plan baru. Nomor telepon tetap nullable di database dan pada sync/import/system path; hanya formulir interaktif Lead yang mewajibkannya, dengan normalisasi nomor yang sudah ada.

## Notifikasi dan scheduler

Notifikasi submit dikirim kepada Coordinator yang dipetakan; koreksi Coordinator dikirim kepada Sales owner. Pengingat Agenda dikirim 15 menit sebelum mulai, saat mulai, 15 menit sebelum berakhir, dan 15 menit setelah berakhir bila belum selesai. `sales_agenda_notification_deliveries` mencegah duplikasi secara atomik, termasuk mempertimbangkan perubahan waktu sebagai occurrence baru.

Command `oasis:sales-agenda-reminders` dijadwalkan setiap menit dengan overlap lock. **Task development ini tidak mengonfigurasi scheduler production.** Deployment harus memastikan host menjalankan `php artisan schedule:run` sekali setiap menit.

## Deploy dan migrasi

Migrasi Phase 2 bersifat additive: membuat tabel target, Daily Plan, delivery notifikasi, serta menambah kolom nullable pada `content_items`. Jalankan migrasi pada environment deployment sesuai prosedur backup dan rollback OASIS; tidak ada perubahan produksi atau data historis yang dilakukan oleh pekerjaan development ini.
