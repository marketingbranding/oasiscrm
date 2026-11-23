<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Melengkapi penguncian input Workspace Konsumen per status cabang';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Seluruh input proses, event, lifecycle, garansi, dan kendala Workspace Konsumen kini mengikuti status migrasi cabang. Ditambahkan pemeriksaan read-only untuk menemukan input Workspace manual sebelum deploy.',
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
