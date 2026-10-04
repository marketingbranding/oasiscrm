<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Penyempurnaan Workspace Organisasi';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'description' => 'Workspace organisasi kini memiliki kanvas yang dapat digeser dan diperbesar, konektor pelaporan yang jelas, tindakan node kontekstual, serta penugasan cabang dan proyek berbasis pencarian dengan panduan sesuai peran.',
                'category' => 'changed',
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
