<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserProvisioningService
{
    public function __construct(
        private readonly UserAdministrationService $administration,
        private readonly UserLifecycleService $lifecycle,
        private readonly AccountAuditService $audit,
    ) {}

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

    public function resetExistingAccess(array $ids, string $password, User $actor): Collection
    {
        if (! $actor->isSuperadmin()) {
            throw new \DomainException('Hanya Super Admin yang dapat mereset akses pengguna secara massal.');
        }

        $ids = collect($ids)->map(fn ($id) => (int) $id)->unique()->sort()->values();

        return DB::transaction(function () use ($ids, $password, $actor) {
            $users = User::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            if ($users->count() !== $ids->count()) {
                throw ValidationException::withMessages(['user_ids' => 'Satu atau lebih pengguna tidak ditemukan.']);
            }

            foreach ($users as $user) {
                $this->administration->assertCanManage($actor, $user, 'users.reset_password');
                if ($user->account_status === AccountStatus::Anonymized) {
                    throw ValidationException::withMessages(['user_ids' => "Akun {$user->name} telah dianonimkan dan tidak dapat direset."]);
                }

                $this->lifecycle->assertCriticalCapabilityContinuity($user);
                $old = [
                    'account_status' => $user->account_status->value,
                    'is_active' => $user->is_active,
                    'email_verified' => $user->email_verified_at !== null,
                    'must_change_password' => $user->must_change_password,
                ];
                $this->lifecycle->revokeUserTokens($user);
                $user->forceFill([
                    'password' => $password,
                    'account_status' => AccountStatus::Active,
                    'is_active' => true,
                    'email_verified_at' => $user->email_verified_at ?? now(),
                    'activated_at' => $user->activated_at ?? now(),
                    'suspended_at' => null,
                    'deactivated_at' => null,
                    'must_change_password' => true,
                    'password_changed_at' => null,
                    'updated_by' => $actor->id,
                ])->save();
                $this->audit->log('user_access_reset_bulk', $user, $actor, $old, [
                    'account_status' => AccountStatus::Active->value,
                    'is_active' => true,
                    'email_verified' => true,
                    'must_change_password' => true,
                ]);
            }

            return $users;
        });
    }
}
