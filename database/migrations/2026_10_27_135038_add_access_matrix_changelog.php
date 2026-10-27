<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Matriks Akses Operasional OASIS';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Matriks akses peran aktif Sales, Koordinator, Supervisor, Admin, dan Branch Manager kini mengikuti modul serta lingkup KC/KCP; akses historis tetap baca-saja dan onboarding Sales memerlukan persetujuan Superadmin. Database, Konsumen Progress, dan sinkronisasi spreadsheet tetap dorman, sementara Manager dan Tim Pusat tetap ditunda.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('changelogs')
            ->whereNull('version')
            ->where('title', self::TITLE)
            ->delete();
    }
};
