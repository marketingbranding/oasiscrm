<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Parser import konsumen lebih aman';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            ['category' => 'fixed', 'description' => 'Import paste konsumen menangani TSV spreadsheet dengan kolom kosong, kutip, dan baris baru secara aman serta memisahkan validitas import dari kelengkapan data.', 'created_by' => null, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', self::TITLE)->delete();
    }
};
