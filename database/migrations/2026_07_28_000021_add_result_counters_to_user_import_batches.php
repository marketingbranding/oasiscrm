<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_import_batches', function (Blueprint $table) {
            $table->unsignedInteger('created_rows')->default(0);
            $table->unsignedInteger('invitation_sent_rows')->default(0);
            $table->unsignedInteger('invitation_failed_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('user_import_batches', function (Blueprint $table) {
            $table->dropColumn(['created_rows', 'invitation_sent_rows', 'invitation_failed_rows', 'skipped_rows']);
        });
    }
};
