<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Status transaksi legacy lebih aman dibaca dan diaudit';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'description' => 'Status transaksi legacy selesai dipetakan ke LANJUT, status tidak dikenal tidak memutus halaman, dan audit holder kavling tersedia sebelum migrasi constraint.',
                'category' => 'fixed',
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
