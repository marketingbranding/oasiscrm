<?php

namespace App\Policies;

use App\Models\ExpenseCategory;
use App\Models\User;

class ExpenseCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('expenses.manage_categories');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('expenses.manage_categories');
    }

    public function update(User $user, ExpenseCategory $expenseCategory): bool
    {
        return $user->hasPermission('expenses.manage_categories');
    }

    public function delete(User $user, ExpenseCategory $expenseCategory): bool
    {
        return false;
    }

    public function restore(User $user, ExpenseCategory $expenseCategory): bool
    {
        return false;
    }

    public function forceDelete(User $user, ExpenseCategory $expenseCategory): bool
    {
        return false;
    }
}
