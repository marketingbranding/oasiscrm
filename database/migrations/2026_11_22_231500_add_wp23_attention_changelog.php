<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Penanda perhatian Lead dan Rencana Harian';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'added',
                'description' => 'Menambahkan penanda perhatian berbasis aktivitas Lead, filter dan ringkasan sesuai scope akses, penanda Rencana Harian belum dikirim, serta ringkasan notifikasi in-app harian untuk Koordinator pada hari kerja.',
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
