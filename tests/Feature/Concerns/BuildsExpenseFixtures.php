<?php

namespace Tests\Feature\Concerns;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;

trait BuildsExpenseFixtures
{
    private int $fixtureSequence = 0;

    protected function expenseUser(string $roleSlug, ?Branch $branch = null, ?string $name = null): User
    {
        $role = Role::firstOrCreate(['slug' => $roleSlug], [
            'name' => ucfirst($roleSlug),
            'is_superadmin' => $roleSlug === 'superadmin',
        ]);
        $this->fixtureSequence++;

        $user = new User;
        $user->forceFill([
            'name' => $name ?? ucfirst($roleSlug).' Expense',
            'email' => "expense-{$roleSlug}-{$this->fixtureSequence}@example.test",
            'email_verified_at' => now(),
            'password' => 'password',
            'role_id' => $role->id,
            'branch_id' => $branch?->id,
            'is_active' => true,
            'password_changed_at' => now(),
        ])->save();

        return $user;
    }

    protected function expenseBranch(string $name = 'Cabang Expense', bool $active = true): Branch
    {
        $this->fixtureSequence++;

        return Branch::create([
            'name' => $name,
            'code' => 'EXP'.$this->fixtureSequence,
            'is_active' => $active,
        ]);
    }

    protected function expenseProject(Branch $branch, string $name = 'Proyek Expense', bool $active = true): LeadMaster
    {
        return LeadMaster::create([
            'branch_id' => $branch->id,
            'project_name' => $name,
            'is_active' => $active,
        ]);
    }

    protected function expenseCategory(string $name = 'Operasional Test', bool $active = true): ExpenseCategory
    {
        $this->fixtureSequence++;

        return ExpenseCategory::create([
            'name' => $name,
            'code' => 'category_'.$this->fixtureSequence,
            'is_active' => $active,
            'sort_order' => $this->fixtureSequence,
        ]);
    }

    protected function expense(array $overrides = []): Expense
    {
        $branch = $overrides['branch'] ?? $this->expenseBranch();
        $project = $overrides['project'] ?? $this->expenseProject($branch);
        $category = $overrides['category'] ?? $this->expenseCategory();
        $creator = $overrides['creator'] ?? $this->expenseUser('pusat', $branch);

        unset($overrides['branch'], $overrides['project'], $overrides['category'], $overrides['creator']);

        return Expense::create(array_merge([
            'expense_date' => '2026-07-15',
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'expense_category_id' => $category->id,
            'amount' => '125000.50',
            'description' => 'Biaya operasional pengujian',
            'vendor_name' => 'Vendor Test',
            'payment_method' => 'transfer',
            'reference_number' => 'REF-TEST',
            'notes' => 'Catatan test',
            'status' => Expense::STATUS_ACTIVE,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ], $overrides));
    }

    protected function validExpensePayload(Branch $branch, LeadMaster $project, ExpenseCategory $category, array $overrides = []): array
    {
        return array_merge([
            'expense_date' => '2026-07-20',
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'expense_category_id' => $category->id,
            'amount' => '123456.78',
            'description' => 'Pembelian alat kantor',
            'vendor_name' => 'Toko Maju',
            'payment_method' => 'transfer',
            'reference_number' => 'INV-001',
            'notes' => 'Dibayar lunas',
            'submit_action' => 'save',
        ], $overrides);
    }
}
