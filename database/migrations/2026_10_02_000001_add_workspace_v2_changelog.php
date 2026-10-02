<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Workspace V2 tersedia untuk UAT';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'added',
                'description' => 'Workspace V2 tersedia berdampingan melalui /workspace dengan process view transaksi konsumen yang mengikuti struktur spreadsheet. Fitur tetap opt-in melalui OASIS_NEW_WORKSPACE_ENABLED.',
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
