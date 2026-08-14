<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_import_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('status', 30);
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedSmallInteger('total_rows')->default(0);
            $table->unsignedSmallInteger('valid_rows')->default(0);
            $table->unsignedSmallInteger('invalid_rows')->default(0);
            $table->unsignedSmallInteger('created_rows')->default(0);
            $table->unsignedSmallInteger('updated_rows')->default(0);
            $table->unsignedSmallInteger('skipped_rows')->default(0);
            $table->timestamps();
        });
        Schema::create('promo_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('promo_import_batches')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->json('raw_data');
            $table->json('normalized_data');
            $table->string('status', 30);
            $table->json('errors');
            $table->timestamps();
            $table->unique(['batch_id', 'line_number'], 'promo_import_rows_batch_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_import_rows');
        Schema::dropIfExists('promo_import_batches');
    }
};
