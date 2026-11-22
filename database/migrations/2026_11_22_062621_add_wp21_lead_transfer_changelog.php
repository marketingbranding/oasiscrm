<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Alih Lead dan antrean Lead tanpa pemilik aktif'],
            [
                'description' => 'Koordinator dapat mengalihkan Lead secara atomik dalam tim aktif. Lead aktif milik Sales yang dinonaktifkan masuk antrean tanpa pemilik aktif.',
                'category' => 'changed',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', 'Alih Lead dan antrean Lead tanpa pemilik aktif')->delete();
    }
};
