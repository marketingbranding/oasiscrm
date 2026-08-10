<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Models\User;

class UserProvisioningService
{
    public function createDirectlyActivated(array $attributes, string $temporaryPassword, User $actor): User
    {
        if (! $actor->isSuperadmin()) {
            throw new \DomainException('Hanya Super Admin yang dapat mengaktifkan pengguna secara langsung.');
        }

        $user = new User;
        $user->fill($attributes);
        $user->forceFill([
            'password' => $temporaryPassword,
            'account_status' => AccountStatus::Active,
            'is_active' => true,
            'email_verified_at' => now(),
            'activated_at' => now(),
            'must_change_password' => true,
            'password_changed_at' => null,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ])->save();

        return $user;
    }
}
