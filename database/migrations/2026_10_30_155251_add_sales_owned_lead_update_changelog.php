<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Sales Dapat Memperbarui Lead Miliknya'],
            [
                'category' => 'changed',
                'description' => 'Sales kini dapat menemukan, membuka, dan memperbarui lead miliknya dari Buku Saku Sales.',
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
            ->where('title', 'Sales Dapat Memperbarui Lead Miliknya')
            ->delete();
    }
};
