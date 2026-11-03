<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Ruang Kerja Agenda Sales Lebih Ringkas';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Workspace Agenda dan Lead Saya kini menggunakan susunan referensi yang lebih ringkas, dengan ringkasan jam kerja, tab tanggal, status kosong, dan aksi tambah rencana yang lebih jelas di mobile.',
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
