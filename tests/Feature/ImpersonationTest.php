<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\ModuleMaintenance;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_superadmin_can_start_supported_targets_with_canonical_session_auth_and_landing(): void
    {
        foreach (['sales', 'sales_coordinator', 'admin'] as $slug) {
            $actor = $this->user('superadmin', ['remember_token' => "actor-{$slug}"]);
            $target = $this->user($slug, ['remember_token' => "target-{$slug}"]);
            $this->withSession(['probe' => $slug])->actingAs($actor);
            $beforeId = $this->app['session']->driver()->getId();

            $response = $this->post(route('admin-users.impersonate', $target));

            $response->assertRedirect(route($target->landingRouteName()))
                ->assertSessionHas('impersonation.original_user_id', $actor->id)
                ->assertSessionHas('impersonation.target_user_id', $target->id)
                ->assertSessionHas('impersonation.started_at');
            $this->assertAuthenticatedAs($target);
            $this->assertNotSame($beforeId, $this->app['session']->driver()->getId());

            $this->post(route('impersonation.stop'))->assertRedirect(route('admin-users.index'));
        }
    }

    public function test_non_superadmin_and_supplemental_superadmin_cannot_start(): void
    {
        $target = $this->user('sales');
        $supplemental = $this->user('staff');
        $supplemental->roles()->attach(Role::where('slug', 'superadmin')->firstOrFail());

        foreach ([$this->user('admin'), $supplemental] as $actor) {
            $this->actingAs($actor)->post(route('admin-users.impersonate', $target))->assertForbidden();
            $this->assertAuthenticatedAs($actor);
            $this->assertFalse(session()->has('impersonation.original_user_id'));
        }
    }

    public function test_ineligible_targets_are_denied(): void
    {
        $actor = $this->user('superadmin');
        $targets = [
            $actor,
            $this->user('superadmin'),
            $this->user('sales', ['account_status' => AccountStatus::Anonymized, 'is_active' => false, 'anonymized_at' => now()]),
            $this->user('sales', ['account_status' => AccountStatus::Inactive, 'is_active' => false]),
            $this->user('sales', ['account_status' => AccountStatus::Suspended, 'is_active' => false]),
            $this->user('sales', ['account_status' => AccountStatus::PendingInvitation, 'is_active' => false]),
            $this->user('sales', ['email_verified_at' => null]),
            $this->user('sales', ['must_change_password' => true, 'password_changed_at' => null]),
        ];

        foreach ($targets as $target) {
            $this->actingAs($actor)->post(route('admin-users.impersonate', $target))->assertForbidden();
            $this->assertAuthenticatedAs($actor);
            $this->assertFalse(session()->has('impersonation.original_user_id'));
        }
    }

    public function test_banner_contains_target_identity_branch_and_stop_form(): void
    {
        $branch = Branch::create(['name' => 'Cabang Banner', 'code' => 'BNR', 'is_active' => true]);
        $actor = $this->user('superadmin');
        $target = $this->user('admin', ['name' => 'Target Banner', 'branch_id' => $branch->id]);

        $this->actingAs($actor)->post(route('admin-users.impersonate', $target));
        $this->get(route('dashboard'))->assertOk()
            ->assertSee('Target Banner')
            ->assertSee($target->role->name)
            ->assertSee('Cabang Banner')
            ->assertSee('Kembali ke Superadmin')
            ->assertSee('action="'.route('impersonation.stop').'"', false)
            ->assertSee('method="POST"', false);
    }

    public function test_impersonated_target_is_blocked_by_module_maintenance_then_stop_restores_superadmin_bypass(): void
    {
        $actor = $this->user('superadmin');
        $target = $this->user('admin');
        ModuleMaintenance::create([
            'module_key' => 'promo',
            'is_enabled' => true,
            'message' => 'Promo maintenance',
            'started_at' => now(),
        ]);
        Cache::forget('oasis.module_maintenance.all');

        $this->actingAs($actor)->post(route('admin-users.impersonate', $target));
        $this->get(route('promos.index'))->assertServiceUnavailable();
        $this->post(route('impersonation.stop'))->assertRedirect(route('admin-users.index'));
        $this->get(route('promos.index'))->assertOk()->assertSee('MODE MAINTENANCE MODUL AKTIF — Promo');
    }

    public function test_stop_restores_original_clears_session_and_regenerates_id(): void
    {
        $actor = $this->user('superadmin');
        $target = $this->user('admin');
        $this->actingAs($actor)->post(route('admin-users.impersonate', $target));
        $startedId = $this->app['session']->driver()->getId();

        $this->post(route('impersonation.stop'))->assertRedirect(route('admin-users.index'));

        $this->assertAuthenticatedAs($actor);
        $this->assertFalse(session()->has('impersonation.original_user_id'));
        $this->assertFalse(session()->has('impersonation.target_user_id'));
        $this->assertFalse(session()->has('impersonation.started_at'));
        $this->assertNotSame($startedId, $this->app['session']->driver()->getId());
    }

    public function test_nested_start_is_forbidden_without_changing_context(): void
    {
        $actor = $this->user('superadmin');
        $target = $this->user('admin');
        $other = $this->user('sales');
        $this->actingAs($actor)->post(route('admin-users.impersonate', $target));

        $this->post(route('admin-users.impersonate', $other))->assertForbidden();

        $this->assertAuthenticatedAs($target);
        $this->assertSame($actor->id, session('impersonation.original_user_id'));
        $this->assertSame($target->id, session('impersonation.target_user_id'));
    }

    public function test_start_and_stop_do_not_change_credentials_or_identity_security_fields(): void
    {
        $actor = $this->user('superadmin', ['password' => Hash::make('actor-secret'), 'remember_token' => 'actor-remember']);
        $target = $this->user('admin', [
            'password' => Hash::make('target-secret'),
            'remember_token' => 'target-remember',
            'password_changed_at' => now()->subDay(),
            'email_verified_at' => now()->subDays(2),
        ]);
        $actorSnapshot = $actor->only(['password', 'remember_token']);
        $targetSnapshot = [
            'password' => $target->password,
            'must_change_password' => $target->must_change_password,
            'password_changed_at' => $target->password_changed_at?->toISOString(),
            'email_verified_at' => $target->email_verified_at?->toISOString(),
            'remember_token' => $target->remember_token,
        ];

        $this->actingAs($actor)->post(route('admin-users.impersonate', $target));
        $this->assertSame($actorSnapshot, $actor->fresh()->only(array_keys($actorSnapshot)));
        $this->assertSame($targetSnapshot, $this->securitySnapshot($target->fresh()));

        $this->post(route('impersonation.stop'));
        $this->assertSame($actorSnapshot, $actor->fresh()->only(array_keys($actorSnapshot)));
        $this->assertSame($targetSnapshot, $this->securitySnapshot($target->fresh()));
    }

    public function test_start_and_stop_audits_have_exact_safe_metadata_and_duration(): void
    {
        Carbon::setTestNow('2026-08-13 10:00:00');
        $branch = Branch::create(['name' => 'Audit', 'code' => 'AUD', 'is_active' => true]);
        $actor = $this->user('superadmin', ['password' => Hash::make('actor-secret')]);
        $target = $this->user('admin', ['branch_id' => $branch->id, 'password' => Hash::make('target-secret')]);

        $this->withHeader('User-Agent', 'Impersonation Test Agent')->actingAs($actor)
            ->post(route('admin-users.impersonate', $target));
        Carbon::setTestNow('2026-08-13 10:02:03');
        $this->post(route('impersonation.stop'));

        $start = ActivityLog::where('event', 'user_impersonation_started')->sole();
        $stop = ActivityLog::where('event', 'user_impersonation_stopped')->sole();
        $this->assertSame($actor->id, $start->causer_id);
        $this->assertSame(User::class, $start->subject_type);
        $this->assertSame($target->id, $start->subject_id);
        $this->assertSame([
            'original_user_id' => $actor->id,
            'target_user_id' => $target->id,
            'target_role' => 'admin',
            'target_branch_id' => $branch->id,
            'started_at' => '2026-08-13T10:00:00+07:00',
            'ip' => '127.0.0.1',
            'user_agent' => 'Impersonation Test Agent',
        ], $start->properties);
        $this->assertSame($actor->id, $stop->causer_id);
        $this->assertSame($target->id, $stop->subject_id);
        $this->assertSame([
            'original_user_id' => $actor->id,
            'target_user_id' => $target->id,
            'duration_seconds' => 123,
            'stopped_at' => '2026-08-13T10:02:03+07:00',
        ], $stop->properties);
        $serialized = $start->toJson().$stop->toJson();
        $this->assertStringNotContainsString('actor-secret', $serialized);
        $this->assertStringNotContainsString('target-secret', $serialized);
        $this->assertStringNotContainsString($actor->password, $serialized);
        $this->assertStringNotContainsString($target->password, $serialized);
        Carbon::setTestNow();
    }

    public function test_critical_mutations_are_forbidden_and_records_unchanged_while_active(): void
    {
        $branch = Branch::create(['name' => 'Protected Branch', 'code' => 'PRB', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Protected Project', 'is_active' => true]);
        $actor = $this->user('superadmin');
        $target = $this->user('admin');
        $victim = $this->user('staff', ['name' => 'Unchanged Victim']);
        $counts = [User::count(), Branch::count(), LeadMaster::count()];
        $this->actingAs($actor)->post(route('admin-users.impersonate', $target));

        $requests = [
            fn () => $this->put(route('admin-users.update', $victim), ['name' => 'Changed']),
            fn () => $this->post(route('admin-users.bulk-reset-access'), ['user_ids' => [$victim->id]]),
            fn () => $this->post(route('admin-users.store'), ['name' => 'Created']),
            fn () => $this->post(route('admin-users.import-confirm'), []),
            fn () => $this->put(route('branches.update', $branch), ['name' => 'Changed Branch']),
            fn () => $this->put(route('projects.update', $project), ['project_name' => 'Changed Project']),
        ];
        foreach ($requests as $request) {
            $request()->assertForbidden();
        }

        $this->assertSame('Unchanged Victim', $victim->fresh()->name);
        $this->assertSame('Protected Branch', $branch->fresh()->name);
        $this->assertSame('Protected Project', $project->fresh()->project_name);
        $this->assertSame($counts, [User::count(), Branch::count(), LeadMaster::count()]);
    }

    public function test_target_business_scope_rejects_foreign_branch_request(): void
    {
        $own = Branch::create(['name' => 'Own', 'code' => 'OWN', 'is_active' => true]);
        $foreign = Branch::create(['name' => 'Foreign', 'code' => 'FOR', 'is_active' => true]);
        $actor = $this->user('superadmin');
        $target = $this->user('sales', ['branch_id' => $own->id]);
        $target->branches()->updateExistingPivot($own->id, ['membership_role' => 'primary', 'can_view' => true]);
        $this->actingAs($actor)->post(route('admin-users.impersonate', $target));

        $this->get(route('sales-leads.options', $foreign))->assertForbidden();
    }

    public function test_stop_without_active_session_returns_controlled_conflict(): void
    {
        $this->actingAs($this->user('superadmin'))->post(route('impersonation.stop'))->assertConflict();
    }

    public function test_deleted_or_invalid_original_fails_closed_and_clears_session(): void
    {
        foreach (['deleted', 'invalid'] as $case) {
            $actor = $this->user('superadmin');
            $target = $this->user('admin');
            $this->actingAs($actor)->post(route('admin-users.impersonate', $target));
            if ($case === 'deleted') {
                $actor->delete();
            } else {
                $actor->forceFill(['account_status' => AccountStatus::Suspended, 'is_active' => false])->save();
            }

            $this->post(route('impersonation.stop'))->assertRedirect(route('login'));
            $this->assertGuest();
            $this->assertFalse(session()->has('impersonation.original_user_id'));
            $this->assertFalse(session()->has('impersonation.target_user_id'));
            $this->assertFalse(session()->has('impersonation.started_at'));
            $this->assertDatabaseHas('activity_log', [
                'event' => 'user_impersonation_stop_failed',
                'subject_id' => $target->id,
                'causer_id' => null,
            ]);
        }
    }

    public function test_critical_named_routes_have_expected_http_methods_and_impersonation_middleware(): void
    {
        $contracts = [
            'admin-users.update' => 'PUT',
            'admin-users.bulk-reset-access' => 'POST',
            'admin-users.store' => 'POST',
            'admin-users.import-confirm' => 'POST',
            'branches.update' => 'PUT',
            'branches.assign-store' => 'POST',
            'projects.store' => 'POST',
            'projects.update' => 'PUT',
            'projects.destroy' => 'DELETE',
            'kavlings.bulk-store' => 'POST',
            'kavlings.destroy' => 'DELETE',
            'kavlings.bulk-destroy' => 'POST',
            'promos.store' => 'POST',
            'promos.update' => 'PUT',
            'promos.toggle' => 'PATCH',
            'promos.import.preview' => 'POST',
            'promos.import.confirm' => 'POST',
        ];

        foreach ($contracts as $name => $method) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertContains($method, $route->methods(), $name);
            $this->assertContains('not.impersonating', $route->gatherMiddleware(), $name);
        }
    }

    private function securitySnapshot(User $user): array
    {
        return [
            'password' => $user->password,
            'must_change_password' => $user->must_change_password,
            'password_changed_at' => $user->password_changed_at?->toISOString(),
            'email_verified_at' => $user->email_verified_at?->toISOString(),
            'remember_token' => $user->remember_token,
        ];
    }

    private function user(string $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role_id' => Role::where('slug', $role)->firstOrFail()->id,
            'account_status' => AccountStatus::Active,
            'is_active' => true,
            'email_verified_at' => now(),
            'must_change_password' => false,
            'password_changed_at' => now(),
        ], $attributes));
    }
}
