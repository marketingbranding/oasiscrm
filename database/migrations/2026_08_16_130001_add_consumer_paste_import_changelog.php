<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Import Paste Data Konsumen Lokal'],
            [
                'category' => 'added',
                'description' => 'Import TSV manual dan rekonsiliasi data konsumen lokal tersedia untuk Superadmin. Jalur baca/tulis operasional dan integrasi Google tetap aktif.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', 'Import Paste Data Konsumen Lokal')->delete();
    }
};
