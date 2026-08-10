<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\UserInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class UserInvitationService
{
    public function __construct(private readonly AccountAuditService $audit) {}

    public function createDraft(array $attributes, User $creator): User
    {
        return User::create(array_merge($attributes, [
            'password' => Str::random(64),
            'account_status' => AccountStatus::PendingInvitation,
            'must_change_password' => false,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]));
    }

    public function send(User $user, User $inviter, ?\DateTimeInterface $expiresAt = null): UserInvitation
    {
        return $this->issue($user, $inviter, false, $expiresAt);
    }

    public function resend(User $user, User $inviter, ?\DateTimeInterface $expiresAt = null): UserInvitation
    {
        return $this->issue($user, $inviter, true, $expiresAt);
    }

    public function revoke(UserInvitation $invitation, User $actor): void
    {
        if ($invitation->accepted_at || $invitation->revoked_at) {
            return;
        }

        $invitation->update(['revoked_at' => now()]);
        $this->audit->log('invitation_revoked', $invitation->user, $actor);
    }

    public function findByToken(string $rawToken): ?UserInvitation
    {
        return UserInvitation::with(['user', 'inviter'])
            ->where('token_hash', hash('sha256', $rawToken))
            ->first();
    }

    public function accept(string $rawToken, string $password): User
    {
        return DB::transaction(function () use ($rawToken, $password) {
            $invitation = UserInvitation::where('token_hash', hash('sha256', $rawToken))
                ->lockForUpdate()
                ->first();

            if (! $invitation || ! $invitation->isUsable()) {
                throw new \DomainException('Undangan tidak dapat digunakan.');
            }

            $user = User::whereKey($invitation->user_id)->lockForUpdate()->firstOrFail();
            if (! in_array($user->account_status, [AccountStatus::PendingInvitation, AccountStatus::Invited], true)) {
                throw new \DomainException('Undangan tidak dapat digunakan.');
            }

            $user->forceFill([
                'password' => $password,
                'email_verified_at' => now(),
                'account_status' => AccountStatus::Active,
                'activated_at' => now(),
                'password_changed_at' => now(),
                'must_change_password' => false,
                'updated_by' => $invitation->invited_by,
            ])->save();
            $invitation->update(['accepted_at' => now()]);
            UserInvitation::where('user_id', $user->id)
                ->whereKeyNot($invitation->id)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $this->audit->log('invitation_activated', $user, $user, ['account_status' => AccountStatus::Invited->value], ['account_status' => AccountStatus::Active->value]);

            return $user;
        });
    }

    private function issue(User $user, User $inviter, bool $resend, ?\DateTimeInterface $expiresAt): UserInvitation
    {
        if (! in_array($user->account_status, [AccountStatus::PendingInvitation, AccountStatus::Invited], true)) {
            throw new \DomainException('Hanya akun yang menunggu aktivasi yang dapat diundang.');
        }

        $rawToken = Str::random(64);
        $invitation = DB::transaction(function () use ($user, $inviter, $rawToken, $expiresAt) {
            UserInvitation::where('user_id', $user->id)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $invitation = UserInvitation::create([
                'user_id' => $user->id,
                'invited_by' => $inviter->id,
                'token_hash' => hash('sha256', $rawToken),
                'expires_at' => $expiresAt ?? now()->addHours(72),
            ]);
            $user->update([
                'account_status' => AccountStatus::Invited,
                'invited_at' => now(),
                'updated_by' => $inviter->id,
            ]);

            return $invitation;
        });

        try {
            $user->notify(new UserInvitationNotification($rawToken, $invitation, $inviter));
        } catch (Throwable $exception) {
            report($exception);
            throw new \RuntimeException('Undangan tersimpan, tetapi email gagal dikirim. Silakan kirim ulang.', 0, $exception);
        }

        $invitation->update(['sent_at' => now()]);
        $this->audit->log($resend ? 'invitation_resent' : 'invitation_sent', $user, $inviter, [], [
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]);

        return $invitation;
    }
}
