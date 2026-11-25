<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('comments', 'source')) {
            Schema::table('comments', function (Blueprint $table): void {
                $table->string('source', 32)->nullable()->default(null)->after('body_plain');
                $table->index(['commentable_type', 'commentable_id', 'source', 'created_at'], 'comments_activity_source_index');
            });
        }

        Schema::table('comments', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->change();
        });

        DB::table('comments')->whereNull('source')->whereNotNull('user_id')->update(['source' => 'human']);
        DB::table('comments')->whereNull('source')->whereNull('user_id')->update(['source' => 'system']);

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Sumber komentar untuk penanda perhatian'],
            [
                'category' => 'changed',
                'description' => 'Menetapkan sumber komentar agar penanda perhatian hanya menghitung catatan manusia.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        if (Schema::hasColumn('comments', 'source')) {
            Schema::table('comments', function (Blueprint $table): void {
                $table->dropIndex('comments_activity_source_index');
                $table->dropColumn('source');
            });
        }

        DB::table('changelogs')->whereNull('version')->where('title', 'Sumber komentar untuk penanda perhatian')->delete();
    }
};
