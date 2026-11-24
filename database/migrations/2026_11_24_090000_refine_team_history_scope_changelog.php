<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Riwayat Tim membaca perubahan Lead dan agenda berdasarkan graf organisasi';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            ['description' => 'Menambahkan halaman baca-saja Riwayat Tim dengan filter bawahan langsung, rentang tanggal maksimal 92 hari, jenis perubahan, ringkasan turunan jauh, serta pembatasan scope cabang/proyek sesuai cakupan pemantau.', 'category' => 'changed', 'created_by' => null, 'updated_at' => now()],
        );
    }

    public function down(): void
    {
        // Keep original feature changelog when refinement migration is rolled back.
    }
};
