<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Akses Work Planner Tim Pusat diperbaiki';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'fixed',
                'description' => 'Tim Pusat kini dapat membuat, memperbarui, dan menugaskan item Work Planner pada seluruh cabang aktif sesuai izin lingkup semua. Pesan penolakan cabang dan penugasan juga dibuat lebih jelas.',
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
