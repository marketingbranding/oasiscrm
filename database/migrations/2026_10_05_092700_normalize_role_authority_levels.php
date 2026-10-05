<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->where('authority_level', '<', 1)->update(['authority_level' => 1]);
        DB::table('roles')->where('authority_level', '>', 100)->update(['authority_level' => 100]);

        if (DB::getSchemaBuilder()->hasTable('changelogs')) {
            DB::table('changelogs')->updateOrInsert(
                ['version' => null, 'title' => 'Role Fleksibel Berbasis Tingkat Kewenangan'],
                [
                    'description' => 'Nama role dapat diubah dan hubungan organisasi divalidasi menggunakan tingkat kewenangan 1 sampai 100.',
                    'category' => 'changed',
                    'created_by' => null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        if (DB::getSchemaBuilder()->hasTable('changelogs')) {
            DB::table('changelogs')->whereNull('version')->where('title', 'Role Fleksibel Berbasis Tingkat Kewenangan')->delete();
        }
    }
};
