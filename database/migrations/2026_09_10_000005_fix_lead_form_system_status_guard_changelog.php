<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Formulir Lead Menolak Status Sistem';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'fixed',
                'description' => 'Formulir buat dan ubah lead kini hanya menerima status manual (No Respon, Diskusi, Tatap Muka, Cek Lokasi). Status sistem seperti UTJ dan Cek SLIK bersifat baca-saja pada formulir dan hanya berubah melalui aksi siklus lead yang sesuai.',
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
