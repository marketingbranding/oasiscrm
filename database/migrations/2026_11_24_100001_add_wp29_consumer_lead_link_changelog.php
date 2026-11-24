<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Penautan Lead dan Konsumen ter-audit';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'added',
                'description' => 'Ruang kerja Konsumen menyediakan pilihan Lead, Tanpa Lead, dan Data Lama beserta saran pasangan yang tidak menautkan otomatis. Keputusan penautan disimpan di OASIS dengan status eksplisit, snapshot saran, alasan, aktor, waktu, validasi, batas akses, serta riwayat koreksi untuk menjaga audit history.',
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
