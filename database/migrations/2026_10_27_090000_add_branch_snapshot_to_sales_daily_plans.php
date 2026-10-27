<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHANGELOG_TITLE = 'Perlindungan Arsip Agenda Sales';

    public function up(): void
    {
        Schema::table('sales_daily_plans', function (Blueprint $table): void {
            $table->foreignId('branch_id')->nullable()->after('sales_user_id')->constrained('branches')->nullOnDelete();
            $table->index(['branch_id', 'work_date']);
        });

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::CHANGELOG_TITLE],
            [
                'category' => 'fixed',
                'description' => 'Arsip evidence Agenda Sales mempertahankan snapshot cabang Daily Plan baru; Daily Plan lama tanpa histori cabang tetap unresolved dan tidak diarsipkan otomatis, sementara penghapusan Agenda Sales berbukti serta mutation tanpa izin terdaftar ditolak.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        if (DB::table('sales_daily_plans')->whereNotNull('branch_id')->exists()
            || DB::table('sales_daily_plan_item_evidences')->where(function ($query): void {
                $query->whereNotNull('archive_id')
                    ->orWhereNotNull('archive_entry_path')
                    ->orWhereNotNull('archived_at')
                    ->orWhereNotNull('purged_at');
            })->exists()) {
            throw new RuntimeException('Rollback ditolak: snapshot cabang atau metadata evidence Daily Plan masih digunakan.');
        }

        Schema::table('sales_daily_plans', function (Blueprint $table): void {
            $table->dropIndex(['branch_id', 'work_date']);
            $table->dropForeign(['branch_id']);
            $table->dropColumn('branch_id');
        });

        DB::table('changelogs')->whereNull('version')->where('title', self::CHANGELOG_TITLE)->delete();
    }
};
