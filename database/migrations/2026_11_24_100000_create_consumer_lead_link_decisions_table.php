<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACTIVE_INDEX = 'consumer_lead_link_decisions_active_unique';

    public function up(): void
    {
        if (! Schema::hasTable('consumer_lead_link_decisions')) {
            Schema::create('consumer_lead_link_decisions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('consumer_application_id')->constrained('consumer_applications')->restrictOnDelete();
                $table->string('status', 30);
                $table->foreignId('sales_lead_id')->nullable()->constrained('sales_leads')->nullOnDelete();
                $table->string('decision_source', 64);
                $table->json('suggestions')->nullable();
                $table->text('reason')->nullable();
                $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('decided_at');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['consumer_application_id', 'is_active'], 'consumer_lead_link_decisions_application_active_index');
                $table->index(['status', 'decided_at'], 'consumer_lead_link_decisions_status_time_index');
            });
        }

        if (! Schema::hasIndex('consumer_lead_link_decisions', self::ACTIVE_INDEX)) {
            if (DB::connection()->getDriverName() === 'mysql') {
                if (! Schema::hasColumn('consumer_lead_link_decisions', 'active_consumer_application_key')) {
                    DB::statement('ALTER TABLE consumer_lead_link_decisions ADD active_consumer_application_key BIGINT GENERATED ALWAYS AS (IF(is_active = 1, consumer_application_id, NULL)) STORED');
                }

                DB::statement('CREATE UNIQUE INDEX '.self::ACTIVE_INDEX.' ON consumer_lead_link_decisions (active_consumer_application_key)');
            } else {
                DB::statement('CREATE UNIQUE INDEX '.self::ACTIVE_INDEX.' ON consumer_lead_link_decisions (consumer_application_id) WHERE is_active = 1');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('consumer_lead_link_decisions');
    }
};
