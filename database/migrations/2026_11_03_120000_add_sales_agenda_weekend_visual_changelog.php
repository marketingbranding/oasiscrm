<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Agenda Akhir Pekan Lebih Mudah Dibedakan';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Tanggal dan ringkasan agenda akhir pekan kini memiliki warna latar lembut serta aksen diagonal agar mudah dibedakan dari hari kerja.',
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
