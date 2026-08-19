<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Database V2: Entry Data Manual'],
            [
                'description' => 'Modul baru Database V2 untuk entry data konsumen langsung ke database OASIS. Terdapat 8 modul: Data Konsumen, BI Checking, PSJB, Pemberkasan, Proses Bank, PPJB Developer, Akad, dan BAST. Mendukung tambah, edit, arsip, import copas, dan export Excel per modul.',
                'category' => 'added',
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
            ->where('title', 'Database V2: Entry Data Manual')
            ->delete();
    }
};
