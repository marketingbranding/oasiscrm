<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('changelogs')) {
            return;
        }

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Backfill Dana Talangan dapat ditinjau dan diaudit'],
            [
                'description' => 'Backfill Dana Talangan mendukung konfirmasi kandidat, normalisasi kode kavling, dan audit per baris tanpa menebak kecocokan yang meragukan.',
                'category' => 'changed',
                'created_by' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('changelogs')) {
            return;
        }

        DB::table('changelogs')
            ->whereNull('version')
            ->where('title', 'Backfill Dana Talangan dapat ditinjau dan diaudit')
            ->delete();
    }
};
