<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Penutupan Handoff Operasional Marison V2';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            ['category' => 'fixed', 'description' => 'Aplikasi hasil rekonsiliasi Marison V2 kini dapat melanjutkan input proses OASIS normal dari BI Checking sampai BAST tanpa membuat ulang histori impor.', 'created_by' => null, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', self::TITLE)->delete();
    }
};
