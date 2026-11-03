<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const TITLE = 'Detail Agenda Sales menyederhanakan bukti foto mobile';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Detail agenda kini menampilkan satu preview foto dan satu slot tambah foto yang lebih ringkas pada layar mobile.',
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
