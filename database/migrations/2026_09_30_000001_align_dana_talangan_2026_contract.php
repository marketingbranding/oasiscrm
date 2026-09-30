<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TITLE = 'Dana Talangan terhubung dengan tab 2026';

    public function up(): void
    {
        if (! Schema::hasColumn('dana_talangans', 'nominal')) {
            Schema::table('dana_talangans', function (Blueprint $table): void {
                $table->decimal('nominal', 15, 2)->nullable()->after('tgl_komitmen');
            });
        }

        if (Schema::hasTable('changelogs')) {
            DB::table('changelogs')->updateOrInsert(
                ['version' => null, 'title' => self::TITLE],
                [
                    'category' => 'changed',
                    'description' => 'Dana Talangan kini menggunakan tab 2026 sebagai sumber spreadsheet, mempertahankan metadata OASIS tersembunyi, dan membawa identitas Cabang untuk sinkronisasi dua arah.',
                    'created_by' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('changelogs')) {
            DB::table('changelogs')->whereNull('version')->where('title', self::TITLE)->delete();
        }

        if (Schema::hasColumn('dana_talangans', 'nominal')) {
            Schema::table('dana_talangans', function (Blueprint $table): void {
                $table->dropColumn('nominal');
            });
        }
    }
};
