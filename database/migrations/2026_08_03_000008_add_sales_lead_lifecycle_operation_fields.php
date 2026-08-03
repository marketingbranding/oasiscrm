<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_lead_site_visits', function (Blueprint $table) {
            $table->date('visit_date')->nullable()->after('visited_at');
            $table->string('visit_time', 20)->nullable()->after('visit_date');
            $table->string('visit_status', 20)->nullable()->after('visit_time');
            $table->text('notes')->nullable()->after('visit_status');
            $table->boolean('is_completed')->default(false)->after('notes');
        });

        Schema::table('sales_lead_consumer_links', function (Blueprint $table) {
            $table->string('sheet_type', 40)->nullable()->after('consumer_reference');
            $table->string('nik', 16)->nullable()->after('sheet_type');
            $table->string('id_kavling')->nullable()->after('nik');
            $table->json('payload')->nullable()->after('id_kavling');
            $table->unique(['branch_id', 'nik'], 'lead_consumer_branch_nik_unique');
        });

        Schema::table('sales_lead_slik_attempts', function (Blueprint $table) {
            $table->foreignId('consumer_link_id')->nullable()->after('actor_id')->constrained('sales_lead_consumer_links')->restrictOnDelete();
            $table->string('id_kavling')->nullable()->after('nik');
            $table->date('slik_date')->nullable()->after('id_kavling');
            $table->string('slik_result')->nullable()->after('result');
            $table->text('notes')->nullable()->after('slik_result');
            $table->timestamp('rejected_at')->nullable()->after('notes');
        });

        Schema::table('sales_lead_freelance_links', function (Blueprint $table) {
            $table->foreignId('coordinator_user_id')->nullable()->after('freelancer_user_id')->constrained('users')->nullOnDelete();
            $table->string('nik_sales')->nullable()->after('freelance_reference');
            $table->string('sales_name')->nullable()->after('nik_sales');
            $table->string('coordinator_nik')->nullable()->after('sales_name');
            $table->string('coordinator_name')->nullable()->after('coordinator_nik');
        });

        Schema::table('sales_lead_akad_links', function (Blueprint $table) {
            $table->foreignId('consumer_link_id')->nullable()->after('actor_id')->constrained('sales_lead_consumer_links')->restrictOnDelete();
            $table->foreignId('slik_attempt_id')->nullable()->after('consumer_link_id')->constrained('sales_lead_slik_attempts')->restrictOnDelete();
            $table->string('id_kavling')->nullable()->after('akad_reference');
        });
    }

    public function down(): void
    {
        Schema::table('sales_lead_akad_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('slik_attempt_id');
            $table->dropConstrainedForeignId('consumer_link_id');
            $table->dropColumn('id_kavling');
        });

        Schema::table('sales_lead_freelance_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coordinator_user_id');
            $table->dropColumn(['nik_sales', 'sales_name', 'coordinator_nik', 'coordinator_name']);
        });

        Schema::table('sales_lead_slik_attempts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('consumer_link_id');
            $table->dropColumn(['id_kavling', 'slik_date', 'slik_result', 'notes', 'rejected_at']);
        });

        Schema::table('sales_lead_consumer_links', function (Blueprint $table) {
            $table->dropUnique('lead_consumer_branch_nik_unique');
            $table->dropColumn(['sheet_type', 'nik', 'id_kavling', 'payload']);
        });

        Schema::table('sales_lead_site_visits', function (Blueprint $table) {
            $table->dropColumn(['visit_date', 'visit_time', 'visit_status', 'notes', 'is_completed']);
        });
    }
};
