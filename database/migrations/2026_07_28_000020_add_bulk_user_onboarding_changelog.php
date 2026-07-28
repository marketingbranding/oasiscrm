<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Persiapan impor pengguna secara massal';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'added',
                'description' => 'OASIS menyediakan template Excel berisi referensi peran, cabang, proyek, dan status untuk menyiapkan banyak pengguna sekaligus. Riwayat impor dan tahapan validasi juga disiapkan agar data dapat diperiksa sebelum akun serta undangan dibuat.',
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
