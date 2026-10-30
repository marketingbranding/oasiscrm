<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Pesan Validasi Agenda Lebih Jelas'],
            [
                'category' => 'changed',
                'description' => 'Validasi formulir Agenda Buku Saku Sales kini menampilkan panduan berbahasa Indonesia yang lebih jelas.',
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
            ->where('title', 'Pesan Validasi Agenda Lebih Jelas')
            ->delete();
    }
};
