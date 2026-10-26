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
        Schema::create('sales_daily_plan_item_evidences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_daily_plan_item_id')->constrained('sales_daily_plan_items')->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('storage_path')->nullable();
            $table->string('file_path')->nullable();
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->string('checksum', 64)->nullable();
            $table->string('archive_status')->default('local_only');
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();

            $table->index(['sales_daily_plan_item_id', 'purged_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_daily_plan_item_evidences');
    }
};
