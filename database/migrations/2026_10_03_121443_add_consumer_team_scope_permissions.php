<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $labels = [
            'view' => 'Melihat',
            'manage' => 'Mengelola',
            'export' => 'Mengekspor',
            'sync' => 'Menyinkronkan',
        ];

        foreach ($labels as $action => $label) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => "consumer_progress.{$action}_team"],
                [
                    'name' => "{$label} data tim Progress Konsumen",
                    'description' => "{$label} data tim pada modul Progress Konsumen.",
                    'group_name' => 'Progress Konsumen',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Penambahan scope tim Progress Konsumen'],
            [
                'description' => 'Permission Progress Konsumen kini mendukung scope tim untuk pemetaan RBAC eksplisit tanpa mengubah mapping role existing.',
                'category' => 'added',
                'created_by' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        $slugs = [
            'consumer_progress.view_team',
            'consumer_progress.manage_team',
            'consumer_progress.export_team',
            'consumer_progress.sync_team',
        ];
        $ids = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id');

        DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        DB::table('changelogs')->whereNull('version')->where('title', 'Penambahan scope tim Progress Konsumen')->delete();
    }
};
