<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'consumer_kavling_assignments_active_unique';

    private const NEW_INDEX = 'consumer_kavling_assignments_active_sold_unique';

    private const CHANGELOG_TITLE = 'Kunci tunggal kavling untuk transaksi aktif dan terjual';

    public function up(): void
    {
        if (! Schema::hasTable('consumer_kavling_assignments')) {
            return;
        }

        $duplicates = DB::table('consumer_kavling_assignments')
            ->select('kavling_id')
            ->whereIn('assignment_status', ['active', 'sold'])
            ->whereNull('released_at')
            ->groupBy('kavling_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('kavling_id')
            ->pluck('kavling_id')
            ->all();

        if ($duplicates !== []) {
            throw new RuntimeException('Migrasi dibatalkan: kavling_id memiliki lebih dari satu assignment aktif/terjual: '.implode(', ', $duplicates));
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            if (! Schema::hasColumn('consumer_kavling_assignments', 'active_sold_kavling_key')) {
                DB::statement("ALTER TABLE consumer_kavling_assignments ADD active_sold_kavling_key BIGINT GENERATED ALWAYS AS (IF(assignment_status IN ('active', 'sold') AND released_at IS NULL, kavling_id, NULL)) STORED");
            }
            if (! Schema::hasIndex('consumer_kavling_assignments', self::NEW_INDEX)) {
                DB::statement('CREATE UNIQUE INDEX '.self::NEW_INDEX.' ON consumer_kavling_assignments (active_sold_kavling_key)');
            }
            $this->dropIndexIfExists(self::INDEX);
            if (Schema::hasColumn('consumer_kavling_assignments', 'active_kavling_key')) {
                Schema::table('consumer_kavling_assignments', function (Blueprint $table): void {
                    $table->dropColumn('active_kavling_key');
                });
            }
        } else {
            if (! Schema::hasIndex('consumer_kavling_assignments', self::NEW_INDEX)) {
                DB::statement('CREATE UNIQUE INDEX '.self::NEW_INDEX." ON consumer_kavling_assignments (kavling_id) WHERE assignment_status IN ('active', 'sold') AND released_at IS NULL");
            }
            $this->dropIndexIfExists(self::INDEX);
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

        $this->dropIndexIfExists(self::NEW_INDEX);

        if (DB::connection()->getDriverName() === 'mysql') {
            if (Schema::hasColumn('consumer_kavling_assignments', 'active_sold_kavling_key')) {
                Schema::table('consumer_kavling_assignments', function (Blueprint $table): void {
                    $table->dropColumn('active_sold_kavling_key');
                });
            }

            if (! Schema::hasColumn('consumer_kavling_assignments', 'active_kavling_key')) {
                DB::statement("ALTER TABLE consumer_kavling_assignments ADD active_kavling_key BIGINT GENERATED ALWAYS AS (IF(assignment_status = 'active' AND released_at IS NULL, kavling_id, NULL)) STORED");
            }
            if (! Schema::hasIndex('consumer_kavling_assignments', self::INDEX)) {
                DB::statement('CREATE UNIQUE INDEX '.self::INDEX.' ON consumer_kavling_assignments (active_kavling_key)');
            }
        } else {
            if (! Schema::hasIndex('consumer_kavling_assignments', self::INDEX)) {
                DB::statement('CREATE UNIQUE INDEX '.self::INDEX." ON consumer_kavling_assignments (kavling_id) WHERE assignment_status = 'active' AND released_at IS NULL");
            }
        }
    }

    private function dropIndexIfExists(string $index): void
    {
        if (! Schema::hasIndex('consumer_kavling_assignments', $index)) {
            return;
        }

        Schema::table('consumer_kavling_assignments', function (Blueprint $table) use ($index): void {
            $table->dropIndex($index);
        });
    }
};
