<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumer_parity_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->timestamp('checked_at');
            $table->string('status');
            $table->json('summary');
            $table->json('metrics');
            $table->json('differences');
            $table->timestamps();
            $table->index(['branch_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumer_parity_snapshots');
    }
};
