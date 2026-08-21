<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Database konsumen baru tersedia'],
            ['description' => 'Menambahkan tampilan baca-saja data konsumen dan proses penjualan lokal dengan lingkup cabang serta proyek yang aman.', 'category' => 'added', 'created_by' => null, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', 'Database konsumen baru tersedia')->delete();
    }
};
