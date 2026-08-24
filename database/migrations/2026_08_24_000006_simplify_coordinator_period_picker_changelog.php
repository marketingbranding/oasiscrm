<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Pemilihan Tanggal Koordinator Sales Disederhanakan';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'description' => 'Kolom Dari dan Sampai kini hanya ditampilkan saat Koordinator Sales memilih periode Kustom. Periode Hari Ini, Minggu Ini, dan Bulan Ini tetap memakai tanggal otomatis.',
                'category' => 'changed',
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
