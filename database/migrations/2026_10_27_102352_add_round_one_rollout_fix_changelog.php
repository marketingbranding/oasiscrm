<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Pengamanan gate rollout dan sinkronisasi OASIS'],
            [
                'category' => 'fixed',
                'description' => 'Konversi konsumen, penulisan spreadsheet, dan seluruh permukaan status sinkronisasi kini menghormati gate rollout yang sedang nonaktif.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', 'Pengamanan gate rollout dan sinkronisasi OASIS')->delete();
    }
};
