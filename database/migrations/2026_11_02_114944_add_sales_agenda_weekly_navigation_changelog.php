<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const TITLE = 'Navigasi Agenda menggunakan minggu Senin sampai Minggu';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Navigasi Agenda kini menampilkan tanggal Senin sampai Minggu dengan indikator rencana kosong dan agenda yang belum selesai.',
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
