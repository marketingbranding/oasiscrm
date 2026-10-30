<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Persiapan Cutover Terkendali Graph Organisasi';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Menambahkan resolver mode legacy, shadow, dan graph yang fail-closed, backfill terisolasi dengan guard eksplisit, diagnostik shadow, dan health gate cutover tanpa mengubah graph_mode=legacy atau menjalankan cutover produksi.',
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
