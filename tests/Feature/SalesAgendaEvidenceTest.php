<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesAgendaEvidence;
use App\Models\User;
use App\Services\OptimisticLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SalesAgendaEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_can_create_agenda_with_zero_one_or_two_photos_and_initial_result(): void
    {
        Storage::fake('agenda_evidence');
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Oasis Solo', 'is_active' => true]);
        $role = Role::firstOrCreate(['slug' => 'sales'], ['name' => 'Sales']);
        $sales = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $sales->assignedProjects()->attach($project->id, ['is_primary' => true, 'is_active' => true]);

        foreach ([0, 1, 2] as $count) {
            $photos = [];
            for ($index = 0; $index < $count; $index++) {
                $photos[] = UploadedFile::fake()->image("create-$count-$index.png");
            }
            $this->actingAs($sales)->post(route('sales-agendas.store'), [
                'scheduled_date' => '2026-08-24',
                'sales_activity_category' => 'Cek Lokasi',
                'title' => "Agenda $count foto",
                'activity_result' => $count === 1 ? 'Selesai saat dibuat' : null,
                'photos' => $photos,
            ])->assertRedirect(route('sales-agendas.index'))->assertSessionDoesntHaveErrors();
        }

        $agendas = ContentItem::query()->orderBy('id')->get();
        $this->assertSame([0, 1, 2], $agendas->map(fn (ContentItem $agenda) => $agenda->evidence()->count())->all());
        $this->assertSame('done', $agendas[1]->status);
        $this->assertNotNull($agendas[1]->completed_at);
        $this->assertCount(3, Storage::disk('agenda_evidence')->allFiles());
        $this->assertSame(3, ActivityLog::where('event', 'agenda_evidence_uploaded')->count());
        $this->assertSame(1, ActivityLog::where('event', 'agenda_result_recorded')->where('subject_id', $agendas[1]->id)->count());
        $audit = ActivityLog::where('event', 'agenda_evidence_uploaded')->firstOrFail();
        $this->assertSame('Bukti foto Agenda Sales diunggah.', $audit->description);
        $this->assertSame($sales->id, $audit->causer_id);
        $this->assertSame(SalesAgendaEvidence::class, $audit->subject_type);
    }

    public function test_create_rejects_three_photos_and_invalid_second_without_agenda_or_orphan(): void
    {
        Storage::fake('agenda_evidence');
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Oasis Solo', 'is_active' => true]);
        $role = Role::firstOrCreate(['slug' => 'sales'], ['name' => 'Sales']);
        $sales = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $sales->assignedProjects()->attach($project->id, ['is_primary' => true, 'is_active' => true]);
        $data = ['scheduled_date' => '2026-08-24', 'sales_activity_category' => 'Cek Lokasi', 'title' => 'Agenda gagal'];

        $this->actingAs($sales)->post(route('sales-agendas.store'), $data + ['photos' => [
            UploadedFile::fake()->image('one.jpg'),
            UploadedFile::fake()->image('two.jpg'),
            UploadedFile::fake()->image('three.jpg'),
        ]])->assertSessionHasErrors('photos');
        $this->assertDatabaseCount('content_items', 0);
        $this->assertSame([], Storage::disk('agenda_evidence')->allFiles());

        $this->actingAs($sales)->post(route('sales-agendas.store'), $data + ['photos' => [
            UploadedFile::fake()->image('valid.jpg'),
            UploadedFile::fake()->createWithContent('invalid.png', 'not an image'),
        ]])->assertSessionHasErrors('photos.1');
        $this->assertDatabaseCount('content_items', 0);
        $this->assertSame([], Storage::disk('agenda_evidence')->allFiles());
    }

    public function test_create_cleans_prepared_photo_when_database_audit_fails(): void
    {
        Storage::fake('agenda_evidence');
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Oasis Solo', 'is_active' => true]);
        $role = Role::firstOrCreate(['slug' => 'sales'], ['name' => 'Sales']);
        $sales = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $sales->assignedProjects()->attach($project->id, ['is_primary' => true, 'is_active' => true]);
        DB::statement("CREATE TRIGGER fail_agenda_evidence_audit BEFORE INSERT ON activity_log WHEN NEW.event = 'agenda_evidence_uploaded' BEGIN SELECT RAISE(FAIL, 'forced audit failure'); END");
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($sales)->post(route('sales-agendas.store'), [
                'scheduled_date' => '2026-08-24',
                'sales_activity_category' => 'Cek Lokasi',
                'title' => 'Agenda gagal audit',
                'photos' => [UploadedFile::fake()->image('audit.jpg')],
            ]);
            $this->fail('Audit failure should abort agenda creation.');
        } catch (\Throwable) {
            $this->assertDatabaseCount('content_items', 0);
            $this->assertDatabaseCount('sales_agenda_evidences', 0);
            $this->assertSame([], Storage::disk('agenda_evidence')->allFiles());
        }
    }

    public function test_non_sales_photo_create_is_denied_before_file_storage(): void
    {
        Storage::fake('agenda_evidence');
        $role = Role::firstOrCreate(['slug' => 'manager'], ['name' => 'Manager']);
        $manager = User::factory()->create(['role_id' => $role->id, 'password_changed_at' => now()]);

        $this->actingAs($manager)->post(route('sales-agendas.store'), [
            'scheduled_date' => '2026-08-24',
            'sales_activity_category' => 'Cek Lokasi',
            'title' => 'Agenda terlarang',
            'photos' => [UploadedFile::fake()->image('forbidden.jpg')],
        ])->assertForbidden();

        $this->assertDatabaseCount('content_items', 0);
        $this->assertSame([], Storage::disk('agenda_evidence')->allFiles());
    }

    public function test_creation_form_exposes_mobile_safe_multi_photo_contract(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Oasis Solo', 'is_active' => true]);
        $role = Role::firstOrCreate(['slug' => 'sales'], ['name' => 'Sales']);
        $sales = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $sales->assignedProjects()->attach($project->id, ['is_primary' => true, 'is_active' => true]);

        $this->actingAs($sales)->get(route('sales-agendas.index'))->assertOk()
            ->assertSee('method="POST" enctype="multipart/form-data" action="'.route('sales-agendas.store').'"', false)
            ->assertSee('name="photos[]"', false)
            ->assertSee('accept="image/jpeg,image/png,image/webp"', false)
            ->assertSee('multiple', false)
            ->assertSee('Maksimal 2 foto JPEG, PNG, atau WebP; masing-masing maksimal 10 MB.');

        $view = file_get_contents(resource_path('views/crm/sales-pocketbook/sales-agenda.blade.php'));
        $this->assertStringContainsString("\$errors->first('photos') ?: \$errors->first('photos.*')", $view);
    }

    public function test_create_photo_changelog_is_unique_and_rendered(): void
    {
        $title = 'Foto Agenda Sales Dapat Ditambahkan Saat Membuat Agenda';
        $role = Role::firstOrCreate(['slug' => 'superadmin'], ['name' => 'Super Admin', 'is_superadmin' => true]);
        $superadmin = User::factory()->create(['role_id' => $role->id, 'password_changed_at' => now()]);

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());
        $this->actingAs($superadmin)->get(route('changelogs.index'))->assertOk()->assertSeeText($title);
    }

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
