<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHANGELOG_TITLE = 'Orkestrasi lifecycle konsumen dan guard BAST';

    public function up(): void
    {
        Schema::table('consumer_applications', function (Blueprint $table): void {
            $table->foreignId('replacement_application_id')
                ->nullable()
                ->after('application_status')
                ->constrained('consumer_applications')
                ->nullOnDelete();
        });

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::CHANGELOG_TITLE],
            [
                'description' => 'Menambahkan relasi Ganti Konsumen, orkestrasi bank attempt, guard kesiapan BAST, dan audit before/after untuk tindakan lifecycle kritis.',
                'category' => 'added',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')
            ->whereNull('version')
            ->where('title', self::CHANGELOG_TITLE)
            ->delete();

        Schema::table('consumer_applications', function (Blueprint $table): void {
            $table->dropForeign(['replacement_application_id']);
            $table->dropColumn('replacement_application_id');
        });
    }
};
