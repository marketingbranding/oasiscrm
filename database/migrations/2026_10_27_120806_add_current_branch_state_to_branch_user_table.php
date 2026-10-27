<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHANGELOG_TITLE = 'Akses historis cabang setelah perpindahan pengguna';

    public function up(): void
    {
        Schema::table('branch_user', function (Blueprint $table): void {
            $table->boolean('is_current')->default(true)->after('branch_id');
            $table->index(['user_id', 'is_current']);
            $table->index(['branch_id', 'is_current']);
        });

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::CHANGELOG_TITLE],
            [
                'category' => 'changed',
                'description' => 'Perpindahan pengguna menyimpan keanggotaan cabang lama sebagai akses historis baca-saja tanpa memindahkan kepemilikan data.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', self::CHANGELOG_TITLE)->delete();

        Schema::table('branch_user', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'is_current']);
            $table->dropIndex(['branch_id', 'is_current']);
            $table->dropColumn('is_current');
        });
    }
};
