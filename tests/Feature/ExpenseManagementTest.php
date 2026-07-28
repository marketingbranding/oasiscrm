<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Expense;
use App\Services\OptimisticLockService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsExpenseFixtures;
use Tests\TestCase;

class ExpenseManagementTest extends TestCase
{
    use BuildsExpenseFixtures, RefreshDatabase;

    public function test_pusat_can_create_and_edit_an_expense_with_exact_decimal_amount(): void
    {
        $branch = $this->expenseBranch();
        $project = $this->expenseProject($branch);
        $category = $this->expenseCategory();
        $user = $this->expenseUser('pusat', $branch);

        $response = $this->actingAs($user)->post(route('expenses.store'), $this->validExpensePayload(
            $branch, $project, $category, ['amount' => '123456.78']
        ));
        $expense = Expense::where('description', 'Pembelian alat kantor')->sole();
        $response->assertRedirect(route('expenses.show', $expense));
        $this->assertSame('123456.78', $expense->amount);
        $this->assertSame($user->id, $expense->created_by);
        $created = ActivityLog::where('subject_type', Expense::class)->where('subject_id', $expense->id)
            ->where('event', 'created')->sole();
        $this->assertSame($user->id, $created->causer_id);
        $this->assertSame('123456.78', $created->properties['attributes']['amount']);

        $this->actingAs($user)->put(route('expenses.update', $expense), $this->validExpensePayload(
            $branch,
            $project,
            $category,
            [
                'amount' => '98765.43',
                'description' => 'Deskripsi setelah edit',
                'expected_updated_at' => app(OptimisticLockService::class)->token($expense),
            ],
        ))->assertRedirect(route('expenses.show', $expense));

        $expense->refresh();
        $this->assertSame('98765.43', $expense->amount);
        $this->assertSame('Deskripsi setelah edit', $expense->description);
        $this->assertSame($user->id, $expense->updated_by);
    }

    public function test_cancel_records_reason_actor_status_timestamp_and_excludes_active_views(): void
    {
        $branch = $this->expenseBranch();
        $project = $this->expenseProject($branch);
        $category = $this->expenseCategory();
        $user = $this->expenseUser('pusat', $branch);
        $active = $this->expense(compact('branch', 'project', 'category') + ['creator' => $user, 'description' => 'Tetap Aktif', 'amount' => 100]);
        $cancelled = $this->expense(compact('branch', 'project', 'category') + ['creator' => $user, 'description' => 'Akan Dibatalkan', 'amount' => 900]);

        $this->freezeTime(function () use ($user, $cancelled): void {
            $this->actingAs($user)->patch(route('expenses.cancel', $cancelled), [
                'cancellation_reason' => 'Dokumen transaksi tidak sesuai.',
                'expected_updated_at' => app(OptimisticLockService::class)->token($cancelled),
            ])->assertRedirect(route('expenses.show', $cancelled));

            $cancelled->refresh();
            $this->assertSame(Expense::STATUS_CANCELLED, $cancelled->status);
            $this->assertSame('Dokumen transaksi tidak sesuai.', $cancelled->cancellation_reason);
            $this->assertSame($user->id, $cancelled->cancelled_by);
            $this->assertSame(now()->toDateTimeString(), $cancelled->cancelled_at->toDateTimeString());
        });

        $response = $this->actingAs($user)->get(route('expenses.index', ['period_month' => '2026-07']))->assertOk();
        $response->assertSee('Tetap Aktif')->assertDontSee('Akan Dibatalkan');
        $this->assertSame(100.0, $response->viewData('summary')['total']);
        $this->assertSame(1, $response->viewData('summary')['count']);
    }

