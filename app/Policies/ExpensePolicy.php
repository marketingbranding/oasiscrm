<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;

class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasExpenseAccess($user);
    }

    public function view(User $user, Expense $expense): bool
    {
        return $this->hasExpenseAccess($user);
    }

    public function create(User $user): bool
    {
        return $this->hasExpenseAccess($user);
    }

    public function update(User $user, Expense $expense): bool
    {
        return $this->hasExpenseAccess($user);
    }

    public function cancel(User $user, Expense $expense): bool
    {
        return $this->hasExpenseAccess($user);
    }

    public function export(User $user): bool
    {
        return $this->hasExpenseAccess($user);
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $user->isSuperadmin();
    }

    public function restore(User $user, Expense $expense): bool
    {
        return $user->isSuperadmin();
    }

    public function forceDelete(User $user, Expense $expense): bool
    {
        return false;
    }

    private function hasExpenseAccess(User $user): bool
    {
        return $user->isSuperadmin() || $user->hasRole('pusat');
    }
}
