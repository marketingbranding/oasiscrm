<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dana_talangan_bridge_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('spreadsheet_id')->unique();
            $table->string('mode', 20)->default('off');
            $table->string('status', 30)->default('disabled');
            $table->timestamp('preflight_at')->nullable();
            $table->string('preflight_hash', 64)->nullable();
            $table->foreignId('enabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamps();
            $table->index(['mode', 'status']);
        });

        Schema::table('dana_talangans', function (Blueprint $table): void {
            $table->foreignId('project_id')->nullable()->after('branch_id')->constrained('lead_master')->nullOnDelete();
            $table->string('remote_target_spreadsheet_id')->nullable()->after('sheet_row_number');
            $table->string('sync_status', 30)->default('pending_create')->change();
            $table->string('last_synced_payload_hash', 64)->nullable()->after('source_hash');
            $table->string('last_remote_payload_hash', 64)->nullable()->after('last_synced_payload_hash');
            $table->json('last_synced_field_hashes')->nullable()->after('last_remote_payload_hash');
            $table->timestamp('delivery_attempted_at')->nullable()->after('last_synced_field_hashes');
            $table->timestamp('delete_pending_at')->nullable()->after('delivery_attempted_at');
            $table->index(['remote_target_spreadsheet_id', 'sync_status'], 'dana_talangan_target_sync_index');
            $table->index(['project_id', 'sync_status'], 'dana_talangan_project_sync_index');
        });

        DB::table('dana_talangans')->orderBy('id')->chunkById(200, function ($records): void {
            foreach ($records as $record) {
                $projects = DB::table('lead_master')
                    ->where('branch_id', $record->branch_id)
                    ->where('is_active', true)
                    ->get(['id', 'project_name', 'sheet_project_name'])
                    ->filter(fn ($project) => $project->project_name === $record->project_name || $project->sheet_project_name === $record->project_name)
                    ->pluck('id');
                if ($projects->count() === 1) {
                    DB::table('dana_talangans')->where('id', $record->id)->update(['project_id' => $projects->first()]);
                }
            }
        });

        $spreadsheetId = trim((string) config('services.google_sheets.dana_talangan_spreadsheet_id'));
        if ($spreadsheetId !== '') {
            DB::table('dana_talangans')
                ->whereNull('remote_target_spreadsheet_id')
                ->whereNotNull('last_synced_at')
                ->update(['remote_target_spreadsheet_id' => $spreadsheetId]);
        }

        Schema::create('dana_talangan_reconciliation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dana_talangan_id')->nullable()->constrained('dana_talangans')->nullOnDelete();
            $table->string('spreadsheet_id');
            $table->uuid('remote_sync_id')->nullable();
            $table->unsignedInteger('remote_row_number')->nullable();
            $table->string('issue_code', 60);
            $table->json('field_names')->nullable();
            $table->json('safe_metadata')->nullable();
            $table->string('status', 20)->default('open');
            $table->string('identity_key', 64)->unique();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'issue_code']);
            $table->index(['spreadsheet_id', 'remote_row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dana_talangan_reconciliation_items');
        Schema::dropIfExists('dana_talangan_bridge_settings');

        Schema::table('dana_talangans', function (Blueprint $table): void {
            $table->dropIndex('dana_talangan_target_sync_index');
            $table->dropIndex('dana_talangan_project_sync_index');
            $table->dropForeign(['project_id']);
            $table->dropColumn([
                'project_id',
                'remote_target_spreadsheet_id',
                'last_synced_payload_hash',
                'last_remote_payload_hash',
                'last_synced_field_hashes',
                'delivery_attempted_at',
                'delete_pending_at',
            ]);
        });
    }
};
