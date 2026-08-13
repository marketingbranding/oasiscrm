<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ImpersonationService
{
    private const ORIGINAL_USER_ID = 'impersonation.original_user_id';

    private const TARGET_USER_ID = 'impersonation.target_user_id';

    private const STARTED_AT = 'impersonation.started_at';

    public function isActive(Request $request): bool
    {
        return $request->hasSession() && (
            $request->session()->has(self::ORIGINAL_USER_ID)
            || $request->session()->has(self::TARGET_USER_ID)
            || $request->session()->has(self::STARTED_AT)
        );
    }

    public function originalUser(Request $request): ?User
    {
        $id = $request->session()->get(self::ORIGINAL_USER_ID);

        return is_numeric($id) ? User::query()->with('role')->find((int) $id) : null;
    }

    public function targetUser(Request $request): ?User
    {
        $id = $request->session()->get(self::TARGET_USER_ID);

        return is_numeric($id) ? User::query()->with('role')->find((int) $id) : null;
    }

    public function canImpersonate(User $actor, User $target): bool
    {
        return $actor->isSuperadmin()
            && $this->isEligible($actor)
            && $actor->isNot($target)
            && ! $target->isSuperadmin()
            && $this->isEligible($target)
            && $target->role_id !== null;
    }

    public function assertNotImpersonating(Request $request): void
    {
        if ($this->isActive($request)) {
            throw new AuthorizationException('Sesi impersonasi sudah aktif.');
        }
    }

    public function start(Request $request, User $target): User
    {
        $this->assertNotImpersonating($request);
        $original = $request->user()?->loadMissing('role');
        $target->loadMissing('role');

        if (! $original || ! $this->canImpersonate($original, $target)) {
            throw new AuthorizationException('Impersonasi tidak diizinkan.');
        }

        $startedAt = now();
        ActivityLog::create([
            'causer_id' => $original->id,
            'subject_type' => User::class,
            'subject_id' => $target->id,
            'event' => 'user_impersonation_started',
            'description' => "Superadmin {$original->name} memulai impersonasi {$target->name}",
            'properties' => [
                'original_user_id' => $original->id,
                'target_user_id' => $target->id,
                'target_role' => $target->role->slug,
                'target_branch_id' => $target->branch_id,
                'started_at' => $startedAt->toIso8601String(),
                'ip' => Str::limit((string) $request->ip(), 45, ''),
                'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            ],
        ]);

        $request->session()->put([
            self::ORIGINAL_USER_ID => $original->id,
            self::TARGET_USER_ID => $target->id,
            self::STARTED_AT => $startedAt->toIso8601String(),
        ]);
        Auth::guard('web')->login($target, false);
        $request->session()->regenerate(true);
        $request->session()->regenerateToken();

        return $target;
    }

    public function stop(Request $request): ?User
    {
        if (! $this->isActive($request)) {
            throw new ConflictHttpException('Tidak ada sesi impersonasi aktif.');
        }

        $originalId = $request->session()->get(self::ORIGINAL_USER_ID);
        $targetId = $request->session()->get(self::TARGET_USER_ID);
        $startedAt = $request->session()->get(self::STARTED_AT);
        $current = $request->user();

        if (! is_numeric($targetId) || ! $current || $current->id !== (int) $targetId) {
            $this->failClosed($request, null, $originalId, $targetId, $startedAt);

            return null;
        }

        $target = User::query()->find((int) $targetId);
        $original = is_numeric($originalId) ? User::query()->with('role')->find((int) $originalId) : null;

        if (! $original || ! $original->isSuperadmin() || ! $this->isEligible($original)) {
            $this->failClosed($request, $target, $originalId, $targetId, $startedAt);

            return null;
        }

        $stoppedAt = now();
        ActivityLog::create([
            'causer_id' => $original->id,
            'subject_type' => User::class,
            'subject_id' => $targetId,
            'event' => 'user_impersonation_stopped',
            'description' => "Superadmin {$original->name} menghentikan impersonasi pengguna {$targetId}",
            'properties' => [
                'original_user_id' => $original->id,
                'target_user_id' => (int) $targetId,
                'duration_seconds' => $this->duration($startedAt, $stoppedAt),
                'stopped_at' => $stoppedAt->toIso8601String(),
            ],
        ]);

        Auth::guard('web')->login($original, false);
        $request->session()->regenerate(true);
        $request->session()->regenerateToken();
        $request->session()->forget([self::ORIGINAL_USER_ID, self::TARGET_USER_ID, self::STARTED_AT]);

        return $original;
    }

    private function isEligible(User $user): bool
    {
        return $user->isAccountActive()
            && $user->is_active
            && $user->hasVerifiedEmail()
            && ! $user->must_change_password;
    }

    private function failClosed(Request $request, ?User $target, mixed $originalId, mixed $targetId, mixed $startedAt): void
    {
        $stoppedAt = now();
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($target) {
            ActivityLog::create([
                'causer_id' => null,
                'subject_type' => User::class,
                'subject_id' => $target->id,
                'event' => 'user_impersonation_stop_failed',
                'description' => "Impersonasi pengguna {$target->id} dihentikan karena akun asal tidak valid",
                'properties' => [
                    'original_user_id' => is_numeric($originalId) ? (int) $originalId : null,
                    'target_user_id' => is_numeric($targetId) ? (int) $targetId : $target->id,
                    'duration_seconds' => $this->duration($startedAt, $stoppedAt),
                    'stopped_at' => $stoppedAt->toIso8601String(),
                ],
            ]);
        }
    }

    private function duration(mixed $startedAt, $stoppedAt): ?int
    {
        try {
            return is_string($startedAt) ? max(0, (int) now()->parse($startedAt)->diffInSeconds($stoppedAt)) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
