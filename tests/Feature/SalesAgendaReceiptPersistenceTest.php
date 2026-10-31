<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\SalesAgendaDaySubmission;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesAgendaReceiptPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_submit_one_receipt_day(): void
    {
        $owner = User::factory()->create();

        $submission = SalesAgendaDaySubmission::create([
            'owner_user_id' => $owner->id,
            'scheduled_date' => '2026-10-31',
            'target_minutes' => 420,
            'normal_minutes_at_submission' => 420,
            'submitted_at' => now(),
        ]);

        $this->assertDatabaseHas('sales_agenda_day_submissions', [
            'id' => $submission->id,
            'owner_user_id' => $owner->id,
            'target_minutes' => 420,
            'normal_minutes_at_submission' => 420,
        ]);
        $this->assertSame('2026-10-31', $submission->fresh()->scheduled_date->toDateString());
    }

    public function test_owner_date_pair_is_unique(): void
    {
        $owner = User::factory()->create();
        $attributes = [
            'owner_user_id' => $owner->id,
            'scheduled_date' => '2026-10-31',
            'target_minutes' => 420,
            'normal_minutes_at_submission' => 420,
            'submitted_at' => now(),
        ];

        SalesAgendaDaySubmission::create($attributes);

        $this->expectException(QueryException::class);

        SalesAgendaDaySubmission::create($attributes);
    }

    public function test_existing_agendas_default_to_normal_schedule(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
        $owner = User::factory()->create(['branch_id' => $branch->id]);
        $agenda = ContentItem::create([
            'branch_id' => $branch->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'title' => 'Agenda lama',
            'scheduled_date' => '2026-10-31',
            'status' => 'planned',
            'owner_user_id' => $owner->id,
            'created_by' => $owner->id,
        ]);

        $this->assertFalse($agenda->fresh()->is_outside_schedule);
    }
}
