<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserOnboardingProgress;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class SalesPocketbookOnboardingService
{
    public const FLOW_KEY = 'sales_pocketbook';

    public const VERSION = '1';

    /** @var array<int, array{title: string, description: string, mark: string}> */
    public const STEPS = [
        ['title' => 'Satu buku saku untuk kerjamu', 'description' => 'Agenda dan Lead Saya berada di satu tempat yang mudah dibuka dari ponsel.', 'mark' => 'Buku'],
        ['title' => 'Hari kemarin, hari ini, dan rencana', 'description' => 'Agenda langsung terlihat. Buka agenda untuk tugas, foto, dan hasil aktivitas.', 'mark' => 'Agenda'],
        ['title' => 'Tulis di struk, lalu sobek', 'description' => 'Selesaikan agenda untuk mengirim hasil aktivitas ke Koordinator Sales dan menampilkannya di daftar.', 'mark' => 'Kirim'],
        ['title' => 'Lead selesai saat kamu bilang selesai', 'description' => 'Status funnel dapat diperbarui, tetapi lead belum selesai sebelum Anda memilih Tandai selesai.', 'mark' => 'Selesai'],
    ];

    public function canAccess(User $user): bool
    {
        return $user->isAccountActive()
            && $user->hasVerifiedEmail()
            && $user->hasPrimaryRole('sales')
            && $user->hasPermission('sales_pocketbook.view_own');
    }

    public function shouldShow(User $user): bool
    {
        if (! $this->canAccess($user)) {
            return false;
        }

        $progress = $this->existingProgress($user);

        return $progress === null || ($progress->completed_at === null && $progress->skipped_at === null);
    }

    public function skip(User $user): UserOnboardingProgress
    {
        return DB::transaction(function () use ($user): UserOnboardingProgress {
            $progress = $this->progress($user);

            if ($progress->completed_at === null && $progress->skipped_at === null) {
                $progress->forceFill(['skipped_at' => now()])->save();
            }

            return $progress->fresh();
        });
    }

    public function complete(User $user, int $step = 4): UserOnboardingProgress
    {
        return DB::transaction(function () use ($user, $step): UserOnboardingProgress {
            $progress = $this->progress($user);
            $progress->forceFill([
                'current_step' => max(1, min($step, 4)),
                'completed_at' => $progress->completed_at ?? now(),
                'skipped_at' => null,
            ])->save();

            return $progress->fresh();
        });
    }

    public function replay(User $user): UserOnboardingProgress
    {
        if (! $this->canAccess($user)) {
            throw new AuthorizationException('Anda tidak memiliki akses ke onboarding Buku Saku Sales.');
        }

        $progress = $this->progress($user);
        $progress->forceFill([
            'current_step' => 1,
            'completed_at' => null,
            'skipped_at' => null,
        ])->save();

        return $progress->fresh();
    }

    private function existingProgress(User $user): ?UserOnboardingProgress
    {
        return UserOnboardingProgress::query()
            ->where('user_id', $user->id)
            ->where('flow_key', self::FLOW_KEY)
            ->where('version', self::VERSION)
            ->first();
    }

    private function progress(User $user): UserOnboardingProgress
    {
        return UserOnboardingProgress::query()->firstOrCreate([
            'user_id' => $user->id,
            'flow_key' => self::FLOW_KEY,
            'version' => self::VERSION,
        ], [
            'current_step' => 1,
        ]);
    }
}
