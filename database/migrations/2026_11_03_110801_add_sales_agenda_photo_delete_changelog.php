<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Foto Agenda Sales dapat dihapus sebelum agenda selesai';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'added',
                'description' => 'Sales dapat menghapus foto agenda yang keliru melalui tombol X merah dengan konfirmasi sebelum agenda diselesaikan.',
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
