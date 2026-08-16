<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumer_legacy_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumer_application_id')->nullable()->constrained('consumer_applications')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('legacy_source', 40);
            $table->string('spreadsheet_id')->nullable();
            $table->string('sheet_name')->nullable();
            $table->string('external_key')->nullable();
            $table->unsignedInteger('legacy_row_number')->nullable();
            $table->string('source_payload_hash', 64)->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('mapping_status', 40)->default('unmapped');
            $table->timestamps();

            $table->unique(['legacy_source', 'spreadsheet_id', 'sheet_name', 'external_key'], 'consumer_legacy_identity_source_key_unique');
            $table->index(['legacy_source', 'external_key'], 'consumer_legacy_identity_lookup_index');
            $table->index('consumer_application_id', 'consumer_legacy_identity_application_index');
            $table->index('customer_id', 'consumer_legacy_identity_customer_index');
            $table->index('source_payload_hash', 'consumer_legacy_identity_payload_hash_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumer_legacy_identities');
    }
};
