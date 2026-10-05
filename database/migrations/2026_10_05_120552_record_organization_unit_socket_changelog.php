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
            ['version' => null, 'title' => 'Socket Unit pada Diagram Organisasi'],
            [
                'description' => 'Diagram hierarchy memiliki socket unit berwarna untuk memindahkan pengguna ke Pusat atau Cabang tanpa mencampurnya dengan socket garis pelaporan.',
                'category' => 'added',
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
            ->where('title', 'Socket Unit pada Diagram Organisasi')
            ->delete();
    }
};
