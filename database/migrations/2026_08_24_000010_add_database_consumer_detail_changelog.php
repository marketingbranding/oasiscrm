<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Detail Konsumen Database Kini Tersedia';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'description' => 'Nama konsumen di delapan tahap Database kini membuka profil utama dan riwayat proses melalui rantai ID kanonis yang akurat dalam panel samping, dengan NIK tetap disamarkan.',
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
