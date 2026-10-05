<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Pemisahan Garis Hierarchy dan Keanggotaan Unit'],
            [
                'description' => 'Diagram organisasi memisahkan garis atasan-bawahan dari relasi keanggotaan Pusat/Cabang. Counter unit kini menampilkan jumlah members dan direct reports secara terpisah.',
                'category' => 'changed',
                'created_by' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('changelogs')
            ->whereNull('version')
            ->where('title', 'Pemisahan Garis Hierarchy dan Keanggotaan Unit')
            ->delete();
    }
};
