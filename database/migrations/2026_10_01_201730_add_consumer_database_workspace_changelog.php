<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Workspace Database Konsumen Baru Tersedia'],
            [
                'description' => 'Workspace Database Konsumen kini menampilkan data konsumen lokal dengan filter cabang, proyek, Sales, bank, pencarian, pagination, linimasa proses, dan detail baca-saja yang tetap mengikuti lingkup akses organisasi.',
                'category' => 'added',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', 'Workspace Database Konsumen Baru Tersedia')->delete();
    }
};
