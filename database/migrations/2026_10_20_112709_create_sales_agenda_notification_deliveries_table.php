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
        Schema::create('sales_agenda_notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->constrained('content_items')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->string('notification_type', 40);
            $table->string('occurrence_key', 150);
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(
                ['content_item_id', 'recipient_id', 'notification_type', 'occurrence_key'],
                'sales_agenda_notification_delivery_unique',
            );
            $table->index(['notification_type', 'sent_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_agenda_notification_deliveries');
    }
};
