<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Monitoring Admin Cabang & Pratinjau Cetak';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Buku Saku Admin Cabang kini menggunakan monitoring Lead dan Agenda yang ringkas, sedangkan laporan resmi diarahkan ke Laporan Fee Sales dengan pratinjau cetak A4 yang konsisten.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', self::TITLE)->delete();
    }
};
