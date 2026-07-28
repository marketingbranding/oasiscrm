<?php

namespace App\Support;

class PermissionCatalog
{
    private const MODULES = [
        'sales_pocketbook' => ['Buku Saku Sales', ['own', 'team', 'assigned', 'branch', 'all'], true],
        'work_planner' => ['Work Planner', ['own', 'team', 'assigned', 'branch', 'all'], true],
        'database' => ['Database', ['assigned', 'branch', 'all'], true],
        'consumer_progress' => ['Progress Konsumen', ['assigned', 'branch', 'all'], true],
        'bridge_fund' => ['Dana Talangan', ['assigned', 'branch', 'all'], true],
        'expenses' => ['Pengeluaran', ['assigned', 'branch', 'all'], true],
    ];

    private const ACTION_LABELS = [
        'view' => 'Melihat',
        'manage' => 'Mengelola',
        'export' => 'Mengekspor',
        'sync' => 'Menyinkronkan',
    ];

    private const SCOPE_LABELS = [
        'own' => 'data sendiri',
        'team' => 'data tim',
        'assigned' => 'data penugasan',
        'branch' => 'data cabang',
        'all' => 'semua data',
    ];

    public static function permissions(): array
    {
        $permissions = [
            ['name' => 'Melihat pengguna', 'slug' => 'users.view', 'description' => 'Melihat daftar dan detail akun pengguna.', 'group_name' => 'Pengguna'],
            ['name' => 'Membuat pengguna', 'slug' => 'users.create', 'description' => 'Membuat akun pengguna baru.', 'group_name' => 'Pengguna'],
            ['name' => 'Mengundang pengguna', 'slug' => 'users.invite', 'description' => 'Mengirim dan mengelola undangan akun pengguna.', 'group_name' => 'Pengguna'],
            ['name' => 'Memperbarui pengguna', 'slug' => 'users.update', 'description' => 'Memperbarui profil dan penugasan pengguna.', 'group_name' => 'Pengguna'],
            ['name' => 'Menangguhkan pengguna', 'slug' => 'users.suspend', 'description' => 'Menangguhkan akses akun pengguna.', 'group_name' => 'Pengguna'],
            ['name' => 'Menonaktifkan pengguna', 'slug' => 'users.deactivate', 'description' => 'Menonaktifkan akun pengguna.', 'group_name' => 'Pengguna'],
            ['name' => 'Mengaktifkan kembali pengguna', 'slug' => 'users.reactivate', 'description' => 'Mengaktifkan kembali akun pengguna.', 'group_name' => 'Pengguna'],
            ['name' => 'Mereset kata sandi pengguna', 'slug' => 'users.reset_password', 'description' => 'Memulai reset kata sandi akun pengguna.', 'group_name' => 'Pengguna'],
            ['name' => 'Mengekspor pengguna', 'slug' => 'users.export', 'description' => 'Mengekspor data pengguna.', 'group_name' => 'Pengguna'],
            ['name' => 'Menghapus permanen pengguna', 'slug' => 'users.delete_permanently', 'description' => 'Menghapus akun pengguna secara permanen.', 'group_name' => 'Pengguna'],
            ['name' => 'Menetapkan peran pengguna', 'slug' => 'users.assign_roles', 'description' => 'Menetapkan peran organisasi pengguna sesuai kewenangan.', 'group_name' => 'Pengguna'],
            ['name' => 'Menetapkan cabang pengguna', 'slug' => 'users.assign_branches', 'description' => 'Menetapkan cabang utama dan tambahan pengguna.', 'group_name' => 'Pengguna'],
            ['name' => 'Menetapkan proyek pengguna', 'slug' => 'users.assign_projects', 'description' => 'Menetapkan proyek utama dan tambahan pengguna.', 'group_name' => 'Pengguna'],
            ['name' => 'Menetapkan atasan pengguna', 'slug' => 'users.assign_supervisor', 'description' => 'Menetapkan atasan langsung pengguna.', 'group_name' => 'Pengguna'],
            ['name' => 'Mengekspor Buku Saku Sales', 'slug' => 'sales_pocketbook.export', 'description' => 'Mengekspor data Buku Saku Sales sesuai lingkup akses.', 'group_name' => 'Buku Saku Sales'],
            ['name' => 'Membuat Work Planner', 'slug' => 'work_planner.create', 'description' => 'Membuat item Work Planner.', 'group_name' => 'Work Planner'],
            ['name' => 'Memperbarui Work Planner', 'slug' => 'work_planner.update', 'description' => 'Memperbarui item Work Planner sesuai lingkup akses.', 'group_name' => 'Work Planner'],
            ['name' => 'Menetapkan Work Planner', 'slug' => 'work_planner.assign', 'description' => 'Menetapkan item Work Planner kepada pengguna lain.', 'group_name' => 'Work Planner'],
            ['name' => 'Mengekspor Work Planner', 'slug' => 'work_planner.export', 'description' => 'Mengekspor data Work Planner sesuai lingkup akses.', 'group_name' => 'Work Planner'],
            ['name' => 'Melihat Database', 'slug' => 'database.view', 'description' => 'Melihat data Database sesuai lingkup akses.', 'group_name' => 'Database'],
            ['name' => 'Mengubah Database', 'slug' => 'database.edit', 'description' => 'Mengubah data Database sesuai lingkup akses.', 'group_name' => 'Database'],
            ['name' => 'Menyinkronkan Database', 'slug' => 'database.sync', 'description' => 'Menjalankan sinkronisasi Database sesuai kewenangan.', 'group_name' => 'Database'],
            ['name' => 'Melihat Progress Konsumen', 'slug' => 'consumer_progress.view', 'description' => 'Melihat Progress Konsumen sesuai lingkup akses.', 'group_name' => 'Progress Konsumen'],
            ['name' => 'Menyinkronkan Progress Konsumen', 'slug' => 'consumer_progress.sync', 'description' => 'Menjalankan sinkronisasi Progress Konsumen.', 'group_name' => 'Progress Konsumen'],
            ['name' => 'Melihat Dana Talangan', 'slug' => 'bridge_fund.view', 'description' => 'Melihat Dana Talangan sesuai lingkup akses.', 'group_name' => 'Dana Talangan'],
            ['name' => 'Mengelola Dana Talangan', 'slug' => 'bridge_fund.manage', 'description' => 'Mengelola Dana Talangan sesuai lingkup akses.', 'group_name' => 'Dana Talangan'],
            ['name' => 'Mengekspor Dana Talangan', 'slug' => 'bridge_fund.export', 'description' => 'Mengekspor Dana Talangan sesuai lingkup akses.', 'group_name' => 'Dana Talangan'],
            ['name' => 'Melihat Pengeluaran', 'slug' => 'expenses.view', 'description' => 'Melihat data Pengeluaran.', 'group_name' => 'Pengeluaran'],
            ['name' => 'Membuat Pengeluaran', 'slug' => 'expenses.create', 'description' => 'Membuat pengeluaran manual.', 'group_name' => 'Pengeluaran'],
            ['name' => 'Memperbarui Pengeluaran', 'slug' => 'expenses.update', 'description' => 'Memperbarui pengeluaran manual.', 'group_name' => 'Pengeluaran'],
            ['name' => 'Membatalkan Pengeluaran', 'slug' => 'expenses.cancel', 'description' => 'Membatalkan pengeluaran dengan alasan.', 'group_name' => 'Pengeluaran'],
            ['name' => 'Mengekspor Pengeluaran', 'slug' => 'expenses.export', 'description' => 'Mengekspor laporan Pengeluaran.', 'group_name' => 'Pengeluaran'],
            ['name' => 'Mengelola kategori Pengeluaran', 'slug' => 'expenses.manage_categories', 'description' => 'Mengelola kategori Pengeluaran.', 'group_name' => 'Pengeluaran'],
        ];

        foreach (self::MODULES as $module => [$label, $scopes, $hasSync]) {
            foreach ($scopes as $scope) {
                $actions = $hasSync && ! in_array($module, ['sales_pocketbook', 'work_planner', 'expenses'], true)
                    ? ['view', 'manage', 'export', 'sync']
                    : ['view', 'manage', 'export'];

                foreach ($actions as $action) {
                    $permissions[] = [
                        'name' => self::ACTION_LABELS[$action].' '.self::SCOPE_LABELS[$scope].' '.$label,
                        'slug' => "{$module}.{$action}_{$scope}",
                        'description' => self::ACTION_LABELS[$action].' '.self::SCOPE_LABELS[$scope]." pada modul {$label}.",
                        'group_name' => $label,
                    ];
                }
            }

            $permissions[] = ['name' => "Mengatur konfigurasi {$label}", 'slug' => "{$module}.configure", 'description' => "Mengubah konfigurasi modul {$label}.", 'group_name' => $label];
            $permissions[] = ['name' => "Menghapus permanen data {$label}", 'slug' => "{$module}.delete_permanently", 'description' => "Menghapus data modul {$label} secara permanen.", 'group_name' => $label];
        }

        return [...$permissions,
            ['name' => 'Mengelola cabang', 'slug' => 'branches.manage', 'description' => 'Mengelola data dan konfigurasi cabang.', 'group_name' => 'Administrasi Sistem'],
            ['name' => 'Mengelola proyek', 'slug' => 'projects.manage', 'description' => 'Mengelola data dan konfigurasi proyek.', 'group_name' => 'Administrasi Sistem'],
            ['name' => 'Mengelola peran', 'slug' => 'roles.manage', 'description' => 'Mengelola peran organisasi.', 'group_name' => 'Administrasi Sistem'],
            ['name' => 'Mengelola izin', 'slug' => 'permissions.manage', 'description' => 'Mengelola pemetaan izin peran.', 'group_name' => 'Administrasi Sistem'],
            ['name' => 'Melihat kesehatan sistem', 'slug' => 'system_health.view', 'description' => 'Melihat status kesehatan dan layanan sistem.', 'group_name' => 'Administrasi Sistem'],
            ['name' => 'Melihat log aktivitas', 'slug' => 'activity_logs.view', 'description' => 'Melihat catatan aktivitas pengguna dan sistem.', 'group_name' => 'Administrasi Sistem'],
        ];
    }

