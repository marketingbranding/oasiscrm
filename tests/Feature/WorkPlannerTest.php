<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Services\WorkPlannerReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkPlannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_shape_defaults_to_team_task(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $item = ContentItem::create([
            'branch_id' => $branch->id,
            'title' => 'Legacy Task',
            'scheduled_date' => today(),
            'deadline_date' => today(),
            'status' => 'todo',
            'created_by' => $user->id,
        ]);

        $this->assertSame('task', $item->fresh()->item_type);
        $this->assertSame('team', $item->fresh()->visibility);
    }

    public function test_agenda_store_sets_calendar_date_and_account_assignment(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $assignee = User::factory()->create(['branch_id' => $branch->id, 'password_changed_at' => now()]);
        LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek Test', 'is_active' => true]);

        $response = $this->actingAs($user)->post(route('content-calendar.store'), [
            'item_type' => 'agenda',
            'visibility' => 'personal',
            'title' => 'Survey Lokasi',
            'task_detail' => 'Cek progres lapangan',
            'project_name' => 'Proyek Test',
            'agenda_type' => 'survey',
            'location' => 'Lokasi Proyek',
            'start_date' => '2026-07-20',
            'start_time' => '09:00',
            'deadline_date' => '2026-07-20',
            'end_time' => '11:00',
            'status' => 'planned',
            'assigned_user_ids' => [$assignee->id],
            'pic_names' => ['Vendor Foto'],
            'return_view' => 'calendar',
        ]);

        $response->assertRedirect(route('content-calendar.index', ['view' => 'calendar']));
        $item = ContentItem::where('title', 'Survey Lokasi')->firstOrFail();
        $this->assertSame('agenda', $item->item_type);
        $this->assertSame('2026-07-20', $item->scheduled_date->format('Y-m-d'));
        $this->assertSame('medium', $item->priority);
        $this->assertTrue($item->assignees->contains($assignee));
    }

    public function test_content_uses_content_fields_without_dates_or_pic(): void
    {
        [$branch, $user] = $this->branchAndUser();

        $this->actingAs($user)->post(route('content-calendar.store'), [
            'item_type' => 'content',
            'visibility' => 'team',
            'title' => 'Reels Progress',
            'tujuan_konten' => 'Edukasi',
            'platform' => 'Sosial Media',
            'content_format' => 'Video',
            'start_date' => '2026-07-22',
            'status' => 'done_editing',
            'pic_names' => ['Vendor Foto'],
        ])->assertRedirect();

        $item = ContentItem::where('title', 'Reels Progress')->firstOrFail();
        $this->assertSame('content', $item->item_type);
        $this->assertSame('2026-07-22', $item->scheduled_date->format('Y-m-d'));
        $this->assertSame('2026-07-22', $item->start_date->format('Y-m-d'));
        $this->assertNull($item->deadline_date);
        $this->assertSame('done_editing', $item->status);
        $this->assertSame('Edukasi', $item->tujuan_konten);
        $this->assertSame([], $item->pic_names);
    }

    public function test_personal_items_are_hidden_from_colleagues_but_visible_to_assignees_and_pusat(): void
    {
        [$branch, $creator] = $this->branchAndUser();
        $colleague = User::factory()->create(['branch_id' => $branch->id, 'password_changed_at' => now()]);
        $assignee = User::factory()->create(['branch_id' => $branch->id, 'password_changed_at' => now()]);
        $item = $this->makeItem($branch, $creator, ['visibility' => 'personal']);
        $item->assignees()->attach($assignee);

        $this->actingAs($colleague)->getJson(route('content-calendar.detail', $item))->assertForbidden();
        $this->actingAs($assignee)->getJson(route('content-calendar.detail', $item))->assertOk();

        $pusatRole = Role::firstOrCreate(['slug' => 'pusat'], ['name' => 'Pusat', 'is_superadmin' => false]);
        $pusat = User::factory()->create(['role_id' => $pusatRole->id, 'password_changed_at' => now()]);
        $this->actingAs($pusat)->getJson(route('content-calendar.detail', $item))->assertOk();
    }

    public function test_today_page_and_all_work_planner_views_render(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $this->makeItem($branch, $user, ['item_type' => 'task', 'title' => 'Task Hari Ini']);
        $this->makeItem($branch, $user, ['item_type' => 'agenda', 'title' => 'Agenda Hari Ini', 'status' => 'planned']);
        $this->makeItem($branch, $user, ['item_type' => 'content', 'title' => 'Konten Hari Ini', 'status' => 'idea']);

        $this->actingAs($user)->get(route('content-calendar.index'))->assertOk()
            ->assertSee('Agenda Hari Ini')->assertSee('Task Hari Ini')->assertSee('Konten Hari Ini')
            ->assertSee('Filter Work Planner')->assertSee('+ Tambah');
        foreach (['calendar', 'tasks', 'agenda', 'content', 'all'] as $view) {
            $this->actingAs($user)->get(route('content-calendar.index', ['view' => $view]))->assertOk();
        }
    }

    public function test_reminders_include_today_tomorrow_and_overdue_visible_items(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $this->makeItem($branch, $user, ['title' => 'Overdue', 'scheduled_date' => today()->subDay(), 'deadline_date' => today()->subDay()]);
        $this->makeItem($branch, $user, ['title' => 'Today']);
        $this->makeItem($branch, $user, ['title' => 'Tomorrow', 'scheduled_date' => today()->addDay(), 'deadline_date' => today()->addDay()]);

        $reminders = app(WorkPlannerReminderService::class)->forUser($user);

        $this->assertSame(['Overdue'], $reminders['overdue']->pluck('title')->all());
        $this->assertContains('Today', $reminders['today']->pluck('title')->all());
        $this->assertSame(['Tomorrow'], $reminders['tomorrow']->pluck('title')->all());
    }

    public function test_status_must_match_item_type(): void
    {
        [$branch, $user] = $this->branchAndUser();

        $this->actingAs($user)->post(route('content-calendar.store'), [
            'item_type' => 'agenda',
            'visibility' => 'team',
            'title' => 'Invalid Agenda',
            'agenda_type' => 'meeting',
            'start_date' => '2026-07-20',
            'start_time' => '09:00',
            'deadline_date' => '2026-07-20',
            'priority' => 'medium',
            'status' => 'completed',
        ])->assertSessionHasErrors('status');
    }

    public function test_dynamic_form_and_work_planner_template_render(): void
    {
        [$branch, $user] = $this->branchAndUser();

        $this->actingAs($user)->get(route('content-calendar.create', ['type' => 'content']))
            ->assertOk()
            ->assertSee('Jenis Aktivitas')
            ->assertSee('Tanggal Konten')
            ->assertSee('Format Konten')
            ->assertSee('Tujuan Konten');

        $this->actingAs($user)->get(route('content-calendar.export-template'))
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_task_and_content_tabs_discard_incompatible_filters(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $this->makeItem($branch, $user, ['title' => 'Task Tetap Muncul']);
        $this->makeItem($branch, $user, ['item_type' => 'content', 'title' => 'Konten Tetap Muncul', 'status' => 'idea']);

        $taskResponse = $this->actingAs($user)->get(route('content-calendar.index', [
            'view' => 'tasks',
            'item_type' => 'agenda',
            'status' => 'planned',
        ]));
        $taskResponse->assertOk()->assertSee('Task Tetap Muncul');
        $this->assertNull($taskResponse->viewData('selectedStatus'));
        $this->assertNull($taskResponse->viewData('selectedType'));
        $taskResponse->assertDontSee('Filter aktif:');
        $this->assertMatchesRegularExpression(
            '/<a href="'.preg_quote(route('content-calendar.index', ['view' => 'calendar']), '/').'"[^>]*>Kalender<\/a>/',
            $taskResponse->getContent()
        );

        $contentResponse = $this->actingAs($user)->get(route('content-calendar.index', [
            'view' => 'content',
            'priority' => 'urgent',
        ]));
        $contentResponse->assertOk()->assertSee('Konten Tetap Muncul');
        $this->assertNull($contentResponse->viewData('selectedPriority'));
    }

    public function test_calendar_keeps_fixed_cells_and_collapses_extra_day_items_into_modal(): void
    {
        [$branch, $user] = $this->branchAndUser();
        foreach (range(1, 5) as $number) {
            $this->makeItem($branch, $user, ['title' => "Aktivitas {$number}"]);
        }

        $response = $this->actingAs($user)->get(route('content-calendar.index', [
            'view' => 'calendar',
            'month' => today()->month,
            'year' => today()->year,
        ]));

        $response
            ->assertOk()
            ->assertSee('aspect-square', false)
            ->assertSee('+2 lainnya')
            ->assertSee('openDay(', false)
            ->assertSee('Aktivitas 5');
    }

    public function test_agenda_tab_renders_status_board(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $this->makeItem($branch, $user, [
            'item_type' => 'agenda',
            'title' => 'Meeting Cabang',
            'status' => 'planned',
            'agenda_type' => 'meeting',
            'start_date' => today(),
            'scheduled_date' => today(),
            'start_time' => '10:00',
        ]);

        $this->actingAs($user)->get(route('content-calendar.index', ['view' => 'agenda']))
            ->assertOk()
            ->assertSee('Agenda')
            ->assertSee('Meeting Cabang')
            ->assertSee('sortable-column', false)
            ->assertSee('data-status="planned"', false);
    }

    public function test_board_status_can_be_updated_for_matching_item_type(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $item = $this->makeItem($branch, $user, ['status' => 'todo']);

        $this->actingAs($user)->patchJson(route('content-calendar.update-status', $item), [
            'status' => 'completed',
            'expected_updated_at' => $item->updated_at->copy()->utc()->format('Y-m-d H:i:s'),
        ])->assertOk()->assertJsonPath('status', 'completed');

        $item->refresh();
        $this->assertSame('completed', $item->status);
        $this->assertNotNull($item->completed_at);
    }

    public function test_board_status_rejects_status_from_other_type(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $item = $this->makeItem($branch, $user, ['status' => 'todo']);

        $this->actingAs($user)->patchJson(route('content-calendar.update-status', $item), [
            'status' => 'uploaded',
            'expected_updated_at' => $item->updated_at->copy()->utc()->format('Y-m-d H:i:s'),
        ])->assertUnprocessable();
    }

    public function test_rescheduled_agenda_is_terminal_and_remains_exportable(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $agenda = $this->makeItem($branch, $user, [
            'item_type' => 'agenda',
            'status' => 'rescheduled',
            'agenda_type' => 'meeting',
            'start_date' => today(),
            'deadline_date' => today(),
            'start_time' => '09:00',
            'completed_at' => now(),
        ]);

        $this->assertTrue($agenda->isFinished());
        $this->assertNotContains($agenda->id, app(WorkPlannerReminderService::class)->forUser($user)['today']->pluck('id'));
        $this->actingAs($user)->get(route('content-calendar.index', ['view' => 'agenda']))
            ->assertOk()->assertSee('Dijadwalkan Ulang');
        $this->actingAs($user)->get(route('content-calendar.export', ['item_type' => 'agenda', 'status' => 'rescheduled']))
            ->assertOk()->assertHeader('content-disposition');
    }

    private function branchAndUser(): array
    {
        $branch = Branch::create(['name' => 'Cabang Test', 'code' => 'TEST', 'is_active' => true]);
        $user = User::factory()->create(['branch_id' => $branch->id, 'password_changed_at' => now()]);

        return [$branch, $user];
    }

    private function makeItem(Branch $branch, User $creator, array $overrides = []): ContentItem
    {
        return ContentItem::create(array_merge([
            'branch_id' => $branch->id,
            'item_type' => 'task',
            'visibility' => 'team',
            'title' => 'Planner Item',
            'scheduled_date' => today(),
            'deadline_date' => today(),
            'priority' => 'medium',
            'status' => 'todo',
            'created_by' => $creator->id,
        ], $overrides));
    }
}
