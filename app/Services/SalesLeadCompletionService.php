<?php

namespace App\Services;

use App\Models\SalesLead;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class SalesLeadCompletionService
{
    public function __construct(private readonly CrmAuthorizationService $authorization) {}

    public function complete(SalesLead $lead, User $actor): SalesLead
    {
        if (! $actor->hasPermission('sales_pocketbook.manage_own')
            || ! $this->authorization->canViewSalesLead($actor, $lead)) {
            throw new AuthorizationException('Anda tidak memiliki akses untuk menyelesaikan lead ini.');
        }

        return DB::transaction(function () use ($lead, $actor): SalesLead {
            $current = SalesLead::query()->lockForUpdate()->findOrFail($lead->id);
            if ($current->completed_at !== null) {
                return $current;
            }

            $current->forceFill([
                'completed_at' => now(),
                'completed_by_user_id' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();
            $current->logSalesActivity('completed', [
                'branch_id' => $current->branch_id,
                'project_id' => $current->project_id,
                'sales_user_id' => $current->sales_user_id,
            ]);

            return $current->fresh();
        });
    }
}
