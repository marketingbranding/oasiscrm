<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Http\Requests\Crm\StoreSalesAgendaRequest;
use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Services\SalesAgendaProjectResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesAgendaRoleWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_prefers_active_primary_assignment_within_date_window(): void
    {
        [$sales, $primary, $fallback] = $this->context();
        $sales->assignedProjects()->attach($fallback->id, $this->assignment(false));
        $sales->assignedProjects()->attach($primary->id, $this->assignment(true));

        $this->assertTrue(app(SalesAgendaProjectResolver::class)->resolve($sales, '2026-08-10')->is($primary));
    }

    public function test_resolver_uses_only_assignment_and_leaves_multiple_non_primary_unresolved(): void
    {
        [$sales, $first, $second] = $this->context();
        $sales->assignedProjects()->attach($first->id, $this->assignment(false));
        $this->assertTrue(app(SalesAgendaProjectResolver::class)->resolve($sales, '2026-08-10')->is($first));

        $sales->assignedProjects()->attach($second->id, $this->assignment(false));
        $this->assertNull(app(SalesAgendaProjectResolver::class)->resolve($sales->fresh(), '2026-08-10'));
    }

    public function test_resolver_rejects_multiple_active_primary_assignments(): void
    {
        [$sales, $first, $second] = $this->context();
        $sales->assignedProjects()->attach($first->id, $this->assignment(true));
        $sales->assignedProjects()->attach($second->id, $this->assignment(false));
        DB::table('project_user')->where('user_id', $sales->id)->update(['is_primary' => true]);

        $this->assertNull(app(SalesAgendaProjectResolver::class)->resolve($sales, '2026-08-10'));
        $this->actingAs($sales)->get(route('sales-pocketbook.index'))->assertOk()
            ->assertSee('Proyek utama belum ditentukan. Hubungi admin untuk menetapkan proyek utama.')
            ->assertDontSee('name="scheduled_date"', false);
    }

    public function test_resolver_ignores_inactive_future_and_expired_assignments(): void
    {
        [$sales, $inactive, $future] = $this->context();
        $sales->assignedProjects()->attach($inactive->id, $this->assignment(true, false));
        $sales->assignedProjects()->attach($future->id, [
            ...$this->assignment(false),
            'assignment_start_date' => '2026-08-11',
        ]);

        $this->assertNull(app(SalesAgendaProjectResolver::class)->resolve($sales, '2026-08-10'));
    }

    public function test_store_request_accepts_primary_sales_only_and_exposes_simple_fields(): void
    {
        [$sales] = $this->context();
        $request = new StoreSalesAgendaRequest;
        $request->setUserResolver(fn () => $sales);

        $this->assertTrue($request->authorize());
        $this->assertSame(['scheduled_date', 'sales_activity_category', 'title', 'location', 'activity_result'], array_keys($request->rules()));
        $this->assertSame(['required', 'string'], array_slice($request->rules()['sales_activity_category'], 0, 2));
        $this->assertSame('in:"'.implode('","', ContentItem::SALES_ACTIVITY_CATEGORIES).'"', (string) $request->rules()['sales_activity_category'][2]);

        foreach (['manager', 'branch_manager', 'supervisor', 'sales_coordinator', 'admin', 'pusat', 'superadmin'] as $role) {
            $request->setUserResolver(fn () => $this->user($role));
            $this->assertFalse($request->authorize(), "Primary {$role} must not create Sales Agenda.");
        }

        $manager = $this->user('manager');
        $manager->roles()->attach(Role::where('slug', 'sales')->value('id'));
        $request->setUserResolver(fn () => $manager);
        $this->assertFalse($request->authorize());
    }

    public function test_non_sales_roles_cannot_post_sales_agenda(): void
    {
        foreach (['manager', 'branch_manager', 'supervisor', 'sales_coordinator', 'admin', 'pusat', 'superadmin'] as $role) {
            $this->actingAs($this->user($role))->post(route('sales-agendas.store'), [
                'scheduled_date' => '2026-08-10',
                'sales_activity_category' => 'Cek Lokasi',
                'title' => 'Agenda terlarang',
            ])->assertForbidden();
        }

        $this->assertDatabaseCount('content_items', 0);
    }

    private function context(): array
    {
        $branch = Branch::create(['name' => 'Kudus', 'code' => 'KDS', 'is_active' => true]);

        return [
            $this->user('sales', $branch),
            LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Oasis A', 'is_active' => true]),
            LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Oasis B', 'is_active' => true]),
        ];
    }

    private function user(string $slug, ?Branch $branch = null): User
    {
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => ucfirst(str_replace('_', ' ', $slug))]);

        return User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $branch?->id,
            'account_status' => AccountStatus::Active,
            'is_active' => true,
            'email_verified_at' => now(),
            'password_changed_at' => now(),
        ]);
    }

    private function assignment(bool $primary, bool $active = true): array
    {
        return [
            'is_primary' => $primary,
            'is_active' => $active,
            'assignment_start_date' => '2026-08-01',
            'assignment_end_date' => '2026-08-31',
        ];
    }
}
