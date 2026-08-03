<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Siklus Lead Buku Saku Terhubung';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Form lead kini menulis dua arah ke spreadsheet cabang dengan identitas sinkron stabil, sementara kartu lead menyediakan status siklus, cek lokasi, konsumen atau NUP, SLIK, freelance, dan rekonsiliasi sesuai akses.',
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
