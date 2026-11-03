<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserOnboardingProgress;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class SalesPocketbookOnboardingService
{
    public const FLOW_KEY = 'sales_pocketbook';

    public const VERSION = '2';

    /** @var array<int, array{title: string, description: string, mark: string}> */
    public const STEPS = [
        ['title' => 'Selamat datang, Sales', 'description' => 'Tour singkat ini menunjukkan cara mencatat rencana kerja dan lead. Hanya sekitar satu menit.', 'mark' => 'Sambutan'],
        ['title' => 'Target harian: 420 menit', 'description' => 'Senin–Kamis 09:00–17:00 dan Jumat 08:30–17:00, dengan waktu istirahat. Rencanamu harus pas 420 menit.', 'mark' => 'Jam kerja'],
        ['title' => 'Tulis rencana, lalu sobek', 'description' => 'Tambahkan aktivitas ke struk, lengkapi sampai target, lalu kirim ke Koordinator Sales.', 'mark' => 'Tambah rencana'],
        ['title' => 'Bukti foto dan hasil per agenda', 'description' => 'Buka agenda yang berjalan, unggah bukti foto, tulis hasil aktivitas, lalu selesaikan.', 'mark' => 'Bukti'],
        ['title' => 'Catat lead di tab Lead Saya', 'description' => 'Tambah calon konsumen, perbarui statusnya, dan tekan Tandai selesai bila prosesnya tuntas.', 'mark' => 'Lead Saya'],
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

    public function complete(User $user, int $step = 5): UserOnboardingProgress
    {
        return DB::transaction(function () use ($user, $step): UserOnboardingProgress {
            $progress = $this->progress($user);
            $progress->forceFill([
                'current_step' => max(1, min($step, count(self::STEPS))),
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
