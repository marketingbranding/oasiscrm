<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Perbaikan Akses Historis Buku Saku Sales';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'fixed',
                'description' => 'Supervisor kini hanya dapat membuka lead milik timnya, termasuk riwayat assignment yang relevan untuk monitoring dan ekspor. Edit lead historis oleh Admin dan Branch Manager tetap mempertahankan Sales pemilik serta tidak membuka mutasi lifecycle.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
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
            ->where('title', self::TITLE)
            ->delete();
    }
};
