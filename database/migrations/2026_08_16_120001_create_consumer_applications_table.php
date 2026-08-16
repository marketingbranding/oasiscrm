<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumer_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('project_id')->constrained('lead_master')->restrictOnDelete();
            $table->foreignId('sales_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('kavling_id')->nullable()->constrained('kavlings')->nullOnDelete();
            $table->foreignId('promo_id')->nullable()->constrained('promos')->nullOnDelete();
            $table->foreignId('sales_lead_id')->nullable()->constrained('sales_leads')->nullOnDelete();
            $table->string('application_status', 40)->default('draft');
            $table->string('current_stage', 40)->nullable();
            $table->date('booking_date')->nullable();
            $table->date('akad_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'application_status'], 'consumer_applications_branch_status_index');
            $table->index(['project_id', 'application_status'], 'consumer_applications_project_status_index');
            $table->index(['sales_user_id', 'application_status'], 'consumer_applications_sales_status_index');
            $table->index('kavling_id', 'consumer_applications_kavling_index');
            $table->index('promo_id', 'consumer_applications_promo_index');
            $table->index('sales_lead_id', 'consumer_applications_sales_lead_index');
            $table->index('current_stage', 'consumer_applications_current_stage_index');
            $table->index('booking_date', 'consumer_applications_booking_date_index');
            $table->index('akad_date', 'consumer_applications_akad_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumer_applications');
    }
};
