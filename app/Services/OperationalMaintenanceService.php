<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Models\ActivityLog;
use App\Models\OperationalMaintenanceSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class OperationalMaintenanceService
{
    private const BYPASS_PERMISSION = 'system.maintenance_bypass';

    private const MANAGE_PERMISSION = 'system.maintenance_manage';

    private const MAX_TITLE_LENGTH = 160;

    private const MAX_MESSAGE_LENGTH = 2000;

    public function settingOrFail(): OperationalMaintenanceSetting
    {
        return OperationalMaintenanceSetting::query()->findOrFail(OperationalMaintenanceSetting::GLOBAL_ID);
    }

    public function currentConfiguration(): OperationalMaintenanceSetting
    {
        return $this->settingOrFail()->loadMissing(['enabledBy:id,name', 'disabledBy:id,name']);
    }

    public function isActive(): bool
    {
        return $this->activeSetting() !== null;
    }

    public function activeSetting(): ?OperationalMaintenanceSetting
    {
        try {
            $setting = OperationalMaintenanceSetting::query()->find(OperationalMaintenanceSetting::GLOBAL_ID);
            if (! $setting) {
                Log::error('Operational maintenance singleton is missing; access remains open.', [
                    'operation' => 'operational_maintenance_read',
                ]);

                return null;
            }

            return $setting->enabled ? $setting : null;
        } catch (Throwable $exception) {
            Log::error('Operational maintenance state could not be read; access remains open.', [
                'operation' => 'operational_maintenance_read',
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    public function canBypass(User $user): bool
    {
        return $user->hasPermission(self::BYPASS_PERMISSION);
    }

    public function lifecycleEligibleBypassQuery(): Builder
    {
        return User::query()
            ->where('account_status', AccountStatus::Active->value)
            ->whereNotNull('email_verified_at')
            ->where('must_change_password', false)
            ->whereHas('role', function (Builder $roleQuery) {
                $roleQuery->where(function (Builder $permissionQuery) {
                    $permissionQuery->where('is_superadmin', true)
                        ->orWhereHas('permissions', fn (Builder $query) => $query->where('slug', self::BYPASS_PERMISSION));
                });
            });
    }

    public function publicData(?OperationalMaintenanceSetting $setting = null): array
    {
        try {
            $setting ??= $this->settingOrFail();

            return [
                'enabled' => $setting->enabled,
                'title' => $setting->title,
                'message' => $setting->message,
                'estimated_end_at' => $setting->estimated_end_at?->toIso8601String(),
            ];
        } catch (Throwable $exception) {
            Log::error('Operational maintenance public data could not be read; access remains open.', [
                'operation' => 'operational_maintenance_public_data',
                'exception' => $exception::class,
            ]);

            return [
                'enabled' => false,
                'title' => null,
                'message' => null,
                'estimated_end_at' => null,
            ];
        }
    }

    public function enable(User $actor, array $configuration, int $expectedLockVersion): OperationalMaintenanceSetting
    {
        $values = $this->validatedConfiguration($configuration);

        return DB::transaction(function () use ($actor, $values, $expectedLockVersion) {
            $setting = $this->lockedSetting();
            $this->lockActorAuthorizationState($actor);
            $this->assertAuthorizedActor($actor);
            $this->lockAndAssertBypassRemainsAvailable();
            $this->assertExpectedVersion($setting, $expectedLockVersion);

            if ($setting->enabled) {
                throw ValidationException::withMessages([
                    'enabled' => 'Pemeliharaan operasional sudah aktif.',
                ]);
            }

            $previousState = [
                'enabled' => $setting->enabled,
                'lock_version' => $setting->lock_version,
                'disabled_at' => $setting->disabled_at?->toIso8601String(),
            ];
            $enabledAt = now();
            $updated = OperationalMaintenanceSetting::query()
                ->whereKey(OperationalMaintenanceSetting::GLOBAL_ID)
                ->where('lock_version', $expectedLockVersion)
                ->update([
                    ...$values,
                    'enabled' => true,
                    'enabled_by' => $actor->id,
                    'disabled_by' => null,
                    'enabled_at' => $enabledAt,
                    'disabled_at' => null,
                    'lock_version' => $expectedLockVersion + 1,
                    'updated_at' => $enabledAt,
                ]);

            $this->assertCompareAndSwap($updated);
            $setting = $setting->fresh();
            $this->recordActivity('operational_maintenance_enabled', $setting, $actor, [
                'title' => $setting->title,
                'estimated_end_at' => $setting->estimated_end_at?->toIso8601String(),
                'message_summary' => $this->messageSummary($setting->message),
                'previous_state' => $previousState,
            ]);

            return $setting;
        }, 3);
    }

    public function disable(User $actor, int $expectedLockVersion): OperationalMaintenanceSetting
    {
        return DB::transaction(function () use ($actor, $expectedLockVersion) {
            $setting = $this->lockedSetting();
            $this->lockActorAuthorizationState($actor);
            $this->assertAuthorizedActor($actor);
            $this->assertExpectedVersion($setting, $expectedLockVersion);

            if (! $setting->enabled) {
                throw ValidationException::withMessages([
                    'enabled' => 'Pemeliharaan operasional sudah tidak aktif.',
                ]);
            }

            $previousState = [
                'enabled' => $setting->enabled,
                'lock_version' => $setting->lock_version,
                'enabled_at' => $setting->enabled_at?->toIso8601String(),
                'enabled_by' => $setting->enabled_by,
            ];
            $disabledAt = now();
            $durationSeconds = $setting->enabled_at
                ? max(0, (int) $setting->enabled_at->diffInSeconds($disabledAt))
                : null;
            $updated = OperationalMaintenanceSetting::query()
                ->whereKey(OperationalMaintenanceSetting::GLOBAL_ID)
                ->where('lock_version', $expectedLockVersion)
                ->update([
                    'enabled' => false,
                    'disabled_by' => $actor->id,
                    'disabled_at' => $disabledAt,
                    'lock_version' => $expectedLockVersion + 1,
                    'updated_at' => $disabledAt,
                ]);

            $this->assertCompareAndSwap($updated);
            $setting = $setting->fresh();
            $this->recordActivity('operational_maintenance_disabled', $setting, $actor, [
                'title' => $setting->title,
                'duration_seconds' => $durationSeconds,
                'message_summary' => $this->messageSummary($setting->message),
                'previous_state' => $previousState,
            ]);

            return $setting;
        }, 3);
    }

    private function lockedSetting(): OperationalMaintenanceSetting
    {
        return OperationalMaintenanceSetting::query()
            ->lockForUpdate()
            ->findOrFail(OperationalMaintenanceSetting::GLOBAL_ID);
    }

    private function assertExpectedVersion(OperationalMaintenanceSetting $setting, int $expectedLockVersion): void
    {
        if ($expectedLockVersion < 0 || $setting->lock_version !== $expectedLockVersion) {
            throw ValidationException::withMessages([
                'lock_version' => 'Konfigurasi pemeliharaan telah berubah. Muat ulang data terbaru.',
            ]);
        }
    }

    private function assertCompareAndSwap(int $updated): void
    {
        if ($updated !== 1) {
            throw ValidationException::withMessages([
                'lock_version' => 'Konfigurasi pemeliharaan telah berubah. Muat ulang data terbaru.',
            ]);
        }
    }

    private function assertAuthorizedActor(User $actor): void
    {
        $eligibleActor = $this->lifecycleEligibleBypassQuery()
            ->with('role.permissions')
            ->find($actor->id);

        if (! $eligibleActor || ! $eligibleActor->hasPermission(self::MANAGE_PERMISSION)) {
            throw new AuthorizationException('Anda tidak memiliki izin untuk mengelola pemeliharaan operasional.');
        }
    }

    private function lockActorAuthorizationState(User $actor): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('slug', [self::BYPASS_PERMISSION, self::MANAGE_PERMISSION])
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');
        $roleId = User::query()->whereKey($actor->id)->lockForUpdate()->value('role_id');

        if (! $roleId) {
            return;
        }

        DB::table('roles')->where('id', $roleId)->lockForUpdate()->get();
        DB::table('role_permission')
            ->where('role_id', $roleId)
            ->whereIn('permission_id', $permissionIds)
            ->orderBy('permission_id')
            ->lockForUpdate()
            ->get();
    }

    private function lockAndAssertBypassRemainsAvailable(): void
    {
        $eligibleUsers = $this->lifecycleEligibleBypassQuery()
            ->orderBy('users.id')
            ->lockForUpdate()
            ->get(['users.id', 'users.role_id']);

        if ($eligibleUsers->isEmpty()) {
            throw ValidationException::withMessages([
                'enabled' => 'Pemeliharaan tidak dapat diubah karena tidak ada akun bypass yang memenuhi syarat.',
            ]);
        }

        $roleIds = $eligibleUsers->pluck('role_id')->filter()->unique()->sort()->values();
        $permissionId = DB::table('permissions')->where('slug', self::BYPASS_PERMISSION)->value('id');

        DB::table('roles')->whereIn('id', $roleIds)->orderBy('id')->lockForUpdate()->get();
        if ($permissionId) {
            DB::table('role_permission')
                ->whereIn('role_id', $roleIds)
                ->where('permission_id', $permissionId)
                ->orderBy('role_id')
                ->lockForUpdate()
                ->get();
        }

        if (! $this->lifecycleEligibleBypassQuery()->exists()) {
            throw ValidationException::withMessages([
                'enabled' => 'Pemeliharaan tidak dapat diubah karena tidak ada akun bypass yang memenuhi syarat.',
            ]);
        }
    }

    private function validatedConfiguration(array $configuration): array
    {
        $title = trim((string) ($configuration['title'] ?? ''));
        $message = trim((string) ($configuration['message'] ?? ''));

        $errors = [];
        if ($title === '' || mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            $errors['title'] = 'Judul wajib diisi dan maksimal 160 karakter.';
        }
        if ($message === '' || mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            $errors['message'] = 'Pesan wajib diisi dan maksimal 2.000 karakter.';
        }

        $estimatedEndAt = null;
        if (filled($configuration['estimated_end_at'] ?? null)) {
            try {
                $estimatedEndAt = CarbonImmutable::parse($configuration['estimated_end_at']);
                if ($estimatedEndAt->isPast()) {
                    $errors['estimated_end_at'] = 'Perkiraan selesai harus berada di masa mendatang.';
                }
            } catch (Throwable) {
                $errors['estimated_end_at'] = 'Perkiraan selesai tidak valid.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'title' => $title,
            'message' => $message,
            'estimated_end_at' => $estimatedEndAt,
        ];
    }

    private function messageSummary(string $message): string
    {
        return Str::limit((string) preg_replace('/\s+/u', ' ', trim($message)), 200, '...');
    }

    private function recordActivity(string $event, OperationalMaintenanceSetting $setting, User $actor, array $properties): void
    {
        ActivityLog::create([
            'causer_id' => $actor->id,
            'subject_type' => OperationalMaintenanceSetting::class,
            'subject_id' => null,
            'event' => $event,
            'description' => $event === 'operational_maintenance_enabled'
                ? 'Pemeliharaan operasional OASIS diaktifkan'
                : 'Pemeliharaan operasional OASIS dinonaktifkan',
            'properties' => [
                'setting_id' => $setting->getKey(),
                'actor_user_id' => $actor->id,
                'lock_version' => $setting->lock_version,
                ...$properties,
            ],
        ]);
    }
}
