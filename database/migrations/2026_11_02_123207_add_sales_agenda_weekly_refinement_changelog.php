<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const TITLE = 'Navigasi mingguan Agenda dirapikan untuk alur struk';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Pemilih tanggal mingguan dihapus, jarak navigasi diperjelas, dan agenda Jumat menegaskan target efektif 480 menit.',
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
