<?php

namespace App\Services;

use App\Models\ConsumerApplication;
use App\Models\ConsumerKavlingAssignment;
use App\Models\Kavling;
use DomainException;
use Illuminate\Support\Facades\DB;

final class ConsumerKavlingLifecycleService
{
    public function availability(Kavling $kavling): string
    {
        $assignment = $this->currentAssignment($kavling);
        if ($assignment === null) {
            return 'AVAILABLE';
        }

        return $assignment->assignment_status === 'sold' || $this->hasReachedAkad($assignment->application) ? 'SOLD' : 'RESERVED';
    }

    public function assign(ConsumerApplication $application, Kavling $kavling): ConsumerKavlingAssignment
    {
        return DB::transaction(function () use ($application, $kavling): ConsumerKavlingAssignment {
            $application = ConsumerApplication::query()->lockForUpdate()->findOrFail($application->id);
            $kavling = Kavling::query()->lockForUpdate()->findOrFail($kavling->id);
            $current = $this->currentAssignment($application->kavling_id ? $application->kavling : null);

            if ($current !== null) {
                if ((int) $current->consumer_application_id === (int) $application->id && (int) $current->kavling_id === (int) $kavling->id) {
                    return $current;
                }
                throw new DomainException('Aplikasi sudah memiliki kavling aktif.');
            }
            if ($this->currentAssignment($kavling) !== null) {
                throw new DomainException('Kavling sudah memiliki assignment aktif.');
            }

            $assignment = ConsumerKavlingAssignment::create([
                'consumer_application_id' => $application->id,
                'kavling_id' => $kavling->id,
                'assigned_at' => now(),
                'assignment_status' => 'active',
            ]);
            $application->update(['kavling_id' => $kavling->id]);

            return $assignment;
        });
    }

    public function mundur(ConsumerApplication $application): void
    {
        DB::transaction(function () use ($application): void {
            $application = ConsumerApplication::query()->lockForUpdate()->findOrFail($application->id);
            $assignment = $this->lockCurrentAssignment($application);
            if ($assignment !== null) {
                $assignment->update(['assignment_status' => 'released', 'released_at' => now(), 'release_reason' => 'mundur']);
            }
            $application->update(['consumer_status' => 'Mundur', 'kavling_id' => null]);
        });
    }

    public function pindahKavling(ConsumerApplication $application, Kavling $target): ConsumerKavlingAssignment
    {
        return DB::transaction(function () use ($application, $target): ConsumerKavlingAssignment {
            $application = ConsumerApplication::query()->lockForUpdate()->findOrFail($application->id);
            $target = Kavling::query()->lockForUpdate()->findOrFail($target->id);
            $old = $this->lockCurrentAssignment($application);
            $occupied = $this->currentAssignment($target);
            if ($occupied !== null && (int) $occupied->consumer_application_id !== (int) $application->id) {
                throw new DomainException('Kavling tujuan sudah memiliki assignment aktif.');
            }
            if ($old !== null && (int) $old->kavling_id === (int) $target->id) {
                return $old;
            }
            if ($old !== null) {
                $old->update(['assignment_status' => 'released', 'released_at' => now(), 'release_reason' => 'pindah_kavling']);
            }
            $assignment = ConsumerKavlingAssignment::create([
                'consumer_application_id' => $application->id,
                'kavling_id' => $target->id,
                'assigned_at' => now(),
                'assignment_status' => 'active',
            ]);
            $application->update(['kavling_id' => $target->id, 'consumer_status' => 'Pindah Kavling']);

            return $assignment;
        });
    }

    public function ensureSold(ConsumerApplication $application): void
    {
        DB::transaction(function () use ($application): void {
            $application = ConsumerApplication::query()->lockForUpdate()->findOrFail($application->id);
            $assignment = $this->lockCurrentAssignment($application);
            if ($assignment !== null && $assignment->assignment_status === 'active') {
                $assignment->update(['assignment_status' => 'sold']);
            }
        });
    }

    public function markAkad(ConsumerApplication $application): void
    {
        DB::transaction(function () use ($application): void {
            $application = ConsumerApplication::query()->lockForUpdate()->findOrFail($application->id);
            $assignment = $this->lockCurrentAssignment($application);
            if ($assignment !== null && $assignment->assignment_status === 'active') {
                $assignment->update(['assignment_status' => 'sold']);
            }
            $application->update(['current_stage' => 'akad', 'akad_date' => $application->akad_date ?? now()->toDateString()]);
        });
    }

    private function currentAssignment(?Kavling $kavling): ?ConsumerKavlingAssignment
    {
        return $kavling?->consumerAssignments()->whereIn('assignment_status', ['active', 'sold'])->whereNull('released_at')->latest('id')->first();
    }

    private function lockCurrentAssignment(ConsumerApplication $application): ?ConsumerKavlingAssignment
    {
        return ConsumerKavlingAssignment::query()->where('consumer_application_id', $application->id)->whereIn('assignment_status', ['active', 'sold'])->whereNull('released_at')->latest('id')->lockForUpdate()->first();
    }

    private function hasReachedAkad(?ConsumerApplication $application): bool
    {
        return $application !== null && ($application->current_stage === 'akad' || $application->akad_date !== null || $application->stageEvents()->where('stage', 'akad')->exists());
    }
}
