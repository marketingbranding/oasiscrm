<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('content_items')
            ->where('item_type', 'content')
            ->whereNull('scheduled_date')
            ->update(['scheduled_date' => DB::raw('COALESCE(start_date, CURRENT_DATE)')]);

        DB::table('content_items')
            ->where('item_type', 'content')
            ->whereNull('start_date')
            ->update(['start_date' => DB::raw('scheduled_date')]);
    }

    public function down(): void
    {
        // Keep restored content dates; this migration only repairs local data after the form rule changed.
    }
};
