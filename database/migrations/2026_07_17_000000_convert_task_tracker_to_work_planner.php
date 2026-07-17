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
            $table->string('item_type', 20)->default('task')->after('project_name');
            $table->string('visibility', 20)->default('team')->after('item_type');
            $table->string('agenda_type', 50)->nullable()->after('platform');
            $table->string('location')->nullable()->after('agenda_type');
            $table->time('start_time')->nullable()->after('start_date');
            $table->time('end_time')->nullable()->after('deadline_date');
            $table->string('content_format', 50)->nullable()->after('end_time');
            $table->text('asset_url')->nullable()->after('content_format');

            $table->index(['item_type', 'scheduled_date']);
            $table->index(['visibility', 'branch_id']);
        });

        Schema::create('content_item_user', function (Blueprint $table) {
            $table->foreignId('content_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['content_item_id', 'user_id']);
        });

        DB::table('content_items')->update([
            'item_type' => 'task',
            'visibility' => 'team',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('content_item_user');
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropIndex(['item_type', 'scheduled_date']);
            $table->dropIndex(['visibility', 'branch_id']);
            $table->dropColumn([
                'item_type', 'visibility', 'agenda_type', 'location',
                'start_time', 'end_time', 'content_format', 'asset_url',
            ]);
        });
    }
};
