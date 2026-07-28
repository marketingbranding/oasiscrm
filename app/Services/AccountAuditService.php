<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Models\UserImportBatch;

class AccountAuditService
{
    public function logRolePermissionsChanged(Role $role, ?User $actor, array $oldSlugs, array $newSlugs): ActivityLog
    {
        return ActivityLog::create([
            'causer_id' => $actor?->id,
            'subject_type' => Role::class,
            'subject_id' => $role->id,
            'event' => 'role_permissions_changed',
            'description' => "Izin peran {$role->name} diperbarui",
            'properties' => [
                'actor_user_id' => $actor?->id,
                'role_id' => $role->id,
                'old' => ['permissions' => array_values($oldSlugs)],
                'new' => ['permissions' => array_values($newSlugs)],
            ],
        ]);
    }

    public function log(string $event, User $target, ?User $actor = null, array $old = [], array $new = []): ActivityLog
    {
        return ActivityLog::create([
            'causer_id' => $actor?->id,
            'subject_type' => User::class,
            'subject_id' => $target->id,
            'event' => $event,
            'description' => $this->description($event, $target),
            'properties' => array_filter([
                'actor_user_id' => $actor?->id,
                'target_user_id' => $target->id,
                'old' => $this->safeValues($old),
                'new' => $this->safeValues($new),
            ], fn ($value) => $value !== null && $value !== []),
        ]);
    }

    public function logUserImportBatch(string $event, UserImportBatch $batch, ?User $actor, array $counts): ActivityLog
    {
        $safeCounts = collect($counts)->only([
            'total_rows', 'valid_rows', 'warning_rows', 'error_rows', 'created_rows',
            'invitation_sent_rows', 'invitation_failed_rows', 'skipped_rows',
        ])->all();

        return ActivityLog::create([
            'causer_id' => $actor?->id,
            'subject_type' => UserImportBatch::class,
            'subject_id' => $batch->id,
            'event' => $event,
            'description' => match ($event) {
                'user_import_uploaded' => "File impor pengguna batch {$batch->id} diunggah",
                'user_import_preview_generated' => "Preview impor pengguna batch {$batch->id} dibuat",
                'user_import_confirmed' => "Impor pengguna batch {$batch->id} dikonfirmasi",
                'user_import_completed' => "Impor pengguna batch {$batch->id} selesai",
                default => "Peristiwa impor pengguna batch {$batch->id}: {$event}",
            },
            'properties' => ['batch_id' => $batch->id, ...$safeCounts],
        ]);
    }

    public function logBulkUser(string $event, User $target, User $actor, UserImportBatch $batch, int $rowId): ActivityLog
    {
        return ActivityLog::create([
            'causer_id' => $actor->id,
            'subject_type' => User::class,
            'subject_id' => $target->id,
            'event' => $event,
            'description' => "Peristiwa onboarding pengguna batch {$batch->id}: {$event}",
            'properties' => [
                'actor_user_id' => $actor->id,
                'target_user_id' => $target->id,
                'batch_id' => $batch->id,
                'row_id' => $rowId,
            ],
        ]);
    }

    private function safeValues(array $values): array
    {
        return collect($values)->except([
            'password', 'password_confirmation', 'remember_token', 'token', 'token_hash',
        ])->all();
    }

    private function description(string $event, User $target): string
    {
        return match ($event) {
            'invitation_sent' => "Undangan akun {$target->name} dikirim",
            'invitation_resent' => "Undangan akun {$target->name} dikirim ulang",
            'invitation_revoked' => "Undangan akun {$target->name} dicabut",
            'invitation_activated' => "Akun {$target->name} diaktifkan melalui undangan",
            'login_success' => "Akun {$target->name} berhasil masuk",
            'password_reset_completed' => "Reset kata sandi {$target->name} selesai",
            'password_changed' => "Kata sandi {$target->name} diubah",
            default => "Peristiwa akun {$target->name}: {$event}",
        };
    }
}
