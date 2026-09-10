<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Tombol Cepat Status Lead dan Tandai UTJ';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'added',
                'description' => 'Status lead kini dapat diperbarui cepat (No Respon, Diskusi, Tatap Muka, Cek Lokasi) dengan modal konfirmasi berisi tanggal tercatat otomatis, dan tersedia aksi Tandai UTJ langsung tanpa mencatat data cek lokasi atau konsumen.',
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
