<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Alur Pembuatan Pengguna dan Preview Organisasi';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Alur Tambah Pengguna disusun menjadi tiga langkah dengan penempatan organisasi berbasis peran, pilihan cabang dan proyek yang lebih ringkas, preview struktur ringan, serta mapping Koordinator Tim Sales yang tetap memakai sales_coordinator_sales.',
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
