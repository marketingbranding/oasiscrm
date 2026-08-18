<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Impor Proses Histori Database Master 2026';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'added',
                'description' => 'Penambahan impor proses histori berbasis tempel TSV untuk Super Admin sesuai header kanonik Database Master 2026 (BI Checking, PSJB, Pemberkasan, Proses Bank, PPJB Dev, Akad, BAST). Impor mengikuti rantai ID kanonik id_kons, id_psjb, id_berkas, no_sp3k, id_ppjb_dev, no_ppjb_akad, dan no_bast; data tahap Akad dan BAST dikenali sebagai calon kavling terjual pada preview backfill.',
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
