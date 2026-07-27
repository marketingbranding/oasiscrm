<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Pengingat Harian Buku Saku Sales';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'added',
                'description' => 'Sales kini menerima pengingat harian untuk mencatat lead, menyusun agenda, dan melengkapi hasil agenda. Pengingat dapat disembunyikan untuk hari berjalan dan akan aktif kembali otomatis pada hari berikutnya sesuai waktu Oasis.',
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
