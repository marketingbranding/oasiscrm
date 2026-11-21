<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Isolasi Pemantauan Koordinator Sales';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'fixed',
                'description' => 'Saat pemantauan organisasi diaktifkan, pilihan Sales pada autocomplete dan akses pemantauan Koordinator dibatasi ke tim Sales yang sedang ditugaskan. URL langsung, ekspor, komentar, dan data JSON tetap memeriksa cakupan tim.',
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
