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

    /** @var array<int, array{title: string, description: string, mark: string, target: string}> */
    public const STEPS = [
        ['title' => 'Selamat datang di Agenda Sales', 'description' => 'Ini adalah ruang kerja harianmu untuk mencatat rencana, hasil, bukti, dan lead.', 'mark' => 'Mulai', 'target' => '[data-tour-target="workspace"]'],
        ['title' => 'Target kerja 420 menit', 'description' => 'Hari kerja mengikuti 420 menit efektif. Waktu istirahat tidak dihitung sebagai agenda.', 'mark' => '420 menit', 'target' => '[data-tour-target="work-hours"]'],
        ['title' => 'Tambah rencana hari ini', 'description' => 'Gunakan + Tambah rencana hari ini untuk mencatat kategori, judul, lokasi, dan waktu kegiatan.', 'mark' => 'Rencana', 'target' => '[data-tour-target="today-plan"]'],
        ['title' => 'Lengkapi hasil dan bukti', 'description' => 'Setelah kegiatan, isi hasil aktivitas dan unggah satu sampai dua foto bukti sebelum menyelesaikan agenda.', 'mark' => 'Bukti', 'target' => '[data-tour-target="agenda-result"]'],
        ['title' => 'Lead Saya selalu dekat', 'description' => 'Buka Lead Saya untuk menambah lead, melihat progres, dan menandai lead yang sudah selesai.', 'mark' => 'Lead', 'target' => '[data-tour-target="lead-tab"]'],
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

        return $progress === null || ! $progress->isFinished();
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
