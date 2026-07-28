<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Komentar terpadu pada data OASIS';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'added',
                'description' => 'OASIS menyiapkan komentar terpadu untuk lead sales, agenda Buku Saku Sales, Work Planner, Pengeluaran, dan Dana Talangan agar diskusi tim tetap terhubung dengan data yang dibahas.',
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
