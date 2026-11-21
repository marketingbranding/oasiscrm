<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Tahap Lead v3 dan Gugur';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'added',
                'description' => 'Menambahkan tahap Lead Baru sampai UTJ, penutupan Gugur dengan alasan Pengaturan, riwayat perubahan teraudit, dan kompatibilitas status Lead lama serta spreadsheet. Fitur digerakkan oleh Pengaturan lead_stages_v3 dan belum mengubah cutover produksi.',
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
