<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const VERSION = '1.1.0';

    private const TITLE = 'Peningkatan Sinkronisasi Data CRM';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            [
                'version' => self::VERSION,
                'title' => self::TITLE,
            ],
            [
                'category' => 'fixed',
                'description' => implode(PHP_EOL, [
                    'Status sinkronisasi kini diperbarui secara langsung pada panel utama tanpa perlu memuat ulang halaman.',
                    'Indikator sinkronisasi tampil ringkas di dekat kursor dan tetap menjaga akses ke tabel selama proses berjalan.',
                    'Database otomatis memuat ulang sheet yang sedang aktif setelah sinkronisasi berhasil, tanpa mengubah cabang, tab, pencarian, atau posisi kerja pengguna.',
                    'Pemilihan cabang untuk pengguna Pusat dan Superadmin telah diperbaiki agar sinkronisasi selalu menggunakan cabang yang dipilih.',
                    'Draf edit tetap dilindungi saat data baru tersedia, dengan pilihan untuk memuat ulang data atau mempertahankan draf.',
                ]),
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')
            ->where('version', self::VERSION)
            ->where('title', self::TITLE)
            ->delete();
    }
};
