<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHANGELOG_TITLE = 'Retensi Bukti Agenda Harian';

    public function up(): void
    {
        Schema::table('sales_daily_plan_item_evidences', function (Blueprint $table): void {
            $table->foreignId('archive_id')->nullable()->after('checksum')->constrained('sales_agenda_evidence_archives')->nullOnDelete();
            $table->string('archive_entry_path')->nullable()->after('archive_id');
            $table->timestamp('archived_at')->nullable()->after('archive_entry_path');
            $table->index(['archive_id', 'purged_at']);
        });

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::CHANGELOG_TITLE],
            [
                'category' => 'changed',
                'description' => 'Bukti foto aktivitas Daily Plan kini masuk arsip mingguan Agenda Sales dan mengikuti verifikasi serta purge retensi yang sama.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        $hasRetainedEvidence = DB::table('sales_daily_plan_item_evidences')
            ->where(function ($query): void {
                $query->whereNotNull('archive_id')
                    ->orWhereNotNull('archive_entry_path')
                    ->orWhereNotNull('archived_at')
                    ->orWhereNotNull('purged_at');
            })
            ->exists();
        if ($hasRetainedEvidence) {
            throw new RuntimeException('Rollback ditolak: metadata evidence Daily Plan yang telah diarsipkan atau dipurge masih digunakan.');
        }

        Schema::table('sales_daily_plan_item_evidences', function (Blueprint $table): void {
            $table->dropForeign(['archive_id']);
            $table->dropIndex(['archive_id', 'purged_at']);
            $table->dropColumn(['archive_id', 'archive_entry_path', 'archived_at']);
        });

        DB::table('changelogs')->whereNull('version')->where('title', self::CHANGELOG_TITLE)->delete();
    }
};
