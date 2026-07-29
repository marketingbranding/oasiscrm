<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Fondasi antarmuka OASIS diperbarui';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Tampilan dasar tombol, status, pesan, formulir, dialog, dan Dashboard kini lebih konsisten dan responsif. Dialog baru mendukung navigasi keyboard, dan panduan desain internal tersedia untuk pengembangan berikutnya.',
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
