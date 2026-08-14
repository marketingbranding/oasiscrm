<?php

return [
    'database' => ['label' => 'Database', 'description' => 'Data konsumen dan sinkronisasi spreadsheet cabang.', 'route_patterns' => ['database.*']],
    'sales_pocketbook' => ['label' => 'Buku Saku Sales', 'description' => 'Lead, agenda, laporan, dan siklus penjualan.', 'route_patterns' => ['sales-pocketbook.*', 'sales-fee-reports.*', 'sales-leads.*', 'sales-agendas.*', 'coordinator-leads.*', 'sales-reminders.*']],
    'promo' => ['label' => 'Promo', 'description' => 'Pengelolaan promo penjualan.', 'route_patterns' => ['promos.*']],
    'consumer_progress' => ['label' => 'Konsumen Progress', 'description' => 'Pemantauan progres konsumen.', 'route_patterns' => ['konsumen-progress.*']],
    'dana_talangan' => ['label' => 'Dana Talangan', 'description' => 'Pengelolaan Dana Talangan.', 'route_patterns' => ['dana-talangan.*']],
    'work_planner' => ['label' => 'Work Planner', 'description' => 'Tugas, agenda, dan konten kerja.', 'route_patterns' => ['content-calendar.*']],
    'feedback_reports' => ['label' => 'Laporan Feedback', 'description' => 'Pelaporan dan tindak lanjut feedback.', 'route_patterns' => ['feedback-reports.*']],
    'users' => ['label' => 'Pengguna', 'description' => 'Administrasi pengguna OASIS.', 'route_patterns' => ['admin-users.*']],
    'branches' => ['label' => 'Cabang', 'description' => 'Administrasi cabang.', 'route_patterns' => ['branches.*']],
    'projects' => ['label' => 'Proyek', 'description' => 'Administrasi proyek perumahan.', 'route_patterns' => ['projects.*']],
    'kavling' => ['label' => 'Kavling', 'description' => 'Administrasi kavling proyek.', 'route_patterns' => ['kavlings.*']],
];
