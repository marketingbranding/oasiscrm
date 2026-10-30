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
        if (! $coordinator->hasPrimaryRole('sales_coordinator')) {
            return;
        }

        DB::transaction(function () use ($coordinator, $selected): void {
            $selectedUsers = User::query()
                ->whereIn('id', $selected)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $lockedCoordinator = User::query()->lockForUpdate()->findOrFail($coordinator->id);

            foreach ($selected as $salesId) {
                $sales = $selectedUsers->get($salesId);
                if (! $sales instanceof User) {
                    throw ValidationException::withMessages(['sales_ids' => "Pengguna Sales {$salesId} tidak ditemukan."]);
                }
                $this->graph->validateSalesTeamMember($sales, $lockedCoordinator);
            }

            $current = SalesCoordinatorSales::query()
                ->where('coordinator_user_id', $lockedCoordinator->id)
                ->current()
                ->lockForUpdate()
                ->get();
            $current->whereNotIn('sales_user_id', $selected)->each(fn (SalesCoordinatorSales $assignment) => $this->end($assignment));

            foreach ($selectedUsers as $sales) {
                $this->assign($sales, $lockedCoordinator);
            }
        });
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
            $lockedSales = User::query()->lockForUpdate()->findOrFail($sales->id);
            $current = SalesCoordinatorSales::query()->where('sales_user_id', $lockedSales->id)->current()->lockForUpdate()->get();
            $same = $current->firstWhere('coordinator_user_id', $coordinator->id);

            $this->graph->assignSalesTeamMember($lockedSales, $coordinator, source: 'legacy_sales_coordinator');
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
