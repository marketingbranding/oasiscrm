<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Onboarding Langsung dan Pemindahan Unit Organisasi'],
            [
                'description' => 'Pembuatan pengguna kini langsung aktif dengan password awal dari admin, dan pengguna dapat dipindahkan ke Pusat atau Cabang melalui Workspace Organisasi.',
                'category' => 'changed',
                'created_by' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('changelogs')
            ->whereNull('version')
            ->where('title', 'Onboarding Langsung dan Pemindahan Unit Organisasi')
            ->delete();
    }
};
