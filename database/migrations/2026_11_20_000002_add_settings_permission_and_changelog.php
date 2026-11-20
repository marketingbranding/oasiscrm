<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CHANGELOG_TITLE = 'Pengaturan Aturan dan Feature Flag PRD v3';

    public function up(): void
    {
        $permission = collect(PermissionCatalog::permissions())
            ->firstWhere('slug', 'system.settings_manage');

        if ($permission !== null) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $permission['slug']],
                [...$permission, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::CHANGELOG_TITLE],
            [
                'category' => 'added',
                'description' => 'Superadmin dapat mengelola Pengaturan Aturan dan feature flag PRD v3 dengan nilai bertipe, cache, dan audit perubahan.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', self::CHANGELOG_TITLE)->delete();
    }
};
