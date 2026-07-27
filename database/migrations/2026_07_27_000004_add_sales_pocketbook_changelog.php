<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Buku Saku Sales Terpadu';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'added',
                'description' => 'Buku Saku Sales kini mencakup penugasan sales per proyek, pencatatan lead dan progres pribadi, agenda beserta hasil aktivitas, monitoring mingguan, ringkasan di dashboard, serta export Excel sesuai filter dan hak akses pengguna.',
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
