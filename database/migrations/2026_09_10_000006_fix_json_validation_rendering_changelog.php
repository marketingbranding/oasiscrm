<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Respons JSON untuk Error Validasi Permintaan AJAX';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'fixed',
                'description' => 'Permintaan yang meminta respons JSON (misalnya simpan lead dari modal) kini menerima error validasi sebagai JSON 422 yang dapat ditampilkan langsung, bukan pengalihan halaman. Formulir web biasa tetap menampilkan error validasi secara manual di halaman.',
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
