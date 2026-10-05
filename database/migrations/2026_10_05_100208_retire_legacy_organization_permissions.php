<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->permissions() as $permission) {
            $permissionId = DB::table('permissions')
                ->where('slug', $permission['slug'])
                ->value('id');

            if ($permissionId === null || DB::table('role_permission')->where('permission_id', $permissionId)->exists()) {
                continue;
            }

            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Permission Organisasi Legacy Dihentikan'],
            [
                'description' => 'Permission organization.manage dan organization.configure_rules dihentikan karena struktur organisasi kini menggunakan unit Pusat/Cabang dan tingkat kewenangan.',
                'category' => 'changed',
                'created_by' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('changelogs')
            ->whereNull('version')
            ->where('title', 'Permission Organisasi Legacy Dihentikan')
            ->delete();

        foreach ($this->permissions() as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $permission['slug']],
                [...$permission, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    /**
     * @return list<array{name: string, slug: string, description: string, group_name: string}>
     */
    private function permissions(): array
    {
        return [
            [
                'name' => 'Mengelola struktur organisasi',
                'slug' => 'organization.manage',
                'description' => 'Mengelola konfigurasi struktur organisasi.',
                'group_name' => 'Organisasi',
            ],
            [
                'name' => 'Mengatur aturan relasi organisasi',
                'slug' => 'organization.configure_rules',
                'description' => 'Mengatur relasi parent-child antar peran.',
                'group_name' => 'Organisasi',
            ],
        ];
    }
};
