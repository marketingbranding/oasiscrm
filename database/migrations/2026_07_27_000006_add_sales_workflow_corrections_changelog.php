<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Penyempurnaan Alur Buku Saku Sales';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Sumber lead kini seragam, filter monitoring mengikuti urutan cabang, proyek, dan sales, serta pilihan periode hanya tampil ketika panelnya dibuka. Pengisian progres dan agenda memakai pemilih tanggal dan jam Oasis yang konsisten, dengan roda pemilih jam yang tetap stabil saat dibuka dan digulir. Durasi agenda dihitung otomatis dari jam mulai dan selesai, sedangkan hasil penyimpanan dan peringatan kini tampil melalui notifikasi toast yang konsisten.',
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
