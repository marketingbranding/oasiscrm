<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Owner remains required. Queue records mark handoff candidates.
    }

    public function down(): void
    {
        // No schema change to reverse.
    }
};
