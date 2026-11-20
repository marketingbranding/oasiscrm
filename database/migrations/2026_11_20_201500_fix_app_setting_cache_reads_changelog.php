<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CHANGELOG_TITLE = 'Perbaiki cache Pengaturan Aturan';

    private const CHANGELOG_DESCRIPTION = 'Pembacaan Pengaturan Aturan kini memakai cache sebelum database, hanya menyimpan hasil pembacaan database yang berhasil, dan membersihkan cache setelah perubahan pengaturan.';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::CHANGELOG_TITLE],
            [
                'category' => 'fixed',
                'description' => self::CHANGELOG_DESCRIPTION,
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')
            ->whereNull('version')
            ->where('title', self::CHANGELOG_TITLE)
            ->where('description', self::CHANGELOG_DESCRIPTION)
            ->delete();
    }
};
