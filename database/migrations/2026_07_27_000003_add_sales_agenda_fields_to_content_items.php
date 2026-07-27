<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->text('activity_result')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('sales_activity_category', 50)->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sales_project_id')->nullable()->constrained('lead_master')->nullOnDelete();
            $table->foreignId('rescheduled_from_id')->nullable()->constrained('content_items')->nullOnDelete();

            $table->index(['agenda_type', 'scheduled_date'], 'content_items_sales_agenda_date_index');
            $table->index(['agenda_type', 'owner_user_id', 'scheduled_date'], 'content_items_sales_agenda_owner_date_index');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('content_items', 'sales_project_id')) {
            Schema::table('content_items', function (Blueprint $table) {
                $table->dropConstrainedForeignId('sales_project_id');
            });
        }

        Schema::table('content_items', function (Blueprint $table) {
            $table->dropIndex('content_items_sales_agenda_date_index');
            $table->dropIndex('content_items_sales_agenda_owner_date_index');
            $table->dropForeign(['owner_user_id']);
            $table->dropForeign(['rescheduled_from_id']);
            $table->dropColumn([
                'activity_result',
                'duration_minutes',
                'sales_activity_category',
                'owner_user_id',
                'rescheduled_from_id',
            ]);
        });
    }
};
