<?php

use App\Models\ConsumerApplication;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACTIVE_SUBJECT_INDEX = 'consumer_lead_link_decisions_active_subject_unique';

    private const ACTIVE_LEAD_INDEX = 'consumer_lead_link_decisions_active_lead_unique';

    private const TITLE = 'Penautan Lead untuk subjek Sheet dan NUP';

    public function up(): void
    {
        if (! Schema::hasColumn('consumer_lead_link_decisions', 'consumer_nup_id')) {
            Schema::table('consumer_lead_link_decisions', function (Blueprint $table): void {
                $table->foreignId('consumer_nup_id')->nullable()->after('consumer_application_id')->constrained('consumer_nups')->restrictOnDelete();
                $table->foreignId('branch_id')->nullable()->after('consumer_nup_id')->constrained('branches')->restrictOnDelete();
                $table->string('legacy_consumer_key')->nullable()->after('branch_id');
                $table->foreignId('legacy_sheet_row_id')->nullable()->after('legacy_consumer_key')->constrained('konsumen_progress_sheet_rows')->restrictOnDelete();
                $table->string('subject_type', 20)->nullable()->after('legacy_sheet_row_id');
                $table->string('subject_key', 255)->nullable()->after('subject_type');
            });
        }

        if (Schema::hasIndex('consumer_lead_link_decisions', 'consumer_lead_link_decisions_active_unique')) {
            Schema::table('consumer_lead_link_decisions', function (Blueprint $table): void {
                $table->dropIndex('consumer_lead_link_decisions_active_unique');
            });
        }

        DB::table('consumer_lead_link_decisions')
            ->whereNull('subject_type')
            ->orderBy('id')
            ->get(['id', 'consumer_application_id'])
            ->each(function (object $decision): void {
                $application = ConsumerApplication::query()->find($decision->consumer_application_id);
                if ($application === null) {
                    return;
                }

                DB::table('consumer_lead_link_decisions')->where('id', $decision->id)->update([
                    'subject_type' => 'application',
                    'branch_id' => $application->branch_id,
                    'subject_key' => 'application:'.$application->id,
                ]);
            });

        if (DB::connection()->getDriverName() === 'mysql') {
            if (! Schema::hasColumn('consumer_lead_link_decisions', 'active_subject_key')) {
                DB::statement('ALTER TABLE consumer_lead_link_decisions ADD active_subject_key VARCHAR(255) GENERATED ALWAYS AS (IF(is_active = 1, subject_key, NULL)) STORED');
            }
            if (! Schema::hasColumn('consumer_lead_link_decisions', 'active_lead_key')) {
                DB::statement('ALTER TABLE consumer_lead_link_decisions ADD sales_lead_unique_source BIGINT NULL');
                DB::statement('UPDATE consumer_lead_link_decisions SET sales_lead_unique_source = sales_lead_id');
                DB::statement('CREATE TRIGGER clld_sync_lead_unique_insert BEFORE INSERT ON consumer_lead_link_decisions FOR EACH ROW SET NEW.sales_lead_unique_source = NEW.sales_lead_id');
                DB::statement('CREATE TRIGGER clld_sync_lead_unique_update BEFORE UPDATE ON consumer_lead_link_decisions FOR EACH ROW SET NEW.sales_lead_unique_source = NEW.sales_lead_id');
                DB::statement("ALTER TABLE consumer_lead_link_decisions ADD active_lead_key BIGINT GENERATED ALWAYS AS (IF(is_active = 1 AND status = 'linked', sales_lead_unique_source, NULL)) STORED");
            }
            $this->createIndexIfMissing(self::ACTIVE_SUBJECT_INDEX, 'active_subject_key');
            $this->createIndexIfMissing(self::ACTIVE_LEAD_INDEX, 'active_lead_key');
        } else {
            if (! Schema::hasColumn('consumer_lead_link_decisions', 'active_subject_key')) {
                DB::statement('ALTER TABLE consumer_lead_link_decisions ADD active_subject_key VARCHAR(255) GENERATED ALWAYS AS (CASE WHEN is_active = 1 THEN subject_key ELSE NULL END) STORED');
            }
            DB::statement('DROP INDEX IF EXISTS '.self::ACTIVE_SUBJECT_INDEX);
            DB::statement('CREATE UNIQUE INDEX '.self::ACTIVE_SUBJECT_INDEX.' ON consumer_lead_link_decisions (active_subject_key)');
            $this->createPartialIndex(self::ACTIVE_LEAD_INDEX, 'sales_lead_id', "is_active = 1 AND status = 'linked' AND sales_lead_id IS NOT NULL");
            // Application uniqueness is rebuilt after consumer_application_id becomes nullable.
        }

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'added',
                'description' => 'Penautan Lead mendukung konsumen cermin Sheet dan NUP OASIS tanpa menulis workbook.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', self::TITLE)->delete();

        foreach ([self::ACTIVE_SUBJECT_INDEX, self::ACTIVE_LEAD_INDEX] as $index) {
            if (Schema::hasIndex('consumer_lead_link_decisions', $index)) {
                Schema::table('consumer_lead_link_decisions', function (Blueprint $table) use ($index): void {
                    $table->dropIndex($index);
                });
            }
        }

        Schema::table('consumer_lead_link_decisions', function (Blueprint $table): void {
            $table->dropForeign(['consumer_nup_id']);
            $table->dropForeign(['branch_id']);
            $table->dropForeign(['legacy_sheet_row_id']);
            $table->dropColumn(['consumer_nup_id', 'branch_id', 'legacy_consumer_key', 'legacy_sheet_row_id', 'subject_type', 'subject_key']);
        });
    }

    private function createIndexIfMissing(string $name, string $column): void
    {
        if (! Schema::hasIndex('consumer_lead_link_decisions', $name)) {
            DB::statement("CREATE UNIQUE INDEX {$name} ON consumer_lead_link_decisions ({$column})");
        }
    }

    private function createPartialIndex(string $name, string $column, string $condition = 'is_active = 1'): void
    {
        if (! Schema::hasIndex('consumer_lead_link_decisions', $name)) {
            DB::statement("CREATE UNIQUE INDEX {$name} ON consumer_lead_link_decisions ({$column}) WHERE {$condition}");
        }
    }
};
