<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Pipeline CSS Migrasi ke Tailwind v4'],
            [
                'description' => 'Pipeline CSS OASIS diperbarui ke Tailwind CSS v4 dengan PostCSS plugin resmi dan dependency development yang lebih bersih.',
                'category' => 'changed',
                'created_by' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('changelogs')
            ->whereNull('version')
            ->where('title', 'Pipeline CSS Migrasi ke Tailwind v4')
            ->delete();
    }
};
