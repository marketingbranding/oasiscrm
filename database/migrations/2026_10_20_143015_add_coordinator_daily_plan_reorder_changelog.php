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
            ['version' => null, 'title' => 'Koreksi Urutan Daily Plan oleh Coordinator'],
            [
                'category' => 'fixed',
                'description' => 'Coordinator dapat mengubah urutan Agenda pada Daily Plan Team yang sudah dikirim tanpa mengubah total 420 menit.',
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
        DB::table('changelogs')->whereNull('version')->where('title', 'Koreksi Urutan Daily Plan oleh Coordinator')->delete();
    }
};
