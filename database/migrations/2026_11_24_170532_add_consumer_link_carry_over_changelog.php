<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Carry-over keputusan penautan Lead pada jalur pembuatan konsumen'],
            [
                'description' => 'Keputusan penautan Lead kini dibawa saat konsumen dibuat melalui form, paste import, impor historis, rekonsiliasi migrasi, atau konversi NUP.',
                'category' => 'fixed',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', 'Carry-over keputusan penautan Lead pada jalur pembuatan konsumen')->delete();
    }
};
