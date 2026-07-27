<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Akses Sales Lebih Terarah';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Pengguna Sales kini langsung masuk ke Buku Saku Sales dan hanya dapat membuka Buku Saku Sales serta Work Planner. Menu dan akses modul lain disembunyikan sekaligus dilindungi agar aktivitas harian sales lebih fokus dan data lintas modul tetap aman.',
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
