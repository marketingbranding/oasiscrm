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
            $table->text('task_detail')->nullable()->after('title');
            $table->date('start_date')->nullable()->after('platform');
            $table->date('deadline_date')->nullable()->after('start_date');
            $table->string('priority', 20)->default('medium')->after('deadline_date');
            $table->json('pic_names')->nullable()->after('priority');
            $table->timestamp('completed_at')->nullable()->after('status');
        });

        DB::table('content_items')->whereNull('deadline_date')->update([
            'deadline_date' => DB::raw('scheduled_date'),
        ]);

        DB::table('content_items')->where('status', 'draft')->update(['status' => 'todo']);
        DB::table('content_items')->whereIn('status', ['review', 'approved'])->update(['status' => 'in_progress']);
        DB::table('content_items')->where('status', 'posted')->update(['status' => 'completed']);
        DB::table('content_items')->where('status', 'completed')->whereNull('completed_at')->update(['completed_at' => now()]);
    }

    public function down(): void
    {
        DB::table('content_items')->where('status', 'todo')->update(['status' => 'draft']);
        DB::table('content_items')->where('status', 'in_progress')->update(['status' => 'review']);
        DB::table('content_items')->where('status', 'completed')->update(['status' => 'posted']);
        DB::table('content_items')->where('status', 'lost_track')->update(['status' => 'draft']);

        Schema::table('content_items', function (Blueprint $table) {
            $table->dropColumn(['task_detail', 'start_date', 'deadline_date', 'priority', 'pic_names', 'completed_at']);
        });
    }
};
