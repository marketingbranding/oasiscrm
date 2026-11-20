<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Ringkasan Pemantauan Sales Berjenjang';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Pemantauan Lead dan agenda dari turunan Sales yang lebih jauh kini menampilkan ringkasan terbatas tanpa nomor telepon, catatan, isi kegiatan, lokasi, hasil aktivitas, atau foto bukti. Bawahan langsung tetap melihat detail sesuai hak akses.',
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
