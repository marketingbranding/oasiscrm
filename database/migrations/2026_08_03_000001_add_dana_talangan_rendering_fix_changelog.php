<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Tampilan Dana Talangan Kembali Normal';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'fixed',
                'description' => 'Memperbaiki tampilan halaman Dana Talangan agar kontrol, tabel, dan modal kembali ditampilkan secara normal.',
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
