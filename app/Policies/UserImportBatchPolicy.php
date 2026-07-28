<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserImportBatch;

class UserImportBatchPolicy
{
    public const REQUIRED_PERMISSIONS = [
        'users.create',
        'users.invite',
        'users.assign_roles',
        'users.assign_branches',
        'users.assign_projects',
        'users.assign_supervisor',
    ];

    public function viewAny(User $user): bool
    {
        return $user->hasAllPermissions(self::REQUIRED_PERMISSIONS);
    }

    public function view(User $user, UserImportBatch $batch): bool
    {
        if ($user->isSuperadmin()) {
            return true;
        }

        return $this->viewAny($user) && (int) $batch->uploaded_by === (int) $user->id;
    }
}
