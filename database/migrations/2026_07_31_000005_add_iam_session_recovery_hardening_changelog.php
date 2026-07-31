<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Pengamanan Sesi dan Pemulihan Akun Diperkuat';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Penangguhan, penonaktifan, dan anonimisasi akun kini mencabut sesi aktif, token ingat, token reset kata sandi, dan undangan yang tertunda. Akun terakhir yang dapat mengelola maintenance, melewati maintenance, atau mengelola pengguna tidak dapat dinonaktifkan.',
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
