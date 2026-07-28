<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Laporan dan Ekspor Pengeluaran';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'added',
                'description' => 'Daftar pengeluaran kini dapat difilter dan diurutkan dengan ringkasan periode serta perbandingan periode sebelumnya. Hasil yang sama dapat diunduh sebagai laporan Excel berisi ringkasan, detail, dan rekap per cabang, proyek, serta kategori.',
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
