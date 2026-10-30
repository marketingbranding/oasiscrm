<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesAgendaTask;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\OptimisticLockService;
use App\Services\SalesLeadCompletionService;
use App\Services\SalesPocketbookOnboardingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesPocketbookMobileContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_lead_completion_is_separate_from_funnel_status_and_indexed(): void
    {
        [$branch, $project, $sales] = $this->salesContext();

        $lead = SalesLead::create([
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => '2026-10-28',
            'customer_name' => 'Lead Lama',
            'current_status' => 'discussion',
            'created_by' => $sales->id,
        ]);

        $indexes = collect(Schema::getIndexes('sales_leads'));

        $this->assertTrue(Schema::hasColumn('sales_leads', 'completed_at'));
        $this->assertTrue(Schema::hasColumn('sales_leads', 'completed_by_user_id'));
        $this->assertTrue($indexes->contains(fn (array $index): bool => $index['columns'] === ['sales_user_id', 'completed_at']));
        $this->assertNull($lead->completed_at);
        $this->assertSame('discussion', $lead->fresh()->current_status->value);
    }

    public function test_sales_agenda_tasks_keep_a_foreign_key_to_the_agenda_and_are_exposed_by_the_relation(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $agenda = ContentItem::create([
            'branch_id' => $branch->id,
            'project_name' => $project->project_name,
            'sales_project_id' => $project->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'visibility' => 'personal',
            'title' => 'Agenda dengan checklist',
            'scheduled_date' => '2026-10-28',
            'status' => 'planned',
            'owner_user_id' => $sales->id,
            'created_by' => $sales->id,
        ]);

        $task = SalesAgendaTask::create([
            'content_item_id' => $agenda->id,
            'title' => 'Kirim follow-up',
            'is_completed' => false,
            'sort_order' => 1,
            'created_by' => $sales->id,
        ]);

        $this->assertSame($agenda->id, $task->agenda->id);
        $this->assertTrue($agenda->fresh()->tasks->contains($task));
        $this->assertDatabaseHas('sales_agenda_tasks', [
            'id' => $task->id,
            'content_item_id' => $agenda->id,
            'is_completed' => false,
        ]);
    }

    public function test_sales_lead_completion_is_idempotent_without_changing_funnel_status(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $lead = SalesLead::create([
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => '2026-10-28',
            'customer_name' => 'Lead Selesai',
            'current_status' => 'discussion',
            'created_by' => $sales->id,
        ]);
        $service = app(SalesLeadCompletionService::class);

        $first = $service->complete($lead, $sales);
        $second = $service->complete($lead->fresh(), $sales);

        $this->assertNotNull($first->completed_at);
        $this->assertSame($first->completed_at->toDateTimeString(), $second->completed_at->toDateTimeString());
        $this->assertSame('discussion', $second->current_status->value);
        $this->assertDatabaseHas('sales_leads', [
            'id' => $lead->id,
            'completed_by_user_id' => $sales->id,
        ]);
        $this->assertDatabaseCount('activity_log', 1);
    }

    public function test_sales_pocketbook_onboarding_skip_is_idempotent_per_user_flow_and_version(): void
    {
        $user = User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'sales'], ['name' => 'Sales'])->id,
            'password_changed_at' => now(),
        ]);
        $service = app(SalesPocketbookOnboardingService::class);

        $first = $service->skip($user);
        $second = $service->skip($user);

        $this->assertSame($first->id, $second->id);
        $this->assertNotNull($second->skipped_at);
        $this->assertDatabaseCount('user_onboarding_progress', 1);
        $this->assertDatabaseHas('user_onboarding_progress', [
            'user_id' => $user->id,
            'flow_key' => SalesPocketbookOnboardingService::FLOW_KEY,
            'version' => SalesPocketbookOnboardingService::VERSION,
        ]);
    }

    public function test_mobile_redesign_changelog_is_idempotent(): void
    {
        $title = 'Buku Saku Sales memiliki alur mobile dan panduan onboarding';
        $migration = require database_path('migrations/2026_10_28_223711_add_sales_pocketbook_mobile_changelog.php');

        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());
    }

    public function test_sales_agenda_stores_time_range_and_duration_without_a_daily_minutes_cap(): void
    {
        [$branch, $project, $sales] = $this->salesContext();

        $this->actingAs($sales)->post(route('sales-agendas.store'), [
            'scheduled_date' => today()->toDateString(),
            'start_time' => '09:15',
            'end_time' => '13:45',
            'sales_activity_category' => 'Cek Lokasi',
            'title' => 'Agenda panjang',
        ])->assertRedirect();

        $agenda = ContentItem::query()->where('title', 'Agenda panjang')->sole();

        $this->assertSame($branch->id, $agenda->branch_id);
        $this->assertSame('09:15', $agenda->start_time);
        $this->assertSame('13:45', $agenda->end_time);
        $this->assertSame(270, $agenda->duration_minutes);
    }

    public function test_sales_agenda_rejects_an_end_time_before_the_start_time(): void
    {
        [, , $sales] = $this->salesContext();

        $this->actingAs($sales)->post(route('sales-agendas.store'), [
            'scheduled_date' => today()->toDateString(),
            'start_time' => '14:00',
            'end_time' => '13:00',
            'sales_activity_category' => 'Cek Lokasi',
            'title' => 'Waktu tidak valid',
        ])->assertSessionHasErrors('end_time');

        $this->assertDatabaseMissing('content_items', ['title' => 'Waktu tidak valid']);
    }

    public function test_personal_agenda_exposes_yesterday_today_and_planned_contexts(): void
    {
        $today = CarbonImmutable::today();
        [, $project, $sales] = $this->salesContext();
        $this->makeAgenda($sales, $project, 'Agenda kemarin', $today->subDay());
        $this->makeAgenda($sales, $project, 'Agenda hari ini', $today);
        $this->makeAgenda($sales, $project, 'Agenda rencana', $today->addDay());

        $response = $this->actingAs($sales)->get(route('sales-agendas.index'));
        $groups = $response->assertOk()->viewData('agendaGroups');

        $this->assertSame(['yesterday', 'today', 'planned'], $groups->keys()->all());
        $this->assertSame('Agenda kemarin', $groups['yesterday']['items']->first()->title);
        $this->assertSame('Agenda hari ini', $groups['today']['items']->first()->title);
        $this->assertSame('Agenda rencana', $groups['planned']['items']->first()->title);
    }

    public function test_sales_agenda_detail_rejects_an_agenda_owned_by_another_sales(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $other = $this->sales($branch, $project, 'Sales Lain');
        $agenda = $this->makeAgenda($other, $project, 'Agenda rahasia', CarbonImmutable::today());

        $this->actingAs($sales)->get(route('sales-agendas.show', $agenda))->assertForbidden();
    }

    public function test_sales_agenda_detail_renders_with_shared_detail_composition(): void
    {
        [, $project, $sales] = $this->salesContext();
        $agenda = $this->makeAgenda($sales, $project, 'Agenda detail render', CarbonImmutable::today());

        $this->actingAs($sales)->get(route('sales-agendas.show', $agenda))
            ->assertOk()
            ->assertSee('sales-pocketbook-detail-hero', false)
            ->assertDontSee('Checklist tugas')
            ->assertDontSee('agenda-checklist', false)
            ->assertSee('Bukti foto')
            ->assertSee('Hasil aktivitas');
    }

    public function test_sales_agenda_validation_uses_understandable_indonesian_messages(): void
    {
        [, , $sales] = $this->salesContext();

        $this->actingAs($sales)
            ->post(route('sales-agendas.store'), [
                'scheduled_date' => today()->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors([
                'sales_activity_category' => 'Kategori aktivitas Sales wajib dipilih.',
                'title' => 'Judul agenda wajib diisi.',
            ]);
    }

    public function test_sales_can_create_toggle_and_delete_agenda_tasks(): void
    {
        [, $project, $sales] = $this->salesContext();
        $agenda = $this->makeAgenda($sales, $project, 'Agenda checklist', CarbonImmutable::today());

        $this->actingAs($sales)->post(route('sales-agendas.tasks.store', $agenda), [
            'title' => 'Kirim follow-up',
        ])->assertRedirect();
        $task = SalesAgendaTask::sole();

        $this->actingAs($sales)->patch(route('sales-agendas.tasks.update', [$agenda, $task]), [
            'is_completed' => true,
            'expected_updated_at' => app(OptimisticLockService::class)->token($task),
        ])->assertRedirect();
        $this->assertTrue($task->fresh()->is_completed);

        $this->actingAs($sales)->delete(route('sales-agendas.tasks.destroy', [$agenda, $task]))->assertRedirect();
        $this->assertDatabaseMissing('sales_agenda_tasks', ['id' => $task->id]);
    }

    public function test_primary_sales_can_create_a_minimal_lead_with_server_controlled_date_and_project(): void
    {
        [, $project, $sales] = $this->salesContext();
        $spoofedProject = LeadMaster::create(['branch_id' => $project->branch_id, 'project_name' => 'Proyek Spoof', 'is_active' => true]);

        $this->actingAs($sales)->post(route('sales-leads.store'), [
            'customer_name' => 'Lead Mobile',
            'phone' => '081234567890',
            'lead_date' => '2020-01-01',
            'project_id' => $spoofedProject->id,
            'operation_uuid' => (string) Str::uuid(),
        ])->assertRedirect();

        $lead = SalesLead::sole();
        $this->assertSame($project->id, $lead->project_id);
        $this->assertSame(today()->toDateString(), $lead->lead_date->toDateString());
        $this->assertSame('no_response', $lead->current_status->value);
        $this->assertNull($lead->source);
        $this->assertNull($lead->platform);
        $this->assertNull($lead->campaign_name);
    }

    public function test_primary_sales_can_complete_a_lead_without_changing_funnel_status_and_retry_is_idempotent(): void
    {
        [, $project, $sales] = $this->salesContext();
        $lead = $this->leadForMobile($sales, $project, 'Lead untuk selesai');
        $token = app(OptimisticLockService::class)->token($lead);

        $this->actingAs($sales)->patch(route('sales-leads.complete', $lead), [
            'expected_updated_at' => $token,
        ])->assertRedirect();
        $completedAt = $lead->fresh()->completed_at;

        $this->actingAs($sales)->patch(route('sales-leads.complete', $lead->fresh()), [
            'expected_updated_at' => app(OptimisticLockService::class)->token($lead->fresh()),
        ])->assertRedirect();

        $this->assertNotNull($completedAt);
        $this->assertSame($completedAt->toDateTimeString(), $lead->fresh()->completed_at->toDateTimeString());
        $this->assertSame('discussion', $lead->fresh()->current_status->value);
        $this->assertDatabaseCount('activity_log', 1);
    }

    public function test_primary_sales_lead_workspace_separates_open_and_completed_tabs(): void
    {
        [, $project, $sales] = $this->salesContext();
        $open = $this->leadForMobile($sales, $project, 'Lead Belum Selesai');
        $completed = $this->leadForMobile($sales, $project, 'Lead Sudah Selesai');
        $completed->update(['completed_at' => now(), 'completed_by_user_id' => $sales->id]);

        $this->actingAs($sales)->get(route('sales-agendas.index', ['tab' => 'leads']))
            ->assertOk()
            ->assertSee('Lead Belum Selesai')
            ->assertDontSee('Lead Sudah Selesai')
            ->assertSee('Belum selesai (1)')
            ->assertSee('Selesai (1)');

        $this->actingAs($sales)->get(route('sales-agendas.index', ['tab' => 'leads', 'lead_status' => 'completed']))
            ->assertOk()
            ->assertSee('Lead Sudah Selesai')
            ->assertDontSee('Lead Belum Selesai');
    }

    public function test_first_personal_workspace_visit_redirects_to_sales_pocketbook_onboarding(): void
    {
        [, , $sales] = $this->salesContext(false);

        $this->actingAs($sales)->get(route('sales-agendas.index'))
            ->assertRedirect(route('sales-pocketbook.onboarding.show'));
    }

    public function test_onboarding_visual_system_has_pdf_composition_and_accessibility_states(): void
    {
        $source = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('--oasis-onboarding-paper', $source);
        $this->assertStringContainsString('.sales-pocketbook-onboarding-visual--book', $source);
        $this->assertStringContainsString('.sales-pocketbook-onboarding-visual--agenda', $source);
        $this->assertStringContainsString('.sales-pocketbook-onboarding-visual--tear', $source);
        $this->assertStringContainsString('.sales-pocketbook-onboarding-visual--lead', $source);
        $this->assertStringContainsString('.sales-pocketbook-onboarding button:focus-visible', $source);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $source);
        $this->assertStringContainsString('overflow-x: clip', $source);

        $onboarding = file_get_contents(resource_path('views/crm/sales-pocketbook/onboarding.blade.php'));
        $onboardingScript = file_get_contents(resource_path('js/sales-pocketbook-onboarding.js'));

        $this->assertStringContainsString('data-onboarding-step="{{ $index + 1 }}"', $onboarding);
        $this->assertStringContainsString('data-onboarding-step="${this.step}"', $onboardingScript);
        $this->assertStringNotContainsString('<main class="sales-pocketbook-onboarding"', $onboarding);
        $this->assertStringContainsString('@if($index > 0) hidden @endif', $onboarding);
        $this->assertStringContainsString(':hidden="step <= 1"', $onboarding);
        $this->assertStringContainsString(':hidden="step !== {{ count($steps) }}"', $onboarding);
        $this->assertStringContainsString('body.sales-pocketbook-onboarding-shell > .fixed', $source);
        $this->assertStringContainsString('body.sales-pocketbook-onboarding-shell .crm-global-toasts', $source);
        $this->assertStringContainsString('body.sales-pocketbook-onboarding-shell .crm-global-conflict', $source);
        $this->assertStringContainsString('[x-data="oasisPwa()"]', $source);
    }

    public function test_onboarding_mobile_actions_remain_visible_in_the_viewport(): void
    {
        $source = file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/\.sales-pocketbook-onboarding-actions\s*\{\s*position: fixed;[^}]*bottom: 0;/s',
            $source,
        );
    }

    public function test_full_workflow_redesign_exposes_shared_screen_compositions(): void
    {
        $source = file_get_contents(resource_path('css/app.css'));

        foreach ([
            '.sales-pocketbook-workspace',
            '.sales-pocketbook-context-strip',
            '.sales-pocketbook-composer',
            '.sales-pocketbook-record',
            '.sales-pocketbook-detail-hero',
            '.sales-pocketbook-screen-section',
            '.sales-pocketbook-focus-ring',
        ] as $selector) {
            $this->assertStringContainsString($selector, $source);
        }

        $views = [
            'sales-agenda.blade.php',
            'index.blade.php',
            'coordinator-leads.blade.php',
            'sales-agenda-detail.blade.php',
            'lead-detail.blade.php',
        ];

        foreach ($views as $view) {
            $viewSource = file_get_contents(resource_path("views/crm/sales-pocketbook/$view"));

            $this->assertStringContainsString('sales-pocketbook-workspace', $viewSource, $view);
            $this->assertStringContainsString('sales-pocketbook-screen-section', $viewSource, $view);
        }

        $this->assertStringContainsString('sales-pocketbook-composer', file_get_contents(resource_path('views/crm/sales-pocketbook/sales-agenda.blade.php')));
        $this->assertStringContainsString('sales-pocketbook-composer', file_get_contents(resource_path('views/crm/sales-pocketbook/index.blade.php')));
        $this->assertStringContainsString('sales-pocketbook-detail-hero', file_get_contents(resource_path('views/crm/sales-pocketbook/sales-agenda-detail.blade.php')));
        $this->assertStringContainsString('sales-pocketbook-detail-hero', file_get_contents(resource_path('views/crm/sales-pocketbook/lead-detail.blade.php')));
    }

    public function test_full_workflow_redesign_changelog_is_idempotent_and_rendered(): void
    {
        $title = 'Buku Saku Sales memiliki tampilan operasional terpadu';
        $migration = require database_path('migrations/2026_10_29_000000_add_sales_pocketbook_full_redesign_changelog.php');

        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());

        $role = Role::firstOrCreate(['slug' => 'superadmin'], ['name' => 'Super Admin', 'is_superadmin' => true]);
        $superadmin = User::factory()->create(['role_id' => $role->id, 'password_changed_at' => now()]);

        $this->actingAs($superadmin)->get(route('changelogs.index'))->assertOk()->assertSeeText($title);
    }

    public function test_sales_pocketbook_onboarding_can_be_skipped_completed_and_replayed_idempotently(): void
    {
        [, , $sales] = $this->salesContext(false);

        $this->actingAs($sales)->get(route('sales-pocketbook.onboarding.show'))->assertOk()
            ->assertSee('Satu buku saku untuk kerjamu')
            ->assertSee('aria-label="Progress onboarding"', false)
            ->assertSee('Lewati')
            ->assertSee('sales-pocketbook-onboarding-shell', false)
            ->assertSee('sales-pocketbook-onboarding-visual--book', false)
            ->assertSee('sales-pocketbook-onboarding-visual--agenda', false)
            ->assertSee('sales-pocketbook-onboarding-visual--tear', false)
            ->assertSee('sales-pocketbook-onboarding-visual--lead', false);

        $this->actingAs($sales)->post(route('sales-pocketbook.onboarding.skip'))->assertRedirect(route('sales-agendas.index'));
        $this->actingAs($sales)->post(route('sales-pocketbook.onboarding.skip'))->assertRedirect(route('sales-agendas.index'));
        $this->assertDatabaseCount('user_onboarding_progress', 1);

        $this->actingAs($sales)->get(route('sales-agendas.index'))->assertOk();
        $this->actingAs($sales)->post(route('sales-pocketbook.onboarding.replay'))->assertRedirect(route('sales-pocketbook.onboarding.show'));
        $this->actingAs($sales)->get(route('sales-pocketbook.onboarding.show'))->assertOk();

        $this->actingAs($sales)->post(route('sales-pocketbook.onboarding.complete'), ['current_step' => 4])->assertRedirect(route('sales-agendas.index'));
        $this->actingAs($sales)->post(route('sales-pocketbook.onboarding.complete'), ['current_step' => 4])->assertRedirect(route('sales-agendas.index'));
        $this->assertDatabaseCount('user_onboarding_progress', 1);
        $this->assertNotNull(DB::table('user_onboarding_progress')->where('user_id', $sales->id)->value('completed_at'));
    }

    public function test_non_sales_user_cannot_open_personal_sales_pocketbook_onboarding(): void
    {
        [$branch] = $this->salesContext();
        $role = Role::firstOrCreate(['slug' => 'sales_coordinator'], ['name' => 'Koordinator Sales']);
        $coordinator = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);

        $this->actingAs($coordinator)->get(route('sales-pocketbook.onboarding.show'))->assertForbidden();
    }

    private function salesContext(bool $completeOnboarding = true): array
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Solo Project', 'is_active' => true]);
        $role = Role::firstOrCreate(['slug' => 'sales'], ['name' => 'Sales']);
        $sales = User::factory()->create([
            'name' => 'Solo Sales',
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
        $sales->assignedProjects()->attach($project, ['is_primary' => true, 'is_active' => true]);
        if ($completeOnboarding) {
            app(SalesPocketbookOnboardingService::class)->skip($sales);
        }

        return [$branch, $project, $sales];
    }

    private function sales(Branch $branch, LeadMaster $project, string $name): User
    {
        $role = Role::firstOrCreate(['slug' => 'sales'], ['name' => 'Sales']);
        $sales = User::factory()->create([
            'name' => $name,
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
        $sales->assignedProjects()->attach($project, ['is_primary' => false, 'is_active' => true]);

        return $sales;
    }

    private function makeAgenda(User $sales, LeadMaster $project, string $title, CarbonImmutable $date): ContentItem
    {
        return ContentItem::create([
            'branch_id' => $project->branch_id,
            'project_name' => $project->project_name,
            'sales_project_id' => $project->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'visibility' => 'personal',
            'title' => $title,
            'scheduled_date' => $date->toDateString(),
            'status' => 'planned',
            'owner_user_id' => $sales->id,
            'created_by' => $sales->id,
        ]);
    }

    private function leadForMobile(User $sales, LeadMaster $project, string $name): SalesLead
    {
        return SalesLead::create([
            'branch_id' => $project->branch_id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => today(),
            'customer_name' => $name,
            'phone' => '081234567890',
            'current_status' => 'discussion',
            'created_by' => $sales->id,
        ]);
    }
}
