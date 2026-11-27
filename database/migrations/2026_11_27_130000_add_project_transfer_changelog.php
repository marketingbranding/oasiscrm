<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Pemindahan Proyek Antar Cabang Lebih Aman';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Pemindahan proyek antar cabang kini memakai kepemilikan Sheet berdasarkan riwayat transfer aktif, memetakan seluruh pengguna aktif cabang, menjaga pemeliharaan modul terkait, dan memeriksa ulang data turunan sebelum transaksi selesai.',
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