    public function test_add_another_preserves_only_safe_reusable_fields(): void
    {
        $branch = $this->expenseBranch();
        $project = $this->expenseProject($branch);
        $category = $this->expenseCategory();
        $user = $this->expenseUser('pusat', $branch);

        $this->actingAs($user)->post(route('expenses.store'), $this->validExpensePayload($branch, $project, $category, [
            'submit_action' => 'add_another',
            'description' => 'Jangan dipertahankan',
            'vendor_name' => 'Vendor sensitif',
            'amount' => '777.25',
        ]))->assertRedirect(route('expenses.create'))
            ->assertSessionHasInput('expense_date', '2026-07-20')
            ->assertSessionHasInput('branch_id', $branch->id)
            ->assertSessionHasInput('project_id', $project->id)
            ->assertSessionHasInput('expense_category_id', $category->id)
            ->assertSessionHasInput('payment_method', 'transfer')
            ->assertSessionMissing('_old_input.description')
            ->assertSessionMissing('_old_input.vendor_name')
            ->assertSessionMissing('_old_input.amount');
    }

    public function test_store_rejects_project_from_another_branch_and_inactive_relations(): void
    {
        $branch = $this->expenseBranch('Aktif');
        $otherBranch = $this->expenseBranch('Lain');
        $inactiveBranch = $this->expenseBranch('Nonaktif', false);
        $project = $this->expenseProject($branch);
        $otherProject = $this->expenseProject($otherBranch);
        $inactiveProject = $this->expenseProject($branch, 'Proyek Nonaktif', false);
        $category = $this->expenseCategory();
        $inactiveCategory = $this->expenseCategory('Kategori Nonaktif', false);
        $user = $this->expenseUser('pusat', $branch);

        $cases = [
            ['branch_id' => $branch->id, 'project_id' => $otherProject->id, 'error' => 'project_id'],
            ['branch_id' => $inactiveBranch->id, 'project_id' => null, 'error' => 'branch_id'],
            ['branch_id' => $branch->id, 'project_id' => $inactiveProject->id, 'error' => 'project_id'],
            ['branch_id' => $branch->id, 'project_id' => $project->id, 'expense_category_id' => $inactiveCategory->id, 'error' => 'expense_category_id'],
        ];

        foreach ($cases as $index => $case) {
            $error = $case['error'];
            unset($case['error']);
            $this->actingAs($user)->post(route('expenses.store'), $this->validExpensePayload(
                $branch, $project, $category, $case + ['description' => "Invalid {$index}"]
            ))->assertSessionHasErrors($error);
        }

        $this->assertSame(0, Expense::where('description', 'like', 'Invalid%')->count());
    }

    public function test_inactive_historical_category_remains_visible_but_is_not_selectable_for_new_expenses(): void
    {
        $branch = $this->expenseBranch();
        $project = $this->expenseProject($branch);
        $category = $this->expenseCategory('Kategori Historis');
        $user = $this->expenseUser('pusat', $branch);
        $expense = $this->expense(compact('branch', 'project', 'category') + ['creator' => $user]);
        $category->update(['is_active' => false]);

        $this->actingAs($user)->get(route('expenses.show', $expense))->assertOk()->assertSee('Kategori Historis');
        $this->actingAs($user)->get(route('expenses.edit', $expense))->assertOk()->assertSee('Kategori Historis')->assertSee('(Tidak Aktif)');
        $this->actingAs($user)->put(route('expenses.update', $expense), $this->validExpensePayload(
            $branch,
            $project,
            $category,
            [
                'description' => 'Pengeluaran historis diperbarui',
                'expected_updated_at' => app(OptimisticLockService::class)->token($expense),
            ],
        ))->assertRedirect(route('expenses.show', $expense));
        $this->assertSame($category->id, $expense->fresh()->expense_category_id);
        $this->actingAs($user)->get(route('expenses.create'))->assertOk()->assertDontSee('Kategori Historis');
        $this->actingAs($user)->post(route('expenses.store'), $this->validExpensePayload($branch, $project, $category))
            ->assertSessionHasErrors('expense_category_id');
    }

