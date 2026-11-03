<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const TITLE = 'Shell Agenda Buku Saku menyatukan status dan tampilan';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Header Buku Saku dan status agenda kini tetap konsisten pada tampilan draft, terkirim, dan rencana akhir pekan.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')
            ->whereNull('version')
            ->where('title', self::TITLE)
            ->delete();
    }
};
