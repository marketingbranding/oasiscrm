<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Batas Baca Saja Pemantauan Sales';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Saat pemantauan organisasi diaktifkan, Supervisor, Manager, Ass. Manager, dan Admin Cabang tidak dapat mengubah Lead tim Sales. Koordinator tetap dapat mengelola timnya, sedangkan hak edit Lead historis milik Admin Cabang dan Branch Manager tetap berlaku.',
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
