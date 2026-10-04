<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Penyederhanaan Halaman Peran dan Izin';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'description' => 'Halaman Peran dan Aturan Organisasi kini menampilkan nama dan penjelasan izin dalam bahasa pengguna, menyembunyikan kode teknis secara default, serta menjelaskan hubungan atasan dan bawahan dengan lebih jelas.',
                'category' => 'changed',
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
