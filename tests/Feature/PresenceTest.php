<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\DanaTalangan;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPresence;
use App\Services\OptimisticLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PresenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeGoogleSheets();
    }

    public function test_authenticated_user_can_heartbeat_and_guest_cannot(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $payload = $this->payload($branch);

        $this->postJson(route('presence.heartbeat'), $payload)->assertRedirect(route('login'));
        $this->actingAs($user)->postJson(route('presence.heartbeat'), $payload)
            ->assertOk()->assertJsonPath('ok', true);
        $this->assertDatabaseHas('user_presences', ['user_id' => $user->id, 'branch_id' => $branch->id, 'page_key' => 'dashboard']);
    }

    public function test_unauthorized_branch_is_forbidden_and_secondary_membership_is_allowed(): void
    {
        [$primary, $user] = $this->branchAndUser();
        $secondary = Branch::create(['name' => 'Magelang', 'code' => 'MGL', 'is_active' => true]);
        $other = Branch::create(['name' => 'Pati', 'code' => 'PTI', 'is_active' => true]);
        $user->branches()->syncWithoutDetaching([$secondary->id => ['can_view' => true, 'can_edit' => false]]);

        $this->actingAs($user)->postJson(route('presence.heartbeat'), $this->payload($secondary))->assertOk();
        $this->actingAs($user)->postJson(route('presence.heartbeat'), $this->payload($other))->assertForbidden();
        $this->actingAs($user)->postJson(route('presence.heartbeat'), $this->payload($primary))->assertOk();
    }

    public function test_editing_mode_requires_edit_permission_and_authorized_record(): void
    {
        [, $user] = $this->branchAndUser();
        $secondary = Branch::create(['name' => 'Magelang', 'code' => 'MGL', 'is_active' => true]);
        $user->branches()->syncWithoutDetaching([$secondary->id => ['can_view' => true, 'can_edit' => false]]);
        $record = DanaTalangan::create([
            'branch_id' => $secondary->id, 'created_by' => $user->id, 'tanggal' => today(),
            'nama_konsumen' => 'Presence Record', 'status' => 'sanggup',
        ]);
        $payload = array_merge($this->payload($secondary, 'tab-edit'), [
            'record_type' => 'dana_talangan', 'record_id' => $record->id, 'mode' => 'editing',
        ]);

        $this->actingAs($user)->postJson(route('presence.heartbeat'), $payload)->assertForbidden();
        $user->branches()->updateExistingPivot($secondary->id, ['can_edit' => true]);
        $this->actingAs($user)->postJson(route('presence.heartbeat'), $payload)->assertOk();
    }

    public function test_stale_and_inactive_presence_are_excluded_and_sensitive_fields_are_not_returned(): void
    {
        [$branch, $viewer] = $this->branchAndUser();
        $active = User::factory()->create(['branch_id' => $branch->id, 'role_id' => $viewer->role_id, 'password_changed_at' => now(), 'phone' => '08123456789']);
        $inactive = User::factory()->create(['branch_id' => $branch->id, 'role_id' => $viewer->role_id, 'password_changed_at' => now(), 'is_active' => false]);
        foreach ([[$active, now()], [$inactive, now()], [$viewer, now()->subSeconds(90)]] as [$user, $seen]) {
            UserPresence::create([
                'user_id' => $user->id, 'branch_id' => $branch->id, 'page_key' => 'dashboard',
                'context_key' => 'page', 'mode' => 'viewing', 'session_key' => 'session'.$user->id, 'last_seen_at' => $seen,
            ]);
        }

        $response = $this->actingAs($viewer)->getJson(route('presence.index', ['page_key' => 'dashboard', 'branch_id' => $branch->id]))
            ->assertOk()->assertJsonCount(1, 'presences')->assertJsonPath('presences.0.display_name', $active->name);
        $json = $response->getContent();
        $this->assertStringNotContainsString($active->email, $json);
        $this->assertStringNotContainsString('08123456789', $json);
        $this->assertStringNotContainsString('session'.$active->id, $json);
    }

    public function test_duplicate_heartbeat_updates_row_multiple_tabs_are_stored_and_user_is_deduplicated(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $first = $this->payload($branch, 'tab-one');
        $this->actingAs($user)->postJson(route('presence.heartbeat'), $first)->assertOk();
        $this->actingAs($user)->postJson(route('presence.heartbeat'), array_merge($first, ['mode' => 'idle']))->assertOk();
        $this->actingAs($user)->postJson(route('presence.heartbeat'), $this->payload($branch, 'tab-two'))->assertOk();

        $this->assertSame(2, UserPresence::where('user_id', $user->id)->count());
        $this->actingAs($user)->getJson(route('presence.index', ['page_key' => 'dashboard', 'branch_id' => $branch->id]))
            ->assertOk()->assertJsonCount(1, 'presences')->assertJsonPath('presences.0.is_current_user', true);
    }

    public function test_invalid_page_is_rejected(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $this->actingAs($user)->post(route('presence.heartbeat'), array_merge($this->payload($branch), ['page_key' => '<script>']))
            ->assertRedirect()->assertSessionHasErrors('page_key');
    }

    public function test_invalid_record_type_is_rejected(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $this->actingAs($user)->post(route('presence.heartbeat'), array_merge($this->payload($branch), ['record_type' => 'user', 'record_id' => 1]))
            ->assertRedirect()->assertSessionHasErrors('record_type');
    }

    public function test_cleanup_command_deletes_only_old_rows(): void
    {
        [$branch, $user] = $this->branchAndUser();
        foreach ([now(), now()->subHours(25)] as $index => $seen) {
            UserPresence::create([
                'user_id' => $user->id, 'branch_id' => $branch->id, 'page_key' => 'dashboard',
                'context_key' => 'page', 'mode' => 'viewing', 'session_key' => 'tab'.$index, 'last_seen_at' => $seen,
            ]);
        }

        $this->artisan('oasis:presence-cleanup')->assertSuccessful();
        $this->assertSame(1, UserPresence::count());
    }

    public function test_idle_heartbeat_replaces_editing_mode(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $record = DanaTalangan::create([
            'branch_id' => $branch->id, 'created_by' => $user->id, 'tanggal' => today(),
            'nama_konsumen' => 'Idle Record', 'status' => 'sanggup',
        ]);
        $payload = array_merge($this->payload($branch), [
            'record_type' => 'dana_talangan', 'record_id' => $record->id, 'mode' => 'editing',
        ]);
        $this->actingAs($user)->postJson(route('presence.heartbeat'), $payload)->assertOk();
        $this->actingAs($user)->postJson(route('presence.heartbeat'), array_merge($payload, ['mode' => 'idle']))->assertOk();

        $this->assertDatabaseHas('user_presences', ['user_id' => $user->id, 'record_id' => $record->id, 'mode' => 'idle']);
    }

    public function test_validation_error_retains_editing_presence(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $item = ContentItem::create([
            'branch_id' => $branch->id, 'item_type' => 'task', 'visibility' => 'team', 'title' => 'Validasi',
            'deadline_date' => today(), 'scheduled_date' => today(), 'priority' => 'medium', 'status' => 'todo', 'created_by' => $user->id,
        ]);
        UserPresence::create([
            'user_id' => $user->id, 'branch_id' => $branch->id, 'page_key' => 'content-calendar',
            'record_type' => 'content_item', 'record_id' => $item->id, 'context_key' => 'content_item:'.$item->id,
            'mode' => 'editing', 'session_key' => 'validation-tab', 'last_seen_at' => now(),
        ]);

        $this->actingAs($user)->put(route('content-calendar.update', $item), [
            'expected_updated_at' => app(OptimisticLockService::class)->token($item),
        ])->assertSessionHasErrors();

        $this->assertDatabaseHas('user_presences', ['user_id' => $user->id, 'record_id' => $item->id, 'mode' => 'editing']);
    }

    public function test_initial_modules_render_presence_component_without_sensitive_fields(): void
    {
        [$branch, $user] = $this->branchAndUser();
        foreach ([
            route('dashboard', ['branch_id' => $branch->id]),
            route('database.index', ['branch_id' => $branch->id]),
            route('konsumen-progress.index', ['branch_id' => $branch->id]),
            route('dana-talangan.index', ['branch_id' => $branch->id]),
            route('content-calendar.index', ['branch_id' => $branch->id]),
        ] as $url) {
            $response = $this->actingAs($user)->get($url)->assertOk()->assertSee('crmPresence', false);
            $response->assertDontSee($user->email)->assertDontSee('session_key', false);
        }
        $source = file_get_contents(resource_path('js/presence.js'));
        $this->assertStringContainsString('sedang membuka halaman ini', $source);
        $this->assertStringContainsString('sedang melihat data ini', $source);
        $this->assertStringContainsString('sedang mengedit data ini', $source);
        $this->assertStringContainsString('60000', $source);
    }

    private function branchAndUser(): array
    {
        $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin', 'is_superadmin' => false]);
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $user = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);

        return [$branch, $user];
    }

    private function payload(Branch $branch, string $session = 'tab-main'): array
    {
        return ['page_key' => 'dashboard', 'branch_id' => $branch->id, 'mode' => 'viewing', 'session_key' => $session];
    }
}
