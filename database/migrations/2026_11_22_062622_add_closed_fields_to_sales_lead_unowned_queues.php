<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_lead_unowned_queues', function (Blueprint $table): void {
            $table->foreignId('closed_by')->nullable()->after('resolved_by_user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable()->after('closed_by');
        });
    }

    public function down(): void
    {
        Schema::table('sales_lead_unowned_queues', function (Blueprint $table): void {
            $table->dropForeign(['closed_by']);
            $table->dropColumn(['closed_by', 'closed_at']);
        });
    }
};
