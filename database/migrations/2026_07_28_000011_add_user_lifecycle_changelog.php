<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Aktivasi Akun melalui Undangan';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Pendaftaran umum ditutup dan akun baru kini dapat diaktifkan secara aman melalui tautan undangan OASIS. Status akun, riwayat masuk, dan perubahan kata sandi juga tercatat lebih jelas untuk menjaga akses pengguna.',
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
