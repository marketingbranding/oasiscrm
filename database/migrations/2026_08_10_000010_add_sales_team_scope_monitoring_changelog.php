<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Sales Team Scope & Monitoring';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Struktur tim Buku Saku diselaraskan sehingga Manager Cabang melihat SPV, Koordinator, dan Sales timnya; SPV melihat Koordinator dan Sales; Koordinator melihat Sales yang ditugaskan; sedangkan Sales tetap hanya melihat data sendiri. Tampilan monitoring dan komponen tanggal juga diseragamkan.',
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
