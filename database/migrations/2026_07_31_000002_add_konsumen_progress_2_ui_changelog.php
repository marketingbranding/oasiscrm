<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Tampilan Konsumen Progress Diperbarui';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Halaman Konsumen Progress kini memakai pola tampilan OASIS yang lebih rapi untuk pemilihan cabang, status sinkronisasi, tab tahap, dan daftar konsumen tanpa mengubah data, tahap, atau alur sinkronisasi.',
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
