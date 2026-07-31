<?php

namespace Tests\Feature;

use App\Services\OptimisticLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Concerns\BuildsExpenseFixtures;
use Tests\TestCase;

class ExpenseAccessTest extends TestCase
{
    use BuildsExpenseFixtures, RefreshDatabase;

    public function test_superadmin_and_pusat_can_access_every_expense_endpoint(): void
    {
        $branch = $this->expenseBranch();
        $project = $this->expenseProject($branch);
        $category = $this->expenseCategory();

        foreach (['superadmin', 'pusat'] as $role) {
            $user = $this->expenseUser($role, $branch);
            $expense = $this->expense(compact('branch', 'project', 'category') + ['creator' => $user]);
            $token = app(OptimisticLockService::class)->token($expense);

            $this->actingAs($user)->get(route('expenses.index', ['period_month' => '2026-07']))->assertOk();
            $this->actingAs($user)->get(route('expenses.create'))->assertOk();
            $this->actingAs($user)->get(route('expenses.show', $expense))->assertOk();
            $this->actingAs($user)->get(route('expenses.edit', $expense))->assertOk();
            $this->actingAs($user)->getJson(route('expenses.projects', ['branch_id' => $branch->id]))
                ->assertOk()->assertJsonPath('projects.0.id', $project->id);
            $this->actingAs($user)->get(route('expenses.export', ['period_month' => '2026-07']))
                ->assertOk()->assertDownload();
            $this->actingAs($user)->post(route('expenses.store'), $this->validExpensePayload(
                $branch, $project, $category, ['description' => "Created by {$role}"]
            ))->assertRedirect();
            $this->assertDatabaseHas('expenses', [
                'description' => "Created by {$role}",
                'created_by' => $user->id,
            ]);
            $this->actingAs($user)->put(route('expenses.update', $expense), $this->validExpensePayload(
                $branch, $project, $category, ['description' => "Updated by {$role}", 'expected_updated_at' => $token]
            ))->assertRedirect(route('expenses.show', $expense));

            $expense->refresh();
            $this->actingAs($user)->patch(route('expenses.cancel', $expense), [
                'cancellation_reason' => 'Transaksi dibatalkan untuk pengujian akses.',
                'expected_updated_at' => app(OptimisticLockService::class)->token($expense),
            ])->assertRedirect(route('expenses.show', $expense));
        }
    }

    public function test_sales_staff_admin_and_manager_receive_forbidden_from_expense_http_endpoints(): void
    {
        $branch = $this->expenseBranch();
        $project = $this->expenseProject($branch);
        $category = $this->expenseCategory();
        $owner = $this->expenseUser('pusat', $branch);
        $expense = $this->expense(compact('branch', 'project', 'category') + ['creator' => $owner]);

        foreach (['sales', 'staff', 'admin', 'manager'] as $role) {
            $user = $this->expenseUser($role, $branch);
            $token = app(OptimisticLockService::class)->token($expense);

            $this->actingAs($user)->get(route('expenses.index'))->assertForbidden();
            $this->actingAs($user)->get(route('expenses.create'))->assertForbidden();
            $this->actingAs($user)->get(route('expenses.show', $expense))->assertForbidden();
            $this->actingAs($user)->get(route('expenses.edit', $expense))->assertForbidden();
            $this->actingAs($user)->get(route('expenses.export'))->assertForbidden();
            $this->actingAs($user)->getJson(route('expenses.projects', ['branch_id' => $branch->id]))->assertForbidden();
            $this->actingAs($user)->post(route('expenses.store'), $this->validExpensePayload(
                $branch, $project, $category, ['description' => "Forbidden {$role}"]
            ))->assertForbidden();
            $this->actingAs($user)->put(route('expenses.update', $expense), $this->validExpensePayload(
                $branch, $project, $category, ['expected_updated_at' => $token]
            ))->assertForbidden();
            $this->actingAs($user)->patch(route('expenses.cancel', $expense), [
                'cancellation_reason' => 'Tidak berhak',
                'expected_updated_at' => $token,
            ])->assertForbidden();
        }
    }

