<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Pencatatan Pengeluaran Manual';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'added',
                'description' => 'Superadmin dan tim pusat kini dapat mencatat, melihat, memperbarui, dan membatalkan pengeluaran per cabang serta proyek. Riwayat pembatalan tetap tersimpan agar pencatatan keuangan mudah ditelusuri.',
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
