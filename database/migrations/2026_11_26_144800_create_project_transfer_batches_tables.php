<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_transfer_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('target_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->json('project_ids');
            $table->json('user_mapping');
            $table->string('status', 24)->default('completed');
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->json('impact_summary')->nullable();
            $table->timestamps();

            $table->index(['source_branch_id', 'target_branch_id'], 'ptb_source_target_index');
            $table->index(['status', 'executed_at'], 'ptb_status_executed_index');
        });

        Schema::create('project_transfer_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('project_transfer_batches')->cascadeOnDelete();
            $table->string('table_name', 64);
            $table->unsignedBigInteger('row_id');
            $table->json('old_values')->nullable();
            $table->boolean('was_created')->default(false);
            $table->timestamp('old_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['batch_id', 'table_name', 'row_id'], 'pti_batch_table_row_unique');
            $table->index(['table_name', 'row_id'], 'pti_table_row_index');
        });

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Pindah proyek antar cabang'],
            [
                'category' => 'added',
                'description' => 'Menambahkan batch dan snapshot untuk pemindahan proyek antar cabang yang dapat dibatalkan.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', 'Pindah proyek antar cabang')->delete();
        Schema::dropIfExists('project_transfer_items');
        Schema::dropIfExists('project_transfer_batches');
    }
};
