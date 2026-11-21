<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Privasi AI dan backup database WP-1.9'],
            [
                'description' => 'Data pribadi disaring sebelum dikirim ke penyedia AI; backup database terkompresi dijadwalkan dan disimpan sesuai retensi Pengaturan.',
                'category' => 'added',
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
            ->where('title', 'Privasi AI dan backup database WP-1.9')
            ->delete();
    }
};