    public function test_amount_precision_positive_rule_and_payment_allowlist_are_enforced(): void
    {
        $branch = $this->expenseBranch();
        $project = $this->expenseProject($branch);
        $category = $this->expenseCategory();
        $user = $this->expenseUser('pusat', $branch);

        foreach (['0', '-1', '1.234'] as $amount) {
            $this->actingAs($user)->post(route('expenses.store'), $this->validExpensePayload(
                $branch, $project, $category, ['amount' => $amount]
            ))->assertSessionHasErrors('amount');
        }

        $this->actingAs($user)->post(route('expenses.store'), $this->validExpensePayload(
            $branch, $project, $category, ['payment_method' => 'crypto']
        ))->assertSessionHasErrors('payment_method');

        foreach (array_keys(Expense::PAYMENT_METHODS) as $method) {
            $this->actingAs($user)->post(route('expenses.store'), $this->validExpensePayload(
                $branch, $project, $category, ['description' => "Valid {$method}", 'payment_method' => $method]
            ))->assertSessionHasNoErrors();
        }
    }

    public function test_stale_update_and_cancel_return_conflict_without_overwriting(): void
    {
        $expense = $this->expense(['description' => 'Versi terbaru']);
        $user = $this->expenseUser('pusat', $expense->branch);
        $stale = Carbon::parse($expense->updated_at)->subSecond()->utc()->format('Y-m-d H:i:s');
        $activityCount = $expense->activities()->count();

        $this->actingAs($user)->putJson(route('expenses.update', $expense), $this->validExpensePayload(
            $expense->branch,
            $expense->project,
            $expense->category,
            ['description' => 'Versi stale', 'expected_updated_at' => $stale],
        ))->assertConflict()->assertJsonPath('code', 'record_modified');
        $this->assertSame('Versi terbaru', $expense->fresh()->description);

        $this->actingAs($user)->patchJson(route('expenses.cancel', $expense), [
            'cancellation_reason' => 'Pembatalan stale',
            'expected_updated_at' => $stale,
        ])->assertConflict()->assertJsonPath('code', 'record_modified');
        $expense->refresh();
        $this->assertSame(Expense::STATUS_ACTIVE, $expense->status);
        $this->assertNull($expense->cancellation_reason);
        $this->assertSame($activityCount, $expense->activities()->count());
    }

    public function test_second_update_in_the_same_second_is_rejected_by_lock_version(): void
    {
        $expense = $this->expense(['description' => 'Versi awal']);
        $user = $this->expenseUser('pusat', $expense->branch);

        $this->freezeTime(function () use ($expense, $user): void {
            $originalToken = app(OptimisticLockService::class)->token($expense);
            $this->actingAs($user)->put(route('expenses.update', $expense), $this->validExpensePayload(
                $expense->branch,
                $expense->project,
                $expense->category,
                ['description' => 'Versi pertama', 'expected_updated_at' => $originalToken],
            ))->assertRedirect();

            $this->actingAs($user)->putJson(route('expenses.update', $expense), $this->validExpensePayload(
                $expense->branch,
                $expense->project,
                $expense->category,
                ['description' => 'Versi kedua stale', 'expected_updated_at' => $originalToken],
            ))->assertConflict()->assertJsonPath('code', 'record_modified');
        });

        $expense->refresh();
        $this->assertSame('Versi pertama', $expense->description);
        $this->assertSame(1, $expense->lock_version);
    }

    public function test_historical_inactive_branch_and_project_can_remain_while_other_fields_are_edited(): void
    {
        $expense = $this->expense();
        $user = $this->expenseUser('pusat', $expense->branch);
        $expense->branch->update(['is_active' => false]);
        $expense->project->update(['is_active' => false]);

        $this->actingAs($user)->get(route('expenses.edit', $expense))
            ->assertOk()
            ->assertSee($expense->branch->name)
            ->assertSee($expense->project->project_name)
            ->assertSee('(Tidak Aktif)');

        $this->actingAs($user)->put(route('expenses.update', $expense), $this->validExpensePayload(
            $expense->branch,
            $expense->project,
            $expense->category,
            ['description' => 'Koreksi historis', 'expected_updated_at' => app(OptimisticLockService::class)->token($expense)],
        ))->assertRedirect();

        $this->assertSame('Koreksi historis', $expense->fresh()->description);
    }

