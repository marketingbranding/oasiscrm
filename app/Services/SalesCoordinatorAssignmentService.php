<?php

namespace App\Services;

use App\Models\SalesCoordinatorSales;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class SalesCoordinatorAssignmentService
{
    public function sync(User $coordinator, array $salesIds): void
    {
        $selected = collect($salesIds)->map(fn ($id) => (int) $id)->unique()->values();
        $current = SalesCoordinatorSales::query()->where('coordinator_user_id', $coordinator->id)->current()->get();
        $current->whereNotIn('sales_user_id', $selected)->each(fn (SalesCoordinatorSales $assignment) => $this->end($assignment));

        if (! $coordinator->hasPrimaryRole('sales_coordinator')) {
            return;
        }

        foreach ($selected as $salesId) {
            $this->assign(User::findOrFail($salesId), $coordinator);
        }
    }

    public function assign(User $sales, User $coordinator): SalesCoordinatorSales
    {
        if (! $sales->hasPrimaryRole('sales') || ! $coordinator->hasPrimaryRole('sales_coordinator')) {
            throw ValidationException::withMessages([
                'sales_coordinator_email' => 'Assignment operasional membutuhkan primary role sales dan sales_coordinator.',
            ]);
        }

        $current = SalesCoordinatorSales::query()->where('sales_user_id', $sales->id)->current()->get();
        $same = $current->firstWhere('coordinator_user_id', $coordinator->id);

        $current->where('coordinator_user_id', '!=', $coordinator->id)
            ->each(fn (SalesCoordinatorSales $assignment) => $this->end($assignment));

        if ($same !== null) {
            return $same;
        }

        return SalesCoordinatorSales::create([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $sales->id,
            'is_active' => true,
            'started_at' => today(),
        ]);
    }

    private function end(SalesCoordinatorSales $assignment): void
    {
        $assignment->update(['is_active' => false, 'ended_at' => today()]);
    }
}
