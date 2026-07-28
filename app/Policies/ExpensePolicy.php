<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;
use App\Services\OrganizationScopeService;

class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('expenses.view') && $user->hasScopedPermission('expenses');
    }

    public function view(User $user, Expense $expense): bool
    {
        return $user->hasPermission('expenses.view') && $this->withinScope($user, $expense);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('expenses.create') && $user->hasScopedPermission('expenses', 'manage');
    }

    public function update(User $user, Expense $expense): bool
    {
        return $user->hasPermission('expenses.update') && $this->withinScope($user, $expense, 'manage');
    }

    public function cancel(User $user, Expense $expense): bool
    {
        return $user->hasPermission('expenses.cancel') && $this->withinScope($user, $expense, 'manage');
    }

    public function export(User $user): bool
    {
        return $user->hasPermission('expenses.export') && $user->hasScopedPermission('expenses', 'export');
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $user->hasPermission('expenses.delete_permanently');
    }

    public function restore(User $user, Expense $expense): bool
    {
        return $user->hasPermission('expenses.delete_permanently');
    }

    public function forceDelete(User $user, Expense $expense): bool
    {
        return false;
    }

    private function withinScope(User $user, Expense $expense, string $action = 'view'): bool
    {
        if ($user->hasPermission("expenses.{$action}_all")) {
            return true;
        }

        return in_array((int) $expense->branch_id, app(OrganizationScopeService::class)->branchIds($user, 'expenses', $action), true);
    }
}