    public function test_activity_logs_capture_material_amount_change_and_cancellation(): void
    {
        $expense = $this->expense(['amount' => '100.00']);
        $user = $this->expenseUser('pusat', $expense->branch);
        $this->actingAs($user)->put(route('expenses.update', $expense), $this->validExpensePayload(
            $expense->branch,
            $expense->project,
            $expense->category,
            ['amount' => '250.75', 'expected_updated_at' => app(OptimisticLockService::class)->token($expense)],
        ))->assertRedirect();

        $updated = ActivityLog::where('subject_type', Expense::class)->where('subject_id', $expense->id)
            ->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertEquals(100, $updated->properties['changed']['amount']['old']);
        $this->assertEquals(250.75, $updated->properties['changed']['amount']['new']);

        $expense->refresh();
        $this->actingAs($user)->patch(route('expenses.cancel', $expense), [
            'cancellation_reason' => 'Pembatalan tercatat',
            'expected_updated_at' => app(OptimisticLockService::class)->token($expense),
        ])->assertRedirect();
        $cancelled = ActivityLog::where('subject_type', Expense::class)->where('subject_id', $expense->id)
            ->where('event', 'cancelled')->sole();
        $this->assertSame('Pembatalan tercatat', $cancelled->properties['reason']);
        $this->assertStringContainsString('Rp250,75', $cancelled->description);
        $this->assertSame($user->id, $cancelled->causer_id);
    }

    public function test_category_code_is_stable_through_update_and_toggle(): void
    {
        $category = $this->expenseCategory('Kode Stabil');
        $code = $category->code;
        $superadmin = $this->expenseUser('superadmin');

        $this->actingAs($superadmin)->put(route('expense-categories.update', $category), [
            'name' => 'Nama Kategori Baru', 'code' => 'attempted_change', 'sort_order' => 12,
        ])->assertRedirect();
        $this->assertSame($code, $category->fresh()->code);

        $this->actingAs($superadmin)->patch(route('expense-categories.toggle', $category))->assertRedirect();
        $this->assertFalse($category->fresh()->is_active);
        $this->actingAs($superadmin)->patch(route('expense-categories.toggle', $category))->assertRedirect();
        $this->assertTrue($category->fresh()->is_active);
    }

    public function test_project_json_returns_only_active_branch_options_and_rejects_unauthorized_users(): void
    {
        $branch = $this->expenseBranch();
        $otherBranch = $this->expenseBranch('Cabang Lain');
        $inactiveBranch = $this->expenseBranch('Cabang Nonaktif', false);
        $active = $this->expenseProject($branch, 'Proyek Aktif');
        $this->expenseProject($branch, 'Proyek Nonaktif', false);
        $this->expenseProject($otherBranch, 'Proyek Cabang Lain');
        $user = $this->expenseUser('pusat', $branch);

        $this->actingAs($user)->getJson(route('expenses.projects', ['branch_id' => $branch->id]))
            ->assertOk()
            ->assertJsonCount(1, 'projects')
            ->assertJsonPath('projects.0.id', $active->id)
            ->assertJsonPath('projects.0.name', 'Proyek Aktif');
        $inactiveBranchResponse = $this->actingAs($user)->getJson(route('expenses.projects', [
            'branch_id' => $inactiveBranch->id,
        ]));
        $this->assertSame(422, $inactiveBranchResponse->getStatusCode());
        $inactiveBranchResponse->assertJsonValidationErrors('branch_id');
        $this->actingAs($this->expenseUser('staff', $branch))->getJson(route('expenses.projects', ['branch_id' => $branch->id]))
            ->assertForbidden();
    }
}
