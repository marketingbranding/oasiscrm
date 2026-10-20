<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Migrasi Tailwind CSS 4 dan Keamanan Frontend';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'OASIS kini menggunakan Tailwind CSS 4 melalui plugin Vite resmi dan dependensi frontend yang diperbarui untuk menghapus kerentanan npm tanpa mengubah alur CRM.',
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
