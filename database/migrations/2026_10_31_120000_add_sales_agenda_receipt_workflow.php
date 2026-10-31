<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TITLE = 'Buku Saku Sales Menggunakan Struk Agenda Harian';

    public function up(): void
    {
        Schema::create('sales_agenda_day_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->date('scheduled_date');
            $table->unsignedSmallInteger('target_minutes');
            $table->unsignedSmallInteger('normal_minutes_at_submission');
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique(['owner_user_id', 'scheduled_date'], 'sales_agenda_day_submission_owner_date_unique');
        });

        Schema::table('content_items', function (Blueprint $table): void {
            $table->boolean('is_outside_schedule')->default(false)->after('duration_minutes');
            $table->index(
                ['agenda_type', 'owner_user_id', 'scheduled_date', 'is_outside_schedule'],
                'content_items_sales_agenda_receipt_index',
            );
        });

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Buku Saku Sales kini menampilkan agenda harian sebagai struk waktu dengan target kerja 420 menit dan status pengiriman ke Koordinator.',
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
            ->where('title', self::TITLE)
            ->delete();

        Schema::table('content_items', function (Blueprint $table): void {
            $table->dropIndex('content_items_sales_agenda_receipt_index');
            $table->dropColumn('is_outside_schedule');
        });

        Schema::dropIfExists('sales_agenda_day_submissions');
    }
};
