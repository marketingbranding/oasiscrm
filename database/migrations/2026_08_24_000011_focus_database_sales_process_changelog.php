<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Database Kini Berfokus pada Proses Penjualan';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'description' => 'Database kini tampil sebagai ruang kerja tersendiri yang berfokus pada delapan tahap Proses Penjualan, sementara data sheet lain tetap tersinkron di cache tanpa ditampilkan.',
                'category' => 'changed',
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
