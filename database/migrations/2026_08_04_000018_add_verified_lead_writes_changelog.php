<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Penulisan Lead Mengikuti Pilihan Spreadsheet Cabang';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'fixed',
                'description' => 'Input dan edit lead kini memakai header, pilihan valid, identitas proyek, identitas Sales PIC, dan UUID operasi sesuai spreadsheet cabang sebelum data dikirim.',
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
