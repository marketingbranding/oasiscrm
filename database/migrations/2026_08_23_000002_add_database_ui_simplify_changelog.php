<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Tampilan Database Lebih Sederhana dan Ringkas'],
            [
                'description' => 'Kolom tabel dan form kini menyusun field penting per modul dengan label yang lebih mudah dibaca. Kolom teknis dan formula disembunyikan dari form. Nilai uang dan tanggal ditampilkan dalam format Indonesia.',
                'category' => 'changed',
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
            ->where('title', 'Tampilan Database Lebih Sederhana dan Ringkas')
            ->delete();
    }
};
