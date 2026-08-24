<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesAgendaEvidence;
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

    public function test_sales_agenda_uploader_uses_responsive_cards_and_respects_photo_limit_and_finished_state(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Oasis Solo', 'is_active' => true]);
        $role = Role::firstOrCreate(['slug' => 'sales'], ['name' => 'Sales']);
        $sales = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $sales->assignedProjects()->attach($project->id, ['is_primary' => true, 'is_active' => true]);
        $available = ContentItem::create(['branch_id' => $branch->id, 'sales_project_id' => $project->id, 'item_type' => 'agenda', 'agenda_type' => ContentItem::SALES_AGENDA_TYPE, 'title' => 'Upload tersedia', 'scheduled_date' => now(), 'status' => 'planned', 'owner_user_id' => $sales->id, 'created_by' => $sales->id]);
        $full = ContentItem::create(['branch_id' => $branch->id, 'sales_project_id' => $project->id, 'item_type' => 'agenda', 'agenda_type' => ContentItem::SALES_AGENDA_TYPE, 'title' => 'Upload penuh', 'scheduled_date' => now(), 'status' => 'planned', 'owner_user_id' => $sales->id, 'created_by' => $sales->id]);
        $finished = ContentItem::create(['branch_id' => $branch->id, 'sales_project_id' => $project->id, 'item_type' => 'agenda', 'agenda_type' => ContentItem::SALES_AGENDA_TYPE, 'title' => 'Agenda selesai', 'scheduled_date' => now(), 'status' => 'done', 'owner_user_id' => $sales->id, 'created_by' => $sales->id]);
        foreach ([1, 2] as $index) {
            SalesAgendaEvidence::create(['content_item_id' => $full->id, 'uploaded_by_user_id' => $sales->id, 'storage_path' => "evidence/$index.webp", 'original_name' => "$index.webp", 'mime_type' => 'image/webp', 'width' => 10, 'height' => 10, 'size_bytes' => 3, 'sha256' => hash('sha256', (string) $index)]);
        }

        $response = $this->actingAs($sales)->get(route('sales-agendas.index'));

        $response->assertOk()
            ->assertSee('class="sales-agenda-list"', false)
            ->assertSee('class="sales-agenda-item"', false)
            ->assertSee('class="sales-agenda-action-grid"', false)
            ->assertSee('action="'.route('sales-agendas.evidence.store', $available).'"', false)
            ->assertSee('name="photo"', false)
            ->assertSee('accept="image/jpeg,image/png,image/webp"', false)
            ->assertSee('required', false)
            ->assertSee('Maksimal 2 foto telah terunggah.')
            ->assertDontSee('action="'.route('sales-agendas.evidence.store', $full).'"', false)
            ->assertDontSee('action="'.route('sales-agendas.evidence.store', $finished).'"', false)
            ->assertDontSee('<table class="crm-data-table">', false);
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
