<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Kalender Kerja dan Hari Libur Agenda Sales';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Jam kerja Agenda Sales kini mengikuti pengaturan runtime dengan target 480 menit, jadwal Jumat, dan hari libur nasional atau khusus cabang sebagai hari bebas target. Onboarding Sales diperbarui mengikuti aturan ini.',
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
            ->where('title', self::TITLE)
            ->delete();
    }
};
