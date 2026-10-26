<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const TITLE = 'Kertas Agenda Harian Menjadi Alur Tunggal dengan Bukti Foto';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Agenda Sales kini dibuat dalam satu Kertas Agenda Harian dengan target tepat 420 menit, hasil aktivitas, dan maksimal dua bukti foto pada setiap item setelah submit.',
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
