<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Peran Organisasi dan Izin Akses';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'added',
                'description' => 'OASIS kini memiliki peran organisasi dan izin akses yang lebih terstruktur untuk membatasi data sesuai tanggung jawab pengguna, tim, penugasan, cabang, dan Tim Pusat.',
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
