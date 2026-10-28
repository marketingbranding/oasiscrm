<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Editor Graph Organisasi Drag-and-Drop';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'added',
                'description' => 'Workspace organisasi kini menampilkan Pusat dan cabang dalam graph yang dapat mengubah atasan atau unit melalui drag-and-drop, dengan socket, modal keyboard, dan pemulihan konflik tetap tersedia.',
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
