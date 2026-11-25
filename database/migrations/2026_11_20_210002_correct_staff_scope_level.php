<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CHANGELOG_TITLE = 'Koreksi lingkup Staff Admin';

    public function up(): void
    {
        $staffRoleId = DB::table('roles')->where('slug', 'staff')->value('id');
        if ($staffRoleId) {
            DB::table('users')->where('role_id', $staffRoleId)->update([
                'scope_level' => 'cabang',
                'updated_at' => now(),
            ]);
        }

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::CHANGELOG_TITLE],
            [
                'category' => 'fixed',
                'description' => 'Staff Admin selalu memakai scope cabang dan tidak dipetakan ke scope pusat.',
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
