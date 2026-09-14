<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Pengaturan Proyek dan Akses Lebih Aman';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'description' => 'Perubahan nama proyek kini mempertahankan identitas spreadsheet, pemindahan cabang diblokir bila masih dipakai, penghapusan proyek menjadi nonaktifkan, dan akses berbasis penugasan proyek diperketat di Dana Talangan, Pengeluaran, serta Work Planner.',
                'category' => 'changed',
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
