<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->string('tujuan_konten', 50)->nullable()->after('content_format');
            $table->date('scheduled_date')->nullable()->change();
        });

        DB::table('content_items')->where('item_type', 'content')->whereIn('status', ['draft', 'review', 'scheduled', 'in_progress'])->update(['status' => 'content_in_progress']);
        DB::table('content_items')->where('item_type', 'content')->where('status', 'published')->update(['status' => 'uploaded']);
        DB::table('content_items')->where('item_type', 'content')->where('status', 'cancelled')->update(['status' => 'idea']);
        DB::table('content_items')->where('item_type', 'content')->update([
            'deadline_date' => null,
            'end_time' => null,
            'visibility' => 'team',
        ]);
    }

    public function down(): void
    {
        DB::table('content_items')->whereNull('scheduled_date')->update(['scheduled_date' => now()->toDateString()]);

        Schema::table('content_items', function (Blueprint $table) {
            $table->dropColumn('tujuan_konten');
            $table->date('scheduled_date')->nullable(false)->change();
        });
    }
};
