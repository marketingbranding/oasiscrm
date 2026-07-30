<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Buku Saku Sales menjadi ruang kerja harian';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Lead, agenda, laporan, pengingat, dan input harian kini lebih terarah, responsif, dan ramah keyboard tanpa mengubah alur kerja, scope, atau perhitungan Sales.',
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
