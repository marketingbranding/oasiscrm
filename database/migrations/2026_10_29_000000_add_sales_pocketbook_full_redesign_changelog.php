<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Buku Saku Sales memiliki tampilan operasional terpadu';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'description' => 'Workspace Agenda, Lead, detail aktivitas, dan monitoring Buku Saku Sales kini menggunakan komposisi operasional terpadu yang lebih mudah dipindai di desktop maupun ponsel.',
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
