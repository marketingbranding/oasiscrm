<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Buku Saku Sales memiliki alur mobile dan panduan onboarding';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'description' => 'Agenda dan Lead Saya disusun untuk alur kerja mobile Sales, dengan panduan onboarding yang dapat dilewati dan diulang tanpa mengubah data operasional.',
                'category' => 'added',
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
