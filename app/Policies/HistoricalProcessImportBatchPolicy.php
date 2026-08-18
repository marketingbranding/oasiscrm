<?php

namespace App\Policies;

use App\Models\HistoricalProcessImportBatch;
use App\Models\User;
use App\Services\WorkspaceAccessService;

class HistoricalProcessImportBatchPolicy
{
    public function __construct(private WorkspaceAccessService $access) {}

    public function view(User $user, HistoricalProcessImportBatch $batch): bool
    {
        return $user->isSuperadmin()
            && ($batch->uploaded_by === $user->id || $user->isSuperadmin())
            && $this->access->canViewBranch($user, $batch->branch_id);
    }

    public function confirm(User $user, HistoricalProcessImportBatch $batch): bool
    {
        return $this->view($user, $batch);
    }
}
