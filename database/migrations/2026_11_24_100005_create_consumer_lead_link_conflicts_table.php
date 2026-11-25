<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumer_lead_link_conflicts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_decision_id')->constrained('consumer_lead_link_decisions')->restrictOnDelete();
            $table->foreignId('target_decision_id')->nullable()->constrained('consumer_lead_link_decisions')->nullOnDelete();
            $table->foreignId('consumer_application_id')->constrained('consumer_applications')->restrictOnDelete();
            $table->string('conflict_type', 40);
            $table->string('status', 30)->default('open');
            $table->json('details')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Konflik carry-over penautan Lead'],
            ['category' => 'added', 'description' => 'Konflik keputusan penautan Lead dicatat tanpa menimpa keputusan aktif.', 'created_by' => null, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', 'Konflik carry-over penautan Lead')->delete();
        Schema::dropIfExists('consumer_lead_link_conflicts');
    }
};
