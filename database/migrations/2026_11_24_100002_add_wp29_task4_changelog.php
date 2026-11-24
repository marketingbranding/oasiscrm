<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TITLE = 'Penautan Lead konsumen tersedia di ruang kerja';

    public function up(): void
    {
        if (Schema::hasTable('changelogs')) {
            DB::table('changelogs')->updateOrInsert(
                ['version' => null, 'title' => self::TITLE],
                [
                    'category' => 'added',
                    'description' => 'Ruang kerja Konsumen menyediakan pilihan Lead, Tanpa Lead, dan Data Lama dengan validasi, batas akses, serta riwayat koreksi.',
                    'created_by' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('changelogs')) {
            DB::table('changelogs')
                ->whereNull('version')
                ->where('title', self::TITLE)
                ->delete();
        }
    }
};
