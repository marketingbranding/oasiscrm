<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Semantika Relasi Organisasi Reporting dan Sales Team';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Memisahkan relasi supervisor sebagai reporting formal dari relasi Sales Coordinator sebagai keanggotaan operasional Sales-team dengan edge canonical bertipe, tanpa mengubah graph_mode legacy atau menjalankan cutover.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')
            ->whereNull('version')
            ->where('title', self::TITLE)
            ->delete();
    }
};
