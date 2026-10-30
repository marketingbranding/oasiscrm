<?php

namespace App\Services;

use App\Models\SalesCoordinatorSales;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesCoordinatorAssignmentService
{
    public function __construct(private readonly OrganizationGraphService $graph) {}

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

        if (! $coordinator->is_active || ! $coordinator->isAccountActive()) {
            throw ValidationException::withMessages([
                'coordinator_user_id' => 'Sales dan Koordinator Tim harus merupakan pengguna aktif.',
            ]);
        }

        if (blank($sales->branch_id) || blank($coordinator->branch_id) || (int) $sales->branch_id !== (int) $coordinator->branch_id) {
            throw ValidationException::withMessages([
                'coordinator_user_id' => 'Sales dan Koordinator Tim harus berada pada cabang utama yang sama.',
            ]);
        }

        return DB::transaction(function () use ($coordinator, $sales): SalesCoordinatorSales {
            $current = SalesCoordinatorSales::query()->where('sales_user_id', $sales->id)->current()->get();
            $same = $current->firstWhere('coordinator_user_id', $coordinator->id);

            $this->graph->assignSalesTeamMember($sales, $coordinator, source: 'legacy_sales_coordinator');
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
        });
    }

    private function end(SalesCoordinatorSales $assignment): void
    {
        $assignment->loadMissing(['coordinator', 'sales']);
        $this->graph->closeSalesTeamMember($assignment->sales, $assignment->coordinator, 'Sales Coordinator mapping berakhir.');
        $assignment->update(['is_active' => false, 'ended_at' => today()]);
    }
}
