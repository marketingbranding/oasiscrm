<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHANGELOG_TITLE = 'Status transaksi kanonis dan alasan Mundur';

    public function up(): void
    {
        Schema::table('consumer_applications', function (Blueprint $table): void {
            $table->string('closed_by_event', 40)->nullable()->after('transaction_status');
            $table->string('withdraw_reason', 255)->nullable()->after('closed_by_event');
        });

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::CHANGELOG_TITLE],
            [
                'category' => 'added',
                'description' => 'Menambahkan status transaksi kanonis, penanda event penutupan, dan alasan Mundur yang dapat diatur tanpa menghapus kolom legacy.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', self::CHANGELOG_TITLE)->delete();

        Schema::table('consumer_applications', function (Blueprint $table): void {
            $table->dropColumn(['closed_by_event', 'withdraw_reason']);
        });
    }
};
