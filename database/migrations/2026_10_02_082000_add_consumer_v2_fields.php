<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHANGELOG_TITLE = 'Pondasi Proses Penjualan V2';

    public function up(): void
    {
        Schema::table('consumer_applications', function (Blueprint $table): void {
            $table->string('transaction_status', 30)->nullable()->after('consumer_status');
            $table->string('payment_method', 30)->nullable()->after('status_cash');
            $table->string('current_process', 40)->nullable()->after('current_stage');
            $table->string('current_process_source', 40)->nullable()->after('current_process');
            $table->string('entry_mode', 30)->nullable()->after('current_process_source');
            $table->string('acquisition_source', 40)->nullable()->after('entry_mode');
            $table->dateTime('historical_entered_at')->nullable()->after('entry_mode');
            $table->foreignId('historical_entered_by')->nullable()->after('historical_entered_at')->constrained('users')->nullOnDelete();
            $table->foreignId('source_nup_id')->nullable()->after('sales_lead_id')->constrained('consumer_nups')->nullOnDelete();

            $table->index(['branch_id', 'current_process']);
            $table->index(['branch_id', 'transaction_status']);
            $table->index(['payment_method', 'current_process']);
        });

        DB::table('consumer_applications')
            ->whereNull('current_process')
            ->whereNotNull('current_stage')
            ->update(['current_process' => DB::raw("CASE lower(current_stage) WHEN 'bi_checking' THEN 'slik' WHEN 'psjb' THEN 'psjb' WHEN 'pemberkasan' THEN 'pemberkasan' WHEN 'proses_bank' THEN 'proses_bank' WHEN 'ppjb_dev' THEN 'ppjb' WHEN 'akad' THEN 'akad' WHEN 'bast' THEN 'bast' ELSE current_stage END"), 'current_process_source' => 'legacy_compatibility']);

        DB::table('consumer_applications')
            ->where('status_cash', true)
            ->whereNull('payment_method')
            ->update(['payment_method' => 'cash']);

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::CHANGELOG_TITLE],
            [
                'description' => 'Menambahkan pondasi transaksi Proses Penjualan V2 untuk mode pembayaran, posisi proses, entry historis, dan sumber NUP tanpa menghapus struktur legacy.',
                'category' => 'added',
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
            $table->dropForeign(['historical_entered_by']);
            $table->dropForeign(['source_nup_id']);
            $table->dropIndex(['branch_id', 'current_process']);
            $table->dropIndex(['branch_id', 'transaction_status']);
            $table->dropIndex(['payment_method', 'current_process']);
            $table->dropColumn([
                'transaction_status', 'payment_method', 'current_process', 'current_process_source',
                'entry_mode', 'acquisition_source', 'historical_entered_at', 'historical_entered_by', 'source_nup_id',
            ]);
        });
    }
};
