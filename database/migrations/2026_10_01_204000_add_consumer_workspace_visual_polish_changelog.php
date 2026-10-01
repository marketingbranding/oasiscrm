<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Penyempurnaan Tampilan Workspace Database Konsumen'],
            [
                'description' => 'Menyempurnakan tampilan responsif, konteks bank, pesan keadaan kosong, istilah proses, dan pesan akses pada Workspace Database Konsumen.',
                'category' => 'fixed',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', 'Penyempurnaan Tampilan Workspace Database Konsumen')->delete();
    }
};
