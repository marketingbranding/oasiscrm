<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Izin Akses Khusus untuk Lead dan Agenda Sales';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'added',
                'description' => 'Koordinator dapat menerima izin akses khusus yang dibatasi subjek, turunan organisasi, cabang/proyek workspace, masa berlaku, dan hak edit untuk Lead Sales serta agenda harian. Export dan Asisten AI mengikuti scope grant, termasuk pencabutan akses dan penyaringan data pribadi.',
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
            ->where('title', self::TITLE)
            ->delete();
    }
};
