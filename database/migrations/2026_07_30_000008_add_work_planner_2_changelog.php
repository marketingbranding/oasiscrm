<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Work Planner menjadi ruang kerja operasional';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Tampilan Work Planner kini lebih terarah untuk task, agenda, konten, filter, detail, dan aksi harian dengan komponen OASIS Design System tanpa mengubah aturan akses, status, penugasan, import, export, komentar, presence, atau konflik kolaborasi.',
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
