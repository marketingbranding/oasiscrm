<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Akses modul berdasarkan izin pengguna';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'description' => 'Menu dan tindakan pada setiap modul kini mengikuti izin peran pengguna secara konsisten, dengan batas cabang, proyek, dan data Sales tetap terlindungi.',
                'category' => 'changed', 'created_by' => null, 'updated_at' => now(), 'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', self::TITLE)->delete();
    }
};
