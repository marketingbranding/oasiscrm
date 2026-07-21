<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_task_runs', function (Blueprint $table) {
            $table->id();
            $table->string('task_key', 100);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('status', 20);
            $table->json('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['task_key', 'started_at']);
            $table->index(['task_key', 'status', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_task_runs');
    }
};
