<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Workspace Koordinator Sales Memprioritaskan Antrean Tindakan';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Workspace Koordinator Sales kini mengutamakan antrean tindakan, merangkum lead yang perlu ditindaklanjuti, dan menyediakan tampilan agenda yang lebih mudah dipindai dari ponsel.',
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
