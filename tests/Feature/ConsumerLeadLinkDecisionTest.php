<?php

namespace Tests\Feature;

use App\Models\ConsumerApplication;
use App\Models\ConsumerLeadLinkDecision;
use App\Models\SalesLead;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ConsumerLeadLinkDecisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_br_link_decision_schema_has_audited_fields_and_active_unique_index(): void
    {
        $this->assertTrue(Schema::hasTable('consumer_lead_link_decisions'));
        $this->assertTrue(Schema::hasColumns('consumer_lead_link_decisions', [
            'consumer_application_id',
            'status',
            'sales_lead_id',
            'decision_source',
            'suggestions',
            'reason',
            'decided_by',
            'decided_at',
            'is_active',
        ]));
        $this->assertTrue(Schema::hasIndex('consumer_lead_link_decisions', 'consumer_lead_link_decisions_active_unique'));
    }

    public function test_br_link_decision_casts_and_relations_are_defined(): void
    {
        $application = ConsumerApplication::factory()->create();
        $actor = User::factory()->create();
        $lead = SalesLead::create([
            'branch_id' => $application->branch_id,
            'project_id' => $application->project_id,
            'sales_user_id' => $actor->id,
            'lead_date' => today(),
            'customer_name' => 'Lead Test',
        ]);
        $decision = ConsumerLeadLinkDecision::create([
            'consumer_application_id' => $application->id,
            'status' => ConsumerLeadLinkDecision::STATUS_LINKED,
            'sales_lead_id' => $lead->id,
            'decision_source' => 'manual',
            'suggestions' => ['lead_id' => $lead->id, 'score' => 0.92],
            'reason' => 'Konfirmasi pemilik data',
            'decided_by' => $actor->id,
            'decided_at' => now(),
            'is_active' => true,
        ]);

        $this->assertSame(['lead_id' => $lead->id, 'score' => 0.92], $decision->suggestions);
        $this->assertTrue($decision->decided_at->isToday());
        $this->assertTrue($decision->consumerApplication->is($application));
        $this->assertTrue($decision->salesLead->is($lead));
        $this->assertTrue($decision->decidedBy->is($actor));
        $this->assertTrue($application->linkDecisions->first()->is($decision));
        $this->assertTrue($lead->linkDecisions->first()->is($decision));
    }

    public function test_br_foreign_keys_reject_unknown_consumer_and_actor_references(): void
    {
        $this->expectException(QueryException::class);

        ConsumerLeadLinkDecision::create([
            'consumer_application_id' => 999999,
            'status' => ConsumerLeadLinkDecision::STATUS_WITHOUT_LEAD,
            'decision_source' => 'manual',
            'reason' => 'Belum ada Lead',
            'decided_at' => now(),
            'decided_by' => 999999,
        ]);
    }

    public function test_br_database_allows_only_one_active_decision_per_consumer(): void
    {
        $application = ConsumerApplication::factory()->create();
        $attributes = [
            'consumer_application_id' => $application->id,
            'status' => ConsumerLeadLinkDecision::STATUS_WITHOUT_LEAD,
            'decision_source' => 'manual',
            'reason' => 'Belum ada Lead',
            'decided_at' => now(),
            'is_active' => true,
        ];
        ConsumerLeadLinkDecision::create($attributes);

        $this->expectException(QueryException::class);
        ConsumerLeadLinkDecision::create($attributes);
    }

    public function test_br_inactive_decisions_preserve_audit_history_and_do_not_conflict(): void
    {
        $application = ConsumerApplication::factory()->create();
        $attributes = [
            'consumer_application_id' => $application->id,
            'status' => ConsumerLeadLinkDecision::STATUS_LEGACY,
            'decision_source' => 'migration_review',
            'reason' => 'Data lama',
            'decided_at' => now(),
            'is_active' => false,
        ];

        ConsumerLeadLinkDecision::create($attributes);
        ConsumerLeadLinkDecision::create($attributes);

        $this->assertDatabaseCount('consumer_lead_link_decisions', 2);
    }

    public function test_br_link_decision_migration_and_changelog_are_idempotent(): void
    {
        $migration = require database_path('migrations/2026_11_24_100000_create_consumer_lead_link_decisions_table.php');
        $changelogMigration = require database_path('migrations/2026_11_24_100001_add_wp29_consumer_lead_link_changelog.php');

        $migration->up();
        $migration->up();
        $changelogMigration->up();
        $changelogMigration->up();

        $this->assertTrue(Schema::hasTable('consumer_lead_link_decisions'));
        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', 'Penautan Lead dan Konsumen ter-audit')->count());

        $changelogMigration->down();
        $this->assertSame(0, DB::table('changelogs')->whereNull('version')->where('title', 'Penautan Lead dan Konsumen ter-audit')->count());
    }
}
