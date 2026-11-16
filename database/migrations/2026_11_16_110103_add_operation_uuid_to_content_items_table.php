<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->uuid('operation_uuid')->nullable();
            $table->char('operation_payload_hash', 64)->nullable();
            $table->unique('operation_uuid', 'content_items_operation_uuid_unique');
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->dropUnique('content_items_operation_uuid_unique');
            $table->dropColumn(['operation_uuid', 'operation_payload_hash']);
        });
    }
};
