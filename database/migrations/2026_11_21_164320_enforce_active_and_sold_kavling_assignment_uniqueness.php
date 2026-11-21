<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'consumer_kavling_assignments_active_unique';

    private const CHANGELOG_TITLE = 'Kunci tunggal kavling untuk transaksi aktif dan terjual';

    public function up(): void
    {
        if (! Schema::hasTable('consumer_kavling_assignments')) {
            return;
        }

        $this->dropIndexIfExists();

        if (DB::connection()->getDriverName() === 'mysql') {
            if (Schema::hasColumn('consumer_kavling_assignments', 'active_kavling_key')) {
                Schema::table('consumer_kavling_assignments', function (Blueprint $table): void {
                    $table->dropColumn('active_kavling_key');
                });
            }

            DB::statement("ALTER TABLE consumer_kavling_assignments ADD active_kavling_key BIGINT GENERATED ALWAYS AS (IF(assignment_status IN ('active', 'sold') AND released_at IS NULL, kavling_id, NULL)) STORED");
            DB::statement('CREATE UNIQUE INDEX '.self::INDEX.' ON consumer_kavling_assignments (active_kavling_key)');
        } else {
            DB::statement('CREATE UNIQUE INDEX '.self::INDEX." ON consumer_kavling_assignments (kavling_id) WHERE assignment_status IN ('active', 'sold') AND released_at IS NULL");
        }

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::CHANGELOG_TITLE],
            [
                'description' => 'Kavling hanya dapat memiliki satu assignment aktif atau terjual yang belum dilepas, termasuk saat terjadi benturan penulisan bersamaan.',
                'category' => 'fixed',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', self::CHANGELOG_TITLE)->delete();

        if (! Schema::hasTable('consumer_kavling_assignments')) {
            return;
        }

        $this->dropIndexIfExists();

        if (DB::connection()->getDriverName() === 'mysql') {
            if (Schema::hasColumn('consumer_kavling_assignments', 'active_kavling_key')) {
                Schema::table('consumer_kavling_assignments', function (Blueprint $table): void {
                    $table->dropColumn('active_kavling_key');
                });
            }

            DB::statement("ALTER TABLE consumer_kavling_assignments ADD active_kavling_key BIGINT GENERATED ALWAYS AS (IF(assignment_status = 'active' AND released_at IS NULL, kavling_id, NULL)) STORED");
            DB::statement('CREATE UNIQUE INDEX '.self::INDEX.' ON consumer_kavling_assignments (active_kavling_key)');
        } else {
            DB::statement('CREATE UNIQUE INDEX '.self::INDEX." ON consumer_kavling_assignments (kavling_id) WHERE assignment_status = 'active' AND released_at IS NULL");
        }
    }

    private function dropIndexIfExists(): void
    {
        if (! Schema::hasIndex('consumer_kavling_assignments', self::INDEX)) {
            return;
        }

        Schema::table('consumer_kavling_assignments', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }
};
