<?php

namespace Tests\Feature;

use Tests\TestCase;

class ConsumerProcessApplicabilitiesMigrationTest extends TestCase
{
    public function test_process_applicability_unique_index_uses_a_mysql_safe_name(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_10_02_081716_create_consumer_process_applicabilities_table.php'));

        $this->assertStringContainsString(
            "\$table->unique(['consumer_application_id', 'process_key'], 'consumer_process_app_unique');",
            $migration,
        );
    }

    public function test_consumer_warranties_indexes_use_mysql_safe_names(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_10_02_081716_create_consumer_warranties_table.php'));

        $this->assertStringContainsString(
            "\$table->index(['consumer_application_id', 'status_garansi'], 'consumer_warranties_app_status_idx');",
            $migration,
        );
        $this->assertStringContainsString(
            "\$table->index(['consumer_application_id', 'tanggal_selesai'], 'consumer_warranties_app_finish_idx');",
            $migration,
        );
        $this->assertStringContainsString(
            "\$table->index(['source', 'source_id'], 'consumer_warranties_source_idx');",
            $migration,
        );
    }
}
