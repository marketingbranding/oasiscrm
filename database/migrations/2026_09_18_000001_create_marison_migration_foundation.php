<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marison_project_mappings')) {
            Schema::create('marison_project_mappings', function (Blueprint $table): void {
                $table->id();
                $table->string('source_system', 50);
                $table->string('branch_code', 20);
                $table->string('source_project_id', 100);
                $table->foreignId('oasis_project_id')->constrained('lead_master')->restrictOnDelete();
                $table->timestamps();
                $table->unique(['source_system', 'branch_code', 'source_project_id'], 'marison_project_mapping_source_unique');
                $table->index('oasis_project_id', 'marison_project_mapping_project_index');
            });
        }

        if (! Schema::hasTable('marison_import_batches')) {
            Schema::create('marison_import_batches', function (Blueprint $table): void {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('source_system', 50);
                $table->string('source_branch_code', 20);
                $table->string('source_spreadsheet_id', 255);
                $table->string('source_version', 50)->nullable();
                $table->dateTime('source_exported_at')->nullable();
                $table->string('contract_version', 20);
                $table->char('payload_hash', 64);
                $table->char('preview_hash', 64)->nullable();
                $table->unsignedInteger('preview_version')->default(1);
                $table->string('status', 40)->default('uploaded');
                $table->dateTime('expires_at')->nullable();
                $table->dateTime('confirmed_at')->nullable();
                $table->json('counts')->nullable();
                $table->text('error_summary')->nullable();
                $table->timestamps();
                $table->index(['source_system', 'source_branch_code'], 'marison_batches_source_branch_index');
                $table->index(['status', 'expires_at'], 'marison_batches_status_expiry_index');
            });
        }

        Schema::create('consumer_migration_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('consumer_application_id');
            $table->foreign('consumer_application_id', 'cmr_app_fk')->references('id')->on('consumer_applications')->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->foreign('branch_id', 'cmr_branch_fk')->references('id')->on('branches')->restrictOnDelete();
            $table->unsignedBigInteger('project_id');
            $table->foreign('project_id', 'cmr_project_fk')->references('id')->on('lead_master')->restrictOnDelete();
            $table->string('source_system', 50);
            $table->string('source_transaction_id', 150);
            $table->string('source_customer_ref', 150)->nullable();
            $table->string('source_current_kavling', 255)->nullable();
            $table->string('source_transaction_status', 100)->nullable();
            $table->string('source_bank_status', 100)->nullable();
            $table->string('source_kavling_status', 100)->nullable();
            $table->string('source_stage_status', 100)->nullable();
            $table->string('suggested_decision', 40)->nullable();
            $table->string('decision', 40)->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->foreign('customer_id', 'cmr_customer_fk')->references('id')->on('customers')->nullOnDelete();
            $table->unsignedBigInteger('target_kavling_id')->nullable();
            $table->foreign('target_kavling_id', 'cmr_kavling_fk')->references('id')->on('kavlings')->nullOnDelete();
            $table->string('reconciliation_status', 40)->default('PENDING');
            $table->char('source_payload_hash', 64);
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->foreign('resolved_by', 'cmr_resolved_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['source_system', 'branch_id', 'source_transaction_id'], 'consumer_migration_reconciliation_source_unique');
            $table->unique('consumer_application_id', 'consumer_migration_reconciliation_application_unique');
            $table->index(['reconciliation_status', 'branch_id'], 'consumer_migration_reconciliation_queue_index');
        });

        Schema::create('marison_import_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('marison_import_batches')->cascadeOnDelete();
            $table->string('source_system', 50);
            $table->string('branch_code', 20);
            $table->string('source_transaction_id', 150);
            $table->string('source_customer_ref', 150)->nullable();
            $table->string('source_project_id', 100);
            $table->unsignedBigInteger('project_mapping_id')->nullable();
            $table->foreign('project_mapping_id', 'mit_mapping_fk')->references('id')->on('marison_project_mappings')->nullOnDelete();
            $table->unsignedBigInteger('resolved_project_id')->nullable();
            $table->foreign('resolved_project_id', 'mit_project_fk')->references('id')->on('lead_master')->restrictOnDelete();
            $table->string('outcome', 40);
            $table->char('payload_hash', 64);
            $table->json('payload');
            $table->json('errors')->nullable();
            $table->unsignedBigInteger('consumer_application_id')->nullable();
            $table->foreign('consumer_application_id', 'mit_app_fk')->references('id')->on('consumer_applications')->nullOnDelete();
            $table->unsignedBigInteger('reconciliation_id')->nullable();
            $table->foreign('reconciliation_id', 'mit_recon_fk')->references('id')->on('consumer_migration_reconciliations')->nullOnDelete();
            $table->timestamps();
            $table->unique(['batch_id', 'source_transaction_id'], 'marison_import_transaction_batch_source_unique');
            $table->index(['source_system', 'branch_code', 'source_transaction_id'], 'marison_import_transaction_identity_index');
            $table->index(['batch_id', 'outcome'], 'marison_import_transaction_outcome_index');
        });

        Schema::create('marison_unlinked_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('marison_import_batches')->cascadeOnDelete();
            $table->string('source_sheet', 100);
            $table->unsignedBigInteger('source_row')->nullable();
            $table->string('source_id', 150)->nullable();
            $table->string('reason', 255);
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->timestamps();
            $table->index(['batch_id', 'source_sheet'], 'marison_unlinked_batch_sheet_index');
            $table->index(['source_sheet', 'source_id'], 'marison_unlinked_source_index');
        });

        Schema::create('consumer_migration_source_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('import_transaction_id')->nullable();
            $table->foreign('import_transaction_id', 'cmsr_import_fk')->references('id')->on('marison_import_transactions')->nullOnDelete();
            $table->unsignedBigInteger('consumer_application_id')->nullable();
            $table->foreign('consumer_application_id', 'cmsr_app_fk')->references('id')->on('consumer_applications')->cascadeOnDelete();
            $table->string('source_system', 50);
            $table->string('branch_code', 20);
            $table->string('source_transaction_id', 150);
            $table->string('source_type', 50);
            $table->string('source_record_id', 150);
            $table->char('payload_hash', 64);
            $table->json('metadata')->nullable();
            $table->string('target_type', 150)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->timestamps();
            $table->unique(['source_system', 'branch_code', 'source_type', 'source_record_id'], 'consumer_migration_source_record_unique');
            $table->index(['source_system', 'branch_code', 'source_transaction_id'], 'consumer_migration_source_transaction_index');
            $table->index(['target_type', 'target_id'], 'consumer_migration_source_target_index');
        });

        Schema::table('consumer_ppjb_developers', function (Blueprint $table): void {
            $table->string('source_system', 50)->nullable();
            $table->string('source_id', 150)->nullable();
            $table->string('status', 100)->nullable();
            $table->json('metadata')->nullable();
            $table->unique(['source_system', 'source_id'], 'consumer_ppjb_source_unique');
        });

        Schema::table('consumer_akad_records', function (Blueprint $table): void {
            $table->string('no_ppjb_akad', 150)->nullable();
            $table->unique('no_ppjb_akad', 'consumer_akad_no_ppjb_unique');
        });

        Schema::table('consumer_bast_records', function (Blueprint $table): void {
            $table->string('no_bast', 150)->nullable();
            $table->string('status', 100)->nullable();
            $table->text('notes')->nullable();
            $table->unique('no_bast', 'consumer_bast_no_bast_unique');
        });

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Fondasi migrasi data Marison V2'],
            ['description' => 'Menyiapkan penyimpanan batch, jejak sumber, pemetaan proyek, dan rekonsiliasi migrasi konsumen Marison V2 secara aman dan idempoten.', 'category' => 'added', 'created_by' => null, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', 'Fondasi migrasi data Marison V2')->delete();

        Schema::table('consumer_bast_records', function (Blueprint $table): void {
            $table->dropUnique('consumer_bast_no_bast_unique');
            $table->dropColumn(['no_bast', 'status', 'notes']);
        });

        Schema::table('consumer_akad_records', function (Blueprint $table): void {
            $table->dropUnique('consumer_akad_no_ppjb_unique');
            $table->dropColumn('no_ppjb_akad');
        });

        Schema::table('consumer_ppjb_developers', function (Blueprint $table): void {
            $table->dropUnique('consumer_ppjb_source_unique');
            $table->dropColumn(['source_system', 'source_id', 'status', 'metadata']);
        });

        Schema::dropIfExists('consumer_migration_source_records');
        Schema::dropIfExists('marison_unlinked_records');
        Schema::dropIfExists('marison_import_transactions');
        Schema::dropIfExists('consumer_migration_reconciliations');
        Schema::dropIfExists('marison_import_batches');
        Schema::dropIfExists('marison_project_mappings');
    }
};
