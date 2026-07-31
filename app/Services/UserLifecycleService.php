<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class UserLifecycleService
{
    private const CRITICAL_CAPABILITIES = [
        'system.maintenance_manage' => 'mengelola maintenance',
        'system.maintenance_bypass' => 'melewati maintenance',
        'users.update' => 'mengelola pengguna',
    ];

    public function __construct(
        private readonly UserInvitationService $invitations,
        private readonly AccountAuditService $audit,
    ) {}

    public function anonymize(User $target, User $actor, string $reason): void
    {
        if ($target->account_status === AccountStatus::Anonymized) {
            throw new \DomainException('Akun ini sudah dianonimkan.');
        }

        $oldStatus = $target->account_status->value;
        $oldEmail = $target->email;
        $tombstone = $this->tombstoneEmail($target);

        DB::transaction(function () use ($target, $actor, $tombstone, $oldEmail) {
            $target = User::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $this->assertCriticalCapabilityContinuity($target);
            $this->revokeActiveInvitation($target, $actor);
            $this->deleteSessions($target);
            $this->deletePasswordResetTokens($oldEmail);
            $target->forceFill([
                'name' => $this->anonymousName($target),
                'email' => $tombstone,
                'phone' => null,
                'account_status' => AccountStatus::Anonymized,
                'anonymized_at' => now(),
                'email_verified_at' => null,
                'remember_token' => Str::random(60),
                'password' => Str::random(64),
                'password_changed_at' => null,
                'updated_by' => $actor->id,
            ])->save();
        });

        $this->audit->log('user_anonymized', $target, $actor, ['account_status' => $oldStatus], [
            'reason' => $reason,
            'account_status' => AccountStatus::Anonymized->value,
            'email_released' => true,
            'access_revoked' => ['sessions', 'remember_token', 'password_reset_tokens'],
        ]);
    }

    public function releaseEmail(User $target, User $actor, string $reason): void
    {
        if ($target->account_status === AccountStatus::Anonymized) {
            throw new \DomainException('Email akun anonim sudah dilepas.');
        }
        if ($target->account_status !== AccountStatus::Inactive) {
            throw new \DomainException('Email hanya dapat dilepas untuk akun yang dinonaktifkan.');
        }

        $tombstone = $this->tombstoneEmail($target);

        DB::transaction(function () use ($target, $actor, $tombstone) {
            $target = User::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $this->assertCriticalCapabilityContinuity($target);
            $target->forceFill([
                'email' => $tombstone,
                'email_verified_at' => null,
                'updated_by' => $actor->id,
            ])->save();
        });

        $this->audit->log('user_email_released', $target, $actor, [], [
            'reason' => $reason,
            'email_released' => true,
        ]);
    }

    public function revokeUserTokens(User $target): void
    {
        $this->deleteSessions($target);
        $this->deletePasswordResetTokens($target->email);

        DB::table('user_invitations')
            ->where('user_id', $target->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        if (Schema::hasTable('personal_access_tokens')) {
            DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->where('tokenable_id', $target->id)
                ->delete();
        }

        $target->forceFill(['remember_token' => Str::random(60)])->save();
    }

    public function assertCriticalCapabilityContinuity(User $target): void
    {
        if (! $this->isEligibleCapabilityHolder($target)) {
            return;
        }

        foreach (self::CRITICAL_CAPABILITIES as $permission => $label) {
            if (! $target->hasPermission($permission)) {
                continue;
            }
            if ($this->activeCapabilityHolderCount($permission, $target->id) < 1) {
                throw new \DomainException("Akun ini adalah satu-satunya akun aktif yang dapat {$label}.");
            }
        }
    }

    public function activeCapabilityHolderCount(string $permission, ?int $exceptId = null): int
    {
        return User::query()
            ->where('account_status', AccountStatus::Active->value)
            ->where('is_active', true)
            ->whereNotNull('email_verified_at')
            ->whereNotNull('password_changed_at')
            ->where(fn (Builder $query) => $query
                ->whereHas('role', fn (Builder $role) => $role->where('is_superadmin', true))
                ->orWhereHas('role.permissions', fn (Builder $permissions) => $permissions->where('slug', $permission)))
            ->when($exceptId, fn (Builder $query, int $id) => $query->whereKeyNot($id))
            ->lockForUpdate()
            ->count();
    }

    private function isEligibleCapabilityHolder(User $user): bool
    {
        return $user->account_status === AccountStatus::Active
            && $user->is_active
            && $user->email_verified_at !== null
            && $user->password_changed_at !== null;
    }

    private function deletePasswordResetTokens(string $email): void
    {
        if (Schema::hasTable('password_reset_tokens')) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
        }
    }

    private function deleteSessions(User $user): void
    {
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
    }

    public function permanentlyDeleteDraft(User $target, User $actor, string $reason): void
    {
        $blockers = $this->deletionBlockers($target);
        if ($blockers !== []) {
            throw new \DomainException('Akun tidak dapat dihapus permanen: '.implode('; ', $blockers).'. Gunakan anonimisasi bila akun memiliki riwayat.');
        }

        DB::transaction(function () use ($target, $actor, $reason) {
            $target = User::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $this->assertCriticalCapabilityContinuity($target);
            $this->audit->log('user_draft_deleted', $target, $actor, ['account_status' => $target->account_status->value], ['reason' => $reason]);
            $target->delete();
        });
    }

    /**
     * Strict permanent-deletion boundary. Returns human-readable blockers.
     *
     * @return array<int, string>
     */
    public function deletionBlockers(User $target): array
    {
        $blockers = [];

        if ($target->account_status !== AccountStatus::PendingInvitation) {
            $blockers[] = 'Akun bukan draf undangan yang menunggu aktivasi.';
        }
        if ($target->email_verified_at !== null) {
            $blockers[] = 'Email akun sudah terverifikasi.';
        }
        if ($target->last_login_at !== null) {
            $blockers[] = 'Akun pernah masuk.';
        }

        $checks = [
            ['activity_log', fn () => DB::table('activity_log')->where('causer_id', $target->id)->exists(), 'akun tercatat sebagai pelaku aktivitas'],
            ['comments', fn () => DB::table('comments')->where('user_id', $target->id)->exists(), 'akun memiliki komentar'],
            ['comment_mentions', fn () => DB::table('comment_mentions')->where('mentioned_user_id', $target->id)->exists(), 'akun disebut dalam komentar'],
            ['user_notifications', fn () => DB::table('user_notifications')->where('user_id', $target->id)->exists(), 'akun memiliki notifikasi'],
            ['user_presences', fn () => DB::table('user_presences')->where('user_id', $target->id)->exists(), 'akun memiliki kehadiran'],
            ['user_invitations', fn () => DB::table('user_invitations')->where('user_id', $target->id)->exists(), 'akun memiliki riwayat undangan'],
            ['user_import_batches', fn () => DB::table('user_import_batches')->where('uploaded_by', $target->id)->exists(), 'akun mengunggah batch impor'],
            ['user_import_rows', fn () => DB::table('user_import_rows')->where('created_user_id', $target->id)->exists(), 'akun tercatat pada baris impor'],
            ['content_items', fn () => DB::table('content_items')->where('created_by', $target->id)->exists(), 'akun membuat item Work Planner'],
            ['sales_leads', fn () => DB::table('sales_leads')->where('sales_user_id', $target->id)->exists(), 'akun memiliki lead penjualan'],
            ['expenses', fn () => DB::table('expenses')->where('created_by', $target->id)->exists(), 'akun membuat pengeluaran'],
            ['dana_talangans', fn () => DB::table('dana_talangans')->where('created_by', $target->id)->exists(), 'akun membuat data Dana Talangan'],
            ['branch_user', fn () => DB::table('branch_user')->where('user_id', $target->id)->exists(), 'akun memiliki keanggotaan cabang'],
            ['project_user', fn () => DB::table('project_user')->where('user_id', $target->id)->exists(), 'akun memiliki penugasan proyek'],
            ['role_user', fn () => DB::table('role_user')->where('user_id', $target->id)->exists(), 'akun memiliki peran tambahan'],
            ['users_supervisor', fn () => DB::table('users')->where('supervisor_user_id', $target->id)->exists(), 'akun menjadi atasan pengguna lain'],
        ];

        foreach ($checks as [$table, $test, $label]) {
            if (Schema::hasTable($table) && $test()) {
                $blockers[] = $label;
            }
        }

        return $blockers;
    }

    private function revokeActiveInvitation(User $target, User $actor): void
    {
        $invitation = $target->invitations()
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->latest()
            ->first();

        if ($invitation) {
            $this->invitations->revoke($invitation, $actor);
        }
    }

    private function tombstoneEmail(User $user): string
    {
        return 'deleted+'.$user->id.'+'.Str::lower(Str::random(16)).'@invalid.oasis.local';
    }

    private function anonymousName(User $user): string
    {
        return 'Pengguna Teranomimasi #'.$user->id;
    }
}
