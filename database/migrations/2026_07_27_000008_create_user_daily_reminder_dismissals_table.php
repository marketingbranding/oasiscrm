<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_daily_reminder_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reminder_key', 100);
            $table->date('dismissed_for_date');
            $table->timestamp('dismissed_at');
            $table->timestamps();
            $table->unique(['user_id', 'reminder_key', 'dismissed_for_date'], 'daily_reminder_user_key_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_daily_reminder_dismissals');
    }
};
