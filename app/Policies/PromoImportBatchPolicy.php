<?php

namespace App\Policies;

use App\Models\PromoImportBatch;
use App\Models\User;
use App\Services\PromoAccessService;

class PromoImportBatchPolicy
{
    public function __construct(private PromoAccessService $access) {}

    public function view(User $user, PromoImportBatch $batch): bool
    {
        return ($batch->uploaded_by === $user->id || $user->isSuperadmin())
            && $this->access->canManageBranch($user, $batch->branch_id);
    }

    public function confirm(User $user, PromoImportBatch $batch): bool
    {
        return $this->view($user, $batch);
    }
}
