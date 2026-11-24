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
            [
                'description' => 'Menambahkan halaman baca-saja Riwayat Tim dengan filter bawahan langsung, rentang tanggal, jenis perubahan, ringkasan turunan jauh, dan pembatasan scope cabang/proyek.',
                'category' => 'added',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', self::TITLE)->delete();
    }
};
