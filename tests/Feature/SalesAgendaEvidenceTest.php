<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\Role;
use App\Models\User;
use App\Services\OptimisticLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SalesAgendaEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_categories_can_complete_with_zero_one_or_two_optional_photos_and_max_two_is_enforced(): void
    {
        Storage::fake('agenda_evidence');
        Storage::fake('agenda_evidence_archives');
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
        $role = Role::firstOrCreate(['slug' => 'sales'], ['name' => 'Sales']);
        $sales = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);

        foreach (ContentItem::SALES_ACTIVITY_CATEGORIES as $index => $category) {
            $agenda = ContentItem::create(['branch_id' => $branch->id, 'item_type' => 'agenda', 'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
                'title' => $category, 'sales_activity_category' => $category, 'scheduled_date' => now(), 'status' => 'planned', 'owner_user_id' => $sales->id, 'created_by' => $sales->id]);
            for ($photo = 0; $photo < $index % 3; $photo++) {
                $this->actingAs($sales)->post(route('sales-agendas.evidence.store', $agenda), ['photo' => UploadedFile::fake()->image("$photo.jpg")])->assertRedirect();
            }
            $this->actingAs($sales)->patch(route('sales-agendas.update', $agenda), ['activity_result' => 'Selesai', 'expected_updated_at' => app(OptimisticLockService::class)->token($agenda)])->assertRedirect();
        }

        $agenda = ContentItem::create(['branch_id' => $branch->id, 'item_type' => 'agenda', 'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'title' => 'Batas Foto', 'scheduled_date' => now(), 'status' => 'planned', 'owner_user_id' => $sales->id, 'created_by' => $sales->id]);
        foreach ([1, 2] as $number) {
            $this->actingAs($sales)->post(route('sales-agendas.evidence.store', $agenda), ['photo' => UploadedFile::fake()->image("extra-$number.png")])->assertRedirect();
        }
        $this->actingAs($sales)->post(route('sales-agendas.evidence.store', $agenda), ['photo' => UploadedFile::fake()->image('third.webp')])->assertSessionHasErrors('photo');
        $this->assertCount(2, $agenda->evidence);
    }

    public function test_owner_can_view_and_delete_before_done_but_not_after_done(): void
    {
        Storage::fake('agenda_evidence');
        Storage::fake('agenda_evidence_archives');
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
        $role = Role::firstOrCreate(['slug' => 'sales'], ['name' => 'Sales']);
        $sales = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $agenda = ContentItem::create(['branch_id' => $branch->id, 'item_type' => 'agenda', 'agenda_type' => ContentItem::SALES_AGENDA_TYPE, 'title' => 'Visit', 'scheduled_date' => now(), 'status' => 'planned', 'owner_user_id' => $sales->id, 'created_by' => $sales->id]);
        $this->actingAs($sales)->post(route('sales-agendas.evidence.store', $agenda), ['photo' => UploadedFile::fake()->image('proof.jpg')]);
        $evidence = $agenda->evidence()->sole();
        $this->actingAs($sales)->get(route('sales-agendas.evidence.show', [$agenda, $evidence]))->assertOk();
        $agenda->update(['status' => 'done']);
        $this->actingAs($sales)->delete(route('sales-agendas.evidence.destroy', [$agenda, $evidence]))->assertForbidden();
    }
}
