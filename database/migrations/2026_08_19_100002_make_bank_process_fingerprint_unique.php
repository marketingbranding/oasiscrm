<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consumer_bank_processes', function (Blueprint $table): void {
            $table->dropIndex('consumer_bank_processes_fingerprint_index');
            $table->unique(['consumer_application_id', 'source', 'source_id'], 'consumer_bank_processes_fingerprint_unique');
        });
    }

    public function down(): void
    {
        Schema::table('consumer_bank_processes', function (Blueprint $table): void {
            $table->dropUnique('consumer_bank_processes_fingerprint_unique');
            $table->index(['consumer_application_id', 'source', 'source_id'], 'consumer_bank_processes_fingerprint_index');
        });
    }
};