    public static function rolePermissions(): array
    {
        $scoped = fn (array $modules, array $scopes, array $actions): array => collect($modules)
            ->flatMap(fn (string $module) => collect($scopes)->flatMap(
                fn (string $scope) => collect($actions)->map(fn (string $action) => "{$module}.{$action}_{$scope}")
            ))->all();

        $salesModules = ['sales_pocketbook', 'work_planner'];
        $operations = ['database', 'consumer_progress', 'bridge_fund', 'expenses'];
        $allModules = [...$salesModules, ...$operations];

        return [
            'sales' => [
                'sales_pocketbook.view_own', 'work_planner.view_own', 'work_planner.create', 'work_planner.update',
            ],
            'sales_coordinator' => [
                'sales_pocketbook.view_own', 'sales_pocketbook.view_team', 'work_planner.view_own',
                'work_planner.view_team', 'work_planner.create', 'work_planner.update', 'work_planner.assign',
            ],
            'supervisor' => [
                ...$scoped($salesModules, ['own', 'team', 'assigned'], ['view', 'manage', 'export']),
                ...$scoped($operations, ['assigned'], ['view', 'manage', 'export']),
                ...$scoped(['database', 'consumer_progress', 'bridge_fund'], ['assigned'], ['sync']),
                'work_planner.create', 'work_planner.update', 'work_planner.assign', 'work_planner.export',
                'sales_pocketbook.export', 'database.view', 'consumer_progress.view', 'bridge_fund.view',
            ],
            'manager' => [
                ...$scoped($allModules, ['assigned', 'branch'], ['view', 'export']),
                'sales_pocketbook.export', 'work_planner.create', 'work_planner.update', 'work_planner.assign',
                'work_planner.export', 'database.view', 'consumer_progress.view', 'bridge_fund.view',
            ],
            'branch_manager' => [
                'users.view', 'users.create', 'users.invite', 'users.update', 'users.reset_password', 'users.export',
                'users.assign_branches', 'users.assign_projects', 'users.assign_supervisor',
                'sales_pocketbook.export', 'work_planner.create', 'work_planner.update', 'work_planner.assign',
                'work_planner.export', 'database.view', 'database.edit', 'database.sync',
                'consumer_progress.view', 'consumer_progress.sync', 'bridge_fund.view', 'bridge_fund.manage', 'bridge_fund.export',
                ...$scoped($allModules, ['branch'], ['view', 'manage', 'export']),
                ...$scoped(['database', 'consumer_progress', 'bridge_fund'], ['branch'], ['sync']),
            ],
            'pusat' => [
                'users.view', 'users.create', 'users.invite', 'users.update', 'users.suspend', 'users.deactivate',
                'users.reactivate', 'users.reset_password', 'users.export', 'users.assign_roles',
                'users.assign_branches', 'users.assign_projects', 'users.assign_supervisor', 'activity_logs.view',
                'sales_pocketbook.export', 'work_planner.create', 'work_planner.update', 'work_planner.assign',
                'work_planner.export', 'database.view', 'database.edit', 'database.sync',
                'consumer_progress.view', 'consumer_progress.sync', 'bridge_fund.view', 'bridge_fund.manage',
                'bridge_fund.export', 'expenses.view', 'expenses.create', 'expenses.update', 'expenses.cancel', 'expenses.export',
                ...$scoped($allModules, ['all'], ['view', 'manage', 'export']),
                ...$scoped(['database', 'consumer_progress', 'bridge_fund'], ['all'], ['sync']),
            ],
            'admin' => [
                'users.view', 'users.create', 'users.invite', 'users.update', 'users.reset_password',
                'users.assign_branches', 'users.assign_projects', 'users.assign_supervisor',
                'work_planner.create', 'work_planner.update', 'work_planner.assign',
                'database.view', 'database.edit', 'database.sync', 'consumer_progress.view',
                'consumer_progress.sync', 'bridge_fund.view', 'bridge_fund.manage', 'bridge_fund.export',
                ...$scoped($allModules, ['branch'], ['view', 'manage', 'export']),
                ...$scoped(['database', 'consumer_progress', 'bridge_fund'], ['branch'], ['sync']),
            ],
            'staff' => [
                'work_planner.create', 'work_planner.update', 'database.view',
                ...$scoped($salesModules, ['own'], ['view', 'manage']),
                ...$scoped($operations, ['assigned'], ['view', 'manage']),
            ],
        ];
    }
}
