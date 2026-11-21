<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SLUGS = [
        'work_planner.create',
        'work_planner.update',
        'work_planner.assign',
        'work_planner.export',
    ];

    public function up(): void
    {
        $permissions = collect(PermissionCatalog::permissions())
            ->whereIn('slug', self::SLUGS);

        foreach ($permissions as $permission) {
            DB::table('permissions')
                ->where('slug', $permission['slug'])
                ->update([
                    'name' => $permission['name'],
                    'description' => $permission['description'],
                    'group_name' => $permission['group_name'],
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Metadata-only correction. Keep canonical glossary labels on rollback.
    }
};
