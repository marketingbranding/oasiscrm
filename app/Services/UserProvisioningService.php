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
        private readonly TemporaryPasswordService $temporaryPasswords,
    ) {}

    public function createDirectlyActivated(array $attributes, string $temporaryPassword, User $actor): User
    {
        if (! $actor->isSuperadmin()) {
            throw new \DomainException('Hanya Super Admin yang dapat mengaktifkan pengguna secara langsung.');
        }

        if (! $actor->hasPermission('users.create')) {
            throw new \DomainException('Anda tidak memiliki izin untuk membuat pengguna.');
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

    public function resetAccess(User $target, User $actor): string
    {
        return DB::transaction(function () use ($target, $actor): string {
            $user = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $this->assertResettable($user, $actor);

            return $this->applyAccessReset($user, $actor);
        });
    }

    /**
     * @return Collection<int, array{user: User, temporary_password: string}>
     */
    public function resetExistingAccess(array $ids, User $actor): Collection
    {
        if (! $actor->isSuperadmin()) {
            throw new \DomainException('Hanya Super Admin yang dapat mereset akses pengguna secara massal.');
        }

        $ids = collect($ids)->map(fn ($id) => (int) $id)->unique()->sort()->values();

        return DB::transaction(function () use ($ids, $actor) {
            $users = User::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            if ($users->count() !== $ids->count()) {
                throw ValidationException::withMessages(['user_ids' => 'Satu atau lebih pengguna tidak ditemukan.']);
            }

            return $users->map(fn (User $user): array => [
                'user' => $user,
                'temporary_password' => $this->applyBulkAccessReset($user, $actor),
            ]);
        });
    }

    private function assertResettable(User $user, User $actor, ?string $validationKey = null): void
    {
        $this->administration->assertCanManage($actor, $user, 'users.reset_password');
        if ($user->account_status === AccountStatus::Anonymized) {
            $message = "Akun {$user->name} telah dianonimkan dan tidak dapat direset.";
            if ($validationKey !== null) {
                throw ValidationException::withMessages([$validationKey => $message]);
            }

            throw new \DomainException($message);
        }

        $this->lifecycle->assertCriticalCapabilityContinuity($user);
    }

    private function applyBulkAccessReset(User $user, User $actor): string
    {
        $this->assertResettable($user, $actor, 'user_ids');

        return $this->applyAccessReset($user, $actor, 'user_access_reset_bulk');
    }

    private function applyAccessReset(User $user, User $actor, string $event = 'user_access_reset'): string
    {
        $temporaryPassword = $this->temporaryPasswords->generate();
        $old = [
            'account_status' => $user->account_status->value,
            'is_active' => $user->is_active,
            'email_verified' => $user->email_verified_at !== null,
            'must_change_password' => $user->must_change_password,
        ];
        $this->lifecycle->revokeUserTokens($user);
        $user->forceFill([
            'password' => $temporaryPassword,
            'account_status' => AccountStatus::Active,
            'is_active' => true,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'activated_at' => $user->activated_at ?? now(),
            'suspended_at' => null,
            'deactivated_at' => null,
            'must_change_password' => true,
            'temporary_password_expires_at' => now()->addDay(),
            'password_changed_at' => null,
            'updated_by' => $actor->id,
        ])->save();
        $this->audit->log($event, $user, $actor, $old, [
            'account_status' => AccountStatus::Active->value,
            'is_active' => true,
            'email_verified' => true,
            'must_change_password' => true,
            'temporary_password_expires_at' => $user->temporary_password_expires_at->toIso8601String(),
        ]);

        return $temporaryPassword;
    }
}
