<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private string $title = 'Perbaikan tampilan form lead dan aksi agenda di mobile';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => $this->title],
            [
                'description' => 'Form tambah lead kini tetap nyaman digunakan saat bagian aktivitas dan status dibuka. Tombol Edit dan Hapus agenda juga tampil utuh pada layar ponsel.',
                'category' => 'changed',
                'created_by' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', $this->title)->delete();
    }
};
