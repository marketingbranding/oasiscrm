<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CHANGELOG_TITLE = 'Perbarui profil izin Staff Admin PRD v3';

    public function up(): void
    {
        DB::table('roles')->where('slug', 'admin')->update([
            'name' => 'Staff Admin · Admin Cabang',
            'description' => 'Profil izin legacy untuk Staff Admin bertipe Admin Cabang.',
            'updated_at' => now(),
        ]);
        DB::table('roles')->where('slug', 'sas')->update([
            'name' => 'Staff Admin · Sales',
            'description' => 'Profil izin legacy untuk Staff Admin bertipe Sales.',
            'updated_at' => now(),
        ]);
        DB::table('roles')->where('slug', 'sam')->update([
            'name' => 'Staff Admin · Marketing',
            'description' => 'Profil izin legacy untuk Staff Admin bertipe Marketing.',
            'updated_at' => now(),
        ]);
        DB::table('roles')->where('slug', 'staff')->update([
            'name' => 'Staff Admin · Umum',
            'description' => 'Profil izin legacy untuk Staff Admin bertipe Umum.',
            'updated_at' => now(),
        ]);

        DB::table('users')
            ->where('role_id', DB::table('roles')->where('slug', 'staff')->value('id'))
            ->whereIn('staff_type', ['sales', 'marketing', 'umum', 'admin_cabang'])
            ->update(['scope_level' => 'cabang', 'updated_at' => now()]);

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::CHANGELOG_TITLE],
            [
                'category' => 'changed',
                'description' => 'Profil izin legacy kini diberi nama Staff Admin berdasarkan staff_type dan Staff Admin tidak pernah memiliki scope pusat.',
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
