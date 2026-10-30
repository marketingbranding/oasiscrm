<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Dana Talangan Siap Operasional Senin';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'description' => 'Dana Talangan kini mendukung Nominal, status Belum Ditentukan, filter Marketing, ringkasan operasional, ekspor terfilter, dan normalisasi workbook yang lebih aman tanpa mengubah otorisasi atau alur sinkronisasi.',
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
