<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('organization_assignments')) {
            return;
        }

        Schema::create('organization_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('parent_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('relationship_type', 64)->default('reports_to');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('lead_master')->nullOnDelete();
            $table->date('started_at');
            $table->date('ended_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_primary')->default(true);
            $table->string('source', 64)->default('organization_workspace');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['user_id', 'relationship_type', 'is_active', 'is_primary'], 'organization_assignments_current_user_index');
            $table->index(['parent_user_id', 'relationship_type', 'is_active', 'is_primary'], 'organization_assignments_current_parent_index');
            $table->index(['started_at', 'ended_at'], 'organization_assignments_effective_dates_index');
            $table->index(['branch_id', 'project_id'], 'organization_assignments_scope_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_assignments');
    }
};
