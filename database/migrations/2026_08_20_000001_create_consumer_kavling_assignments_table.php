<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumer_kavling_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('consumer_application_id')->constrained('consumer_applications')->restrictOnDelete();
            $table->foreignId('kavling_id')->constrained('kavlings')->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason')->nullable();
            $table->string('assignment_status', 20)->default('active');
            $table->timestamps();
            $table->index(['consumer_application_id', 'assignment_status'], 'consumer_kavling_assignments_application_status_index');
            $table->index(['kavling_id', 'assignment_status'], 'consumer_kavling_assignments_kavling_status_index');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE consumer_kavling_assignments ADD active_kavling_key BIGINT GENERATED ALWAYS AS (IF(assignment_status = 'active' AND released_at IS NULL, kavling_id, NULL)) STORED");
            DB::statement('CREATE UNIQUE INDEX consumer_kavling_assignments_active_unique ON consumer_kavling_assignments (active_kavling_key)');
        } else {
            DB::statement("CREATE UNIQUE INDEX consumer_kavling_assignments_active_unique ON consumer_kavling_assignments (kavling_id) WHERE assignment_status = 'active' AND released_at IS NULL");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('consumer_kavling_assignments');
    }
};
