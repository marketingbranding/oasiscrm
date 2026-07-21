<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback_reports', function (Blueprint $table) {
            $table->string('module', 100)->nullable()->after('title');
            $table->text('activity')->nullable()->after('description');
            $table->text('expected_result')->nullable()->after('activity');
            $table->text('actual_result')->nullable()->after('expected_result');
            $table->text('suggestion')->nullable()->after('actual_result');
            $table->text('impact')->nullable()->after('suggestion');
            $table->string('target_users')->nullable()->after('impact');
            $table->string('reproduction_frequency', 30)->nullable()->after('target_users');
            $table->string('need_level', 20)->nullable()->after('reproduction_frequency');
            $table->text('additional_notes')->nullable()->after('need_level');
            $table->text('page_url')->nullable()->after('additional_notes');
            $table->string('route_name')->nullable()->after('page_url');
            $table->foreignId('active_branch_id')->nullable()->after('route_name')->constrained('branches')->nullOnDelete();
            $table->string('app_version')->nullable()->after('active_branch_id');
            $table->string('user_agent_summary')->nullable()->after('app_version');
            $table->string('screen_size', 30)->nullable()->after('user_agent_summary');
            $table->string('priority', 20)->default('medium')->after('status');
            $table->foreignId('assigned_to')->nullable()->after('reviewed_by')->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->after('reviewed_at');
            $table->string('screenshot_path')->nullable();
            $table->string('screenshot_name')->nullable();
            $table->string('screenshot_mime', 50)->nullable();
            $table->unsignedBigInteger('screenshot_size')->nullable();

            $table->index(['type', 'status']);
            $table->index(['module', 'status']);
            $table->index(['assigned_to', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('feedback_reports', function (Blueprint $table) {
            $table->dropIndex(['type', 'status']);
            $table->dropIndex(['module', 'status']);
            $table->dropIndex(['assigned_to', 'status']);
            $table->dropConstrainedForeignId('active_branch_id');
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropColumn([
                'module', 'activity', 'expected_result', 'actual_result', 'suggestion', 'impact',
                'target_users', 'reproduction_frequency', 'need_level', 'additional_notes', 'page_url',
                'route_name', 'app_version', 'user_agent_summary', 'screen_size', 'priority', 'resolved_at',
                'screenshot_path', 'screenshot_name', 'screenshot_mime', 'screenshot_size',
            ]);
        });
    }
};
