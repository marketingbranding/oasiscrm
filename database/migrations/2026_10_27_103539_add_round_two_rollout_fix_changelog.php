<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Penutupan bridge dan rekonsiliasi dorman'],
            [
                'category' => 'fixed',
                'description' => 'Bridge spreadsheet Lead Sales dan seluruh kontrol rekonsiliasi kini berhenti saat rollout sinkronisasi spreadsheet nonaktif.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', 'Penutupan bridge dan rekonsiliasi dorman')->delete();
    }
};
