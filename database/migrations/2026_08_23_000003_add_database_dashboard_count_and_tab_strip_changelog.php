<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Dashboard Database Menampilkan Jumlah Data dan Tab Lebih Mudah Digeser'],
            [
                'description' => 'Kartu dashboard Database kini menampilkan jumlah baris aktual dari cache spreadsheet per modul. Tab sheet dapat digeser horizontal dengan drag mouse dan roda gulir.',
                'category' => 'fixed',
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
            ->where('title', 'Dashboard Database Menampilkan Jumlah Data dan Tab Lebih Mudah Digeser')
            ->delete();
    }
};
