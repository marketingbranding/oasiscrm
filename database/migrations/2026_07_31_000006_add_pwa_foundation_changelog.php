<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'OASIS Dapat Dipasang sebagai Aplikasi Android';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'added',
                'description' => 'OASIS kini dapat dipasang sebagai aplikasi Android melalui browser (PWA). Halaman luring yang aman dan pembaruan versi otomatis tersedia tanpa menyimpan data CRM saat offline.',
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