    public function test_navigation_and_dashboard_links_match_authorization_target(): void
    {
        $branch = $this->expenseBranch();

        foreach (['superadmin', 'pusat'] as $role) {
            $response = $this->actingAs($this->expenseUser($role, $branch))->get(route('dashboard'))->assertOk();
            $response->assertSee(route('expenses.index'), false)->assertSee(route('expenses.create'), false);
            if ($role === 'superadmin') {
                $response->assertSee(route('expense-categories.index'), false);
            } else {
                $response->assertDontSee(route('expense-categories.index'), false);
            }
        }

        foreach (['sales', 'staff', 'admin', 'manager'] as $role) {
            $user = $this->expenseUser($role, $branch);
            $route = $role === 'sales' ? 'sales-pocketbook.index' : 'dashboard';
            $response = $this->actingAs($user)->get(route($route))->assertOk();
            $response->assertDontSee(route('expenses.index'), false)->assertDontSee(route('expenses.create'), false);
        }
    }

    public function test_category_management_is_superadmin_only_at_ui_and_http_layers(): void
    {
        $branch = $this->expenseBranch();
        $category = $this->expenseCategory();
        $superadmin = $this->expenseUser('superadmin', $branch);

        $this->actingAs($superadmin)->get(route('expense-categories.index'))->assertOk()->assertSee($category->name);
        $this->actingAs($superadmin)->post(route('expense-categories.store'), [
            'name' => 'Kategori Baru', 'sort_order' => 9,
        ])->assertRedirect();
        $this->actingAs($superadmin)->put(route('expense-categories.update', $category), [
            'name' => 'Kategori Diperbarui', 'sort_order' => 3,
        ])->assertRedirect();
        $this->actingAs($superadmin)->patch(route('expense-categories.toggle', $category))->assertRedirect();

        foreach (['pusat', 'sales', 'staff', 'admin', 'manager'] as $role) {
            $user = $this->expenseUser($role, $branch);

            $this->actingAs($user)->get(route('expense-categories.index'))->assertForbidden();
            $this->actingAs($user)->post(route('expense-categories.store'), [
                'name' => "Terlarang {$role}", 'sort_order' => 1,
            ])->assertForbidden();
            $this->actingAs($user)->put(route('expense-categories.update', $category), [
                'name' => "Terlarang {$role}", 'sort_order' => 1,
            ])->assertForbidden();
            $this->actingAs($user)->patch(route('expense-categories.toggle', $category))->assertForbidden();
        }
    }

    public function test_expenses_have_no_destroy_route_or_permanent_delete_capability(): void
    {
        $expense = $this->expense();
        $superadmin = $this->expenseUser('superadmin', $expense->branch);
        $pusat = $this->expenseUser('pusat', $expense->branch);

        $this->assertFalse(Route::has('expenses.destroy'));
        $this->assertFalse($superadmin->can('forceDelete', $expense));
        $this->assertFalse($pusat->can('delete', $expense));
        $this->actingAs($pusat)->delete('/pengeluaran/'.$expense->id)->assertStatus(405);
        $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'deleted_at' => null]);
    }

    public function test_user_management_permanent_delete_is_permission_gated_and_draft_only(): void
    {
        $branch = $this->expenseBranch();
        $superadmin = $this->expenseUser('superadmin', $branch);
        $creator = $this->expenseUser('pusat', $branch);
        $this->expense(['branch' => $branch, 'creator' => $creator]);

        $this->assertTrue(Route::has('admin-users.destroy'));
        $this->actingAs($superadmin)->delete('/admin-users/'.$creator->id, ['reason' => 'uji penghapusan'])
            ->assertRedirect()
            ->assertSessionHas('warning');
        $this->assertDatabaseHas('users', ['id' => $creator->id]);
    }

    public function test_existing_dashboard_and_named_operational_pages_still_render_for_allowed_users(): void
    {
        $branch = $this->expenseBranch();
        $admin = $this->expenseUser('admin', $branch);
        $pusat = $this->expenseUser('pusat', $branch);

        $this->actingAs($admin)->get(route('dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('dana-talangan.index'))->assertOk();
        $this->actingAs($admin)->get(route('content-calendar.index'))->assertOk();
        $this->actingAs($pusat)->get(route('sales-pocketbook.index'))->assertOk();

        $this->assertTrue(Route::has('dana-talangan.index'));
        $this->assertTrue(Route::has('content-calendar.index'));
        $this->assertTrue(Route::has('sales-pocketbook.index'));
    }
}
