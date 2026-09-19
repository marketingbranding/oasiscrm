<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TITLE = 'Perbaikan nullable aplikasi konsumen migrasi';

    public function up(): void
    {
        Schema::table('consumer_applications', function (Blueprint $table): void {
            $table->unsignedBigInteger('customer_id')->nullable()->change();
            $table->unsignedBigInteger('project_id')->nullable()->change();
        });

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'fixed',
                'description' => 'Kolom Customer dan proyek pada aplikasi konsumen kembali mendukung nilai kosong sesuai alur impor mentah dan rekonsiliasi Marison V2, tanpa mengubah relasi foreign key yang ada.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')
            ->whereNull('version')
            ->where('title', self::TITLE)
            ->delete();
    }
};
