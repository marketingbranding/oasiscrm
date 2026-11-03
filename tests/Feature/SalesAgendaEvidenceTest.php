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
use App\Services\SalesPocketbookOnboardingService;
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
        app(SalesPocketbookOnboardingService::class)->skip($sales);

        foreach ([0, 1, 2] as $count) {
            $startHour = 9 + $count;
            $photos = [];
            for ($index = 0; $index < $count; $index++) {
                $photos[] = UploadedFile::fake()->image("create-$count-$index.png");
            }
            $this->actingAs($sales)->post(route('sales-agendas.store'), [
                'scheduled_date' => '2026-08-24',
                'start_time' => sprintf('%02d:00', $startHour),
                'end_time' => sprintf('%02d:00', $startHour + 1),
                'sales_activity_category' => 'Cek Lokasi',
                'title' => "Agenda $count foto",
                'activity_result' => $count === 1 ? 'Selesai saat dibuat' : null,
                'photos' => $photos,
            ])->assertRedirect(route('sales-agendas.index', ['work_date' => '2026-08-24']))->assertSessionDoesntHaveErrors();
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
        app(SalesPocketbookOnboardingService::class)->skip($sales);
        $data = ['scheduled_date' => '2026-08-24', 'start_time' => '09:00', 'end_time' => '10:00', 'sales_activity_category' => 'Cek Lokasi', 'title' => 'Agenda gagal'];

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
                'start_time' => '09:00',
                'end_time' => '10:00',
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

    public function test_sales_agenda_workspace_renders_the_direct_creation_form(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Oasis Solo', 'is_active' => true]);
        $role = Role::firstOrCreate(['slug' => 'sales'], ['name' => 'Sales']);
        $sales = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $sales->assignedProjects()->attach($project->id, ['is_primary' => true, 'is_active' => true]);
        app(SalesPocketbookOnboardingService::class)->skip($sales);

        $this->actingAs($sales)->get(route('sales-agendas.index'))->assertOk()
            ->assertSee('+ Tambah rencana')
            ->assertSee('action="'.route('sales-agendas.store').'"', false)
            ->assertSee('name="scheduled_date"', false)
            ->assertDontSee('Buat Agenda Tambahan');
    }

    public function test_agenda_detail_exposes_one_upload_slot_next_to_a_preview_and_required_completion_contract(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
        $role = Role::firstOrCreate(['slug' => 'sales'], ['name' => 'Sales']);
        $sales = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $agenda = ContentItem::create([
            'branch_id' => $branch->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'title' => 'Visit',
            'scheduled_date' => now(),
            'status' => 'planned',
            'owner_user_id' => $sales->id,
            'created_by' => $sales->id,
        ]);

        $response = $this->actingAs($sales)->get(route('sales-agendas.show', $agenda));

        $response->assertOk()
            ->assertSee('sales-mobile-detail-page', false)
            ->assertSee('sales-agenda-completion-form', false)
            ->assertSeeText('Kembali')
            ->assertDontSee('‹ Rencana hari ini')
            ->assertSee('sales-agenda-back-link-icon', false)
            ->assertSee('←', false)
            ->assertSeeText('+ Foto')
            ->assertSeeText('0 dari 2')
            ->assertSeeText('Hasil aktivitas')
            ->assertSee('data-completion-form', false)
            ->assertSee('data-agenda-result', false)
            ->assertSee('data-complete-agenda', false)
            ->assertSee('disabled', false)
            ->assertSee('aria-disabled="true"', false)
            ->assertSee('sales-agenda-mobile-info', false)
            ->assertSee('sales-agenda-photo-slot', false)
            ->assertSee('data-agenda-photo-preview', false)
            ->assertSee('sales-agenda-photo-placeholder', false)
            ->assertSee('sales-agenda-sticky-action', false)
            ->assertSee('name="photo"', false)
            ->assertSee('accept="image/*"', false)
            ->assertSee('action="'.route('sales-agendas.evidence.store', $agenda).'"', false);

        $this->assertSame(1, substr_count($response->getContent(), 'data-agenda-photo-slot'));
        $this->assertSame(1, substr_count($response->getContent(), 'accept="image/*"'));
        $this->assertSame(0, substr_count($response->getContent(), 'Simpan Foto'));

        $this->actingAs($sales)
            ->from(route('sales-agendas.show', $agenda))
            ->followingRedirects()
            ->post(route('sales-agendas.evidence.store', $agenda), ['photo' => UploadedFile::fake()->createWithContent('invalid.txt', 'not an image')])
            ->assertOk()
            ->assertSeeText('Foto harus berupa JPEG, PNG, atau WebP yang valid.');
    }

    public function test_completion_button_changelog_is_deployed_once_and_visible(): void
    {
        $role = Role::firstOrCreate(['slug' => 'superadmin'], ['name' => 'Super Admin']);
        $actor = User::factory()->create([
            'role_id' => $role->id,
            'password_changed_at' => now(),
        ]);

        $title = 'Tombol penyelesaian Agenda Sales aktif setelah hasil diisi';
        $migration = require database_path('migrations/2026_11_03_103628_add_sales_agenda_completion_button_changelog.php');

        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());
        $this->actingAs($actor)
            ->get(route('changelogs.index'))
            ->assertOk()
            ->assertSeeText($title);
    }

    public function test_mobile_agenda_detail_actions_fit_and_center_within_the_viewport(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($css);
        $this->assertMatchesRegularExpression(
            '/\.sales-mobile-detail-page \{[^}]*overflow-x: clip !important;/s',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/\.sales-mobile-detail-page #crm-main \{[^}]*overflow: visible !important;/s',
            $css,
        );
        $this->assertStringNotContainsString(
            '.sales-mobile-detail-page .sales-pocketbook-workspace > nav span { display: none !important; }',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/\.sales-mobile-detail-page \.sales-agenda-back-link \{[^}]*border: 2px solid #141210 !important;[^}]*display: inline-flex !important;/s',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/\.sales-mobile-detail-page \.sales-agenda-sticky-action \{[^}]*position: fixed !important;[^}]*inset-inline: 0 !important;[^}]*bottom: 0 !important;[^}]*justify-content: center !important;/s',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/\.sales-mobile-detail-page \.sales-agenda-sticky-action \.crm-button \{[^}]*max-width: 480px !important;/s',
            $css,
        );
    }

    public function test_embedded_mobile_agenda_detail_keeps_completion_action_fixed_at_narrow_tablet_widths(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($css);
        $this->assertStringContainsString('@media (max-width: 1024px)', $css);
        $this->assertMatchesRegularExpression(
            '/body\.sales-mobile-detail-page \{[^}]*overflow-x: clip !important;/s',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/body\.sales-mobile-detail-page #crm-main \{[^}]*overflow: visible !important;/s',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/body\.sales-mobile-detail-page \.sales-agenda-sticky-action \{[^}]*position: fixed !important;/s',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/body\.sales-mobile-detail-page \.sales-agenda-sticky-action \{[^}]*inset: auto 0 0 !important;[^}]*width: 100vw !important;[^}]*transform: none !important;/s',
            $css,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/body\.sales-mobile-detail-page \.sales-agenda-sticky-action \{[^}]*left: 50vw !important;[^}]*width: min\(480px, calc\(100vw - 40px\)\) !important;[^}]*transform: translateX\(-50%\) !important;/s',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/body\.sales-mobile-detail-page \.crm-feedback-bubble \{[^}]*display: none !important;/s',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/body\.sales-mobile-detail-page \.sales-agenda-detail form\.sales-agenda-completion-form \{[^}]*padding-bottom: 132px !important;/s',
            $css,
        );
    }

    public function test_agenda_detail_places_existing_photo_left_and_upload_slot_right(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
        $role = Role::firstOrCreate(['slug' => 'sales'], ['name' => 'Sales']);
        $sales = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $agenda = ContentItem::create([
            'branch_id' => $branch->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'title' => 'Visit dengan foto',
            'scheduled_date' => now(),
            'status' => 'planned',
            'owner_user_id' => $sales->id,
            'created_by' => $sales->id,
        ]);
        $path = 'sales-agenda-evidence/preview.webp';
        SalesAgendaEvidence::create([
            'content_item_id' => $agenda->id,
            'uploaded_by_user_id' => $sales->id,
            'storage_path' => $path,
            'original_name' => 'preview.jpg',
            'mime_type' => 'image/webp',
            'width' => 320,
            'height' => 180,
            'size_bytes' => 5,
            'sha256' => hash('sha256', 'photo'),
        ]);

        $content = $this->actingAs($sales)
            ->get(route('sales-agendas.show', $agenda))
            ->assertOk()
            ->assertSee('sales-agenda-photo-card', false)
            ->assertSee('sales-agenda-photo-slot', false)
            ->assertSee('sales-agenda-photo-picker', false)
            ->getContent();

        $this->assertSame(1, substr_count($content, 'data-agenda-photo-slot'));
        $this->assertSame(1, substr_count($content, 'data-agenda-photo-preview'));
    }

    public function test_create_photo_changelog_is_unique_and_rendered(): void
    {
        $title = 'Foto Agenda Sales Dapat Ditambahkan Saat Membuat Agenda';
        $role = Role::firstOrCreate(['slug' => 'superadmin'], ['name' => 'Super Admin', 'is_superadmin' => true]);
        $superadmin = User::factory()->create(['role_id' => $role->id, 'password_changed_at' => now()]);

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());
        $this->actingAs($superadmin)->get(route('changelogs.index'))->assertOk()->assertSeeText($title);
    }

    public function test_camera_and_upload_changelog_is_unique_and_rendered(): void
    {
        $title = 'Agenda Sales Menyediakan Kamera dan Upload Foto';
        $role = Role::firstOrCreate(['slug' => 'superadmin'], ['name' => 'Super Admin', 'is_superadmin' => true]);
        $superadmin = User::factory()->create(['role_id' => $role->id, 'password_changed_at' => now()]);

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());
        $this->actingAs($superadmin)->get(route('changelogs.index'))->assertOk()->assertSeeText($title);
    }

    public function test_mobile_agenda_detail_photo_layout_changelog_is_unique_and_rendered(): void
    {
        $title = 'Detail Agenda Sales menyederhanakan bukti foto mobile';
        $migration = require database_path('migrations/2026_11_02_150000_add_sales_agenda_mobile_photo_layout_changelog.php');

        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());

        $role = Role::firstOrCreate(['slug' => 'superadmin'], ['name' => 'Super Admin', 'is_superadmin' => true]);
        $superadmin = User::factory()->create(['role_id' => $role->id, 'password_changed_at' => now()]);

        $this->actingAs($superadmin)->get(route('changelogs.index'))->assertOk()->assertSeeText($title);
    }

    public function test_mobile_agenda_detail_controls_changelog_is_unique_and_rendered(): void
    {
        $title = 'Kontrol Detail Agenda mengikuti viewport mobile';
        $migration = require database_path('migrations/2026_11_02_160000_add_sales_agenda_mobile_controls_changelog.php');

        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());

        $role = Role::firstOrCreate(['slug' => 'superadmin'], ['name' => 'Super Admin', 'is_superadmin' => true]);
        $superadmin = User::factory()->create(['role_id' => $role->id, 'password_changed_at' => now()]);

        $this->actingAs($superadmin)->get(route('changelogs.index'))->assertOk()->assertSeeText($title);
    }

    public function test_mobile_agenda_detail_control_visibility_changelog_is_unique_and_rendered(): void
    {
        $title = 'Kontrol Detail Agenda menampilkan kembali teks dan sticky action';
        $migration = require database_path('migrations/2026_11_02_170000_add_sales_agenda_mobile_control_visibility_changelog.php');

        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());

        $role = Role::firstOrCreate(['slug' => 'superadmin'], ['name' => 'Super Admin', 'is_superadmin' => true]);
        $superadmin = User::factory()->create(['role_id' => $role->id, 'password_changed_at' => now()]);

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

    public function test_agenda_records_and_evidence_are_preserved_in_the_direct_workspace(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Oasis Solo', 'is_active' => true]);
        $role = Role::firstOrCreate(['slug' => 'sales'], ['name' => 'Sales']);
        $sales = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $sales->assignedProjects()->attach($project->id, ['is_primary' => true, 'is_active' => true]);
        app(SalesPocketbookOnboardingService::class)->skip($sales);
        $available = ContentItem::create(['branch_id' => $branch->id, 'sales_project_id' => $project->id, 'item_type' => 'agenda', 'agenda_type' => ContentItem::SALES_AGENDA_TYPE, 'title' => 'Upload tersedia', 'scheduled_date' => now(), 'status' => 'planned', 'owner_user_id' => $sales->id, 'created_by' => $sales->id]);
        $full = ContentItem::create(['branch_id' => $branch->id, 'sales_project_id' => $project->id, 'item_type' => 'agenda', 'agenda_type' => ContentItem::SALES_AGENDA_TYPE, 'title' => 'Upload penuh', 'scheduled_date' => now(), 'status' => 'planned', 'owner_user_id' => $sales->id, 'created_by' => $sales->id]);
        $finished = ContentItem::create(['branch_id' => $branch->id, 'sales_project_id' => $project->id, 'item_type' => 'agenda', 'agenda_type' => ContentItem::SALES_AGENDA_TYPE, 'title' => 'Agenda selesai', 'scheduled_date' => now(), 'status' => 'done', 'owner_user_id' => $sales->id, 'created_by' => $sales->id]);
        foreach ([1, 2] as $index) {
            SalesAgendaEvidence::create(['content_item_id' => $full->id, 'uploaded_by_user_id' => $sales->id, 'storage_path' => "evidence/$index.webp", 'original_name' => "$index.webp", 'mime_type' => 'image/webp', 'width' => 10, 'height' => 10, 'size_bytes' => 3, 'sha256' => hash('sha256', (string) $index)]);
        }

        $response = $this->actingAs($sales)->get(route('sales-agendas.index'));

        $response->assertOk()
            ->assertSee($available->title)
            ->assertSee($full->title)
            ->assertSee($finished->title)
            ->assertSee(route('sales-agendas.show', $full), false)
            ->assertSee('sales-receipt-paper', false)
            ->assertDontSee('Riwayat Agenda Tambahan');

        $this->assertModelExists($available);
        $this->assertModelExists($full);
        $this->assertModelExists($finished);
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
        $this->actingAs($sales)->get(route('sales-agendas.show', $agenda))->assertOk()->assertSee('proof.jpg');
        $agenda->update(['status' => 'done']);
        $this->actingAs($sales)->delete(route('sales-agendas.evidence.destroy', [$agenda, $evidence]))->assertForbidden();
    }

    public function test_agenda_detail_renders_empty_photo_slots_and_the_next_owned_open_agenda(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
        $role = Role::firstOrCreate(['slug' => 'sales'], ['name' => 'Sales']);
        $sales = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $otherSales = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $agenda = ContentItem::create([
            'branch_id' => $branch->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'title' => 'Agenda Saat Ini',
            'scheduled_date' => '2026-11-02',
            'start_time' => '10:00:00',
            'status' => 'planned',
            'owner_user_id' => $sales->id,
            'created_by' => $sales->id,
        ]);
        ContentItem::create([
            'branch_id' => $branch->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'title' => 'Agenda Pemilik Lain',
            'scheduled_date' => '2026-11-02',
            'start_time' => '10:30:00',
            'status' => 'planned',
            'owner_user_id' => $otherSales->id,
            'created_by' => $otherSales->id,
        ]);
        ContentItem::create([
            'branch_id' => $branch->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'title' => 'Agenda Selesai Lebih Dulu',
            'scheduled_date' => '2026-11-02',
            'start_time' => '11:00:00',
            'status' => 'done',
            'owner_user_id' => $sales->id,
            'created_by' => $sales->id,
        ]);
        ContentItem::create([
            'branch_id' => $branch->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'title' => 'Tindak Lanjut Konsumen',
            'scheduled_date' => '2026-11-02',
            'start_time' => '11:30:00',
            'status' => 'planned',
            'owner_user_id' => $sales->id,
            'created_by' => $sales->id,
        ]);

        $response = $this->actingAs($sales)->get(route('sales-agendas.show', $agenda));

        $response->assertOk()
            ->assertSeeText('0 dari 2')
            ->assertSeeText('+ Foto')
            ->assertSeeText('Selanjutnya: 11:30 Tindak Lanjut Konsumen')
            ->assertSeeText('Preview foto')
            ->assertDontSeeText('Agenda Pemilik Lain')
            ->assertSee('sales-mobile-detail-page', false)
            ->assertSee('accept="image/*"', false)
            ->assertDontSee('capture="environment"', false);
        $this->assertSame(1, substr_count($response->getContent(), 'data-agenda-photo-slot'));
    }

    public function test_agenda_detail_hides_next_line_without_a_later_owned_open_agenda(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
        $role = Role::firstOrCreate(['slug' => 'sales'], ['name' => 'Sales']);
        $sales = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $agenda = ContentItem::create([
            'branch_id' => $branch->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'title' => 'Agenda Terakhir',
            'scheduled_date' => '2026-11-02',
            'start_time' => '10:00:00',
            'status' => 'planned',
            'owner_user_id' => $sales->id,
            'created_by' => $sales->id,
        ]);

        $this->actingAs($sales)->get(route('sales-agendas.show', $agenda))
            ->assertOk()
            ->assertDontSeeText('Selanjutnya:');
    }

    public function test_agenda_detail_shows_saved_photo_and_one_remaining_upload_slot(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
        $role = Role::firstOrCreate(['slug' => 'sales'], ['name' => 'Sales']);
        $sales = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $agenda = ContentItem::create([
            'branch_id' => $branch->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'title' => 'Agenda dengan Bukti',
            'scheduled_date' => '2026-11-02',
            'start_time' => '10:00:00',
            'status' => 'planned',
            'owner_user_id' => $sales->id,
            'created_by' => $sales->id,
        ]);
        SalesAgendaEvidence::create([
            'content_item_id' => $agenda->id,
            'uploaded_by_user_id' => $sales->id,
            'storage_path' => 'sales-agenda-evidence/proof.webp',
            'original_name' => 'foto-konsumen.webp',
            'mime_type' => 'image/webp',
            'width' => 10,
            'height' => 10,
            'size_bytes' => 12,
            'sha256' => hash('sha256', 'foto'),
        ]);

        $response = $this->actingAs($sales)->get(route('sales-agendas.show', $agenda));

        $response->assertOk()
            ->assertSeeText('1 dari 2')
            ->assertSeeText('foto-konsumen.webp')
            ->assertDontSeeText('Preview foto');
        $this->assertSame(1, substr_count($response->getContent(), 'data-agenda-photo-slot'));
    }

    public function test_agenda_detail_mobile_bar_spans_viewport_and_excludes_legacy_shadow(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($css);
        $this->assertMatchesRegularExpression(
            '/\.sales-mobile-detail-page \.sales-agenda-sticky-action \{[^}]*position: fixed !important;[^}]*inset-inline: 0 !important;[^}]*bottom: 0 !important;[^}]*env\(safe-area-inset-bottom\)[^}]*;/s',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/\.sales-mobile-detail-page \.sales-agenda-sticky-action \.crm-button \{[^}]*box-shadow: none !important;/s',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/\.sales-mobile-detail-page \.sales-agenda-photo-picker \{[^}]*min-height: 110px !important;[^}]*place-content: center/s',
            $css,
        );
    }

    public function test_mobile_agenda_detail_changelog_is_created_once_and_visible_to_superadmin(): void
    {
        $title = 'Detail Agenda Sales Menampilkan Slot Foto dan Agenda Berikutnya';
        $role = Role::firstOrCreate(['slug' => 'superadmin'], ['name' => 'Super Admin', 'is_superadmin' => true]);
        $superadmin = User::factory()->create(['role_id' => $role->id, 'password_changed_at' => now()]);

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());
        $this->actingAs($superadmin)->get(route('changelogs.index'))
            ->assertOk()
            ->assertSeeText($title);
    }
}
