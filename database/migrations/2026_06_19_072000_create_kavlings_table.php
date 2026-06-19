<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kavlings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('lead_master')->cascadeOnDelete();
            $table->string('kavling_code');
            $table->string('name');
            $table->timestamps();

            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kavlings');
    }
};
