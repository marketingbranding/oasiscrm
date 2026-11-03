<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Agenda Sales Menyediakan Kamera dan Upload Foto'],
            [
                'category' => 'changed',
                'description' => 'Bukti foto Agenda Sales kini dapat ditambahkan melalui kamera perangkat atau foto yang sudah tersimpan.',
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
            ->where('title', 'Agenda Sales Menyediakan Kamera dan Upload Foto')
            ->delete();
    }
};
