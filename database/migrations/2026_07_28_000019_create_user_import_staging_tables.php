<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('original_filename');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 32);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('warning_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->boolean('send_invitations')->default(false);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'user_import_batches_status_created_index');
            $table->index(['uploaded_by', 'created_at'], 'user_import_batches_uploader_created_index');
        });

        Schema::create('user_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('user_import_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('raw_data');
            $table->json('normalized_data');
            $table->string('validation_status', 16);
            $table->json('errors')->nullable();
            $table->json('warnings')->nullable();
            $table->foreignId('created_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('invitation_status', 32)->nullable();
            $table->string('creation_status', 32)->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'validation_status'], 'user_import_rows_batch_validation_index');
            $table->unique(['batch_id', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_import_rows');
        Schema::dropIfExists('user_import_batches');
    }
};
