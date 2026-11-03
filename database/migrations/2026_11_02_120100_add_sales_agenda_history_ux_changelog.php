<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Perbaikan draft, durasi custom, dan riwayat Agenda Sales';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Preview struk Agenda Sales kini dapat disimpan sebagai draft tanpa mengirim agenda, durasi custom seperti 180 menit dihitung dengan benar, dan Sales dapat membuka riwayat agenda pribadinya lintas tanggal dengan jumlah agenda yang belum selesai.',
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
