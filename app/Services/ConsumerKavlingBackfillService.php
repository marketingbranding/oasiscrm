<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\ConsumerStageEvent;

class ConsumerKavlingBackfillService
{
    public function preview(Branch $branch): array
    {
        return $this->analyze($branch);
    }

    public function analyze(Branch $branch): array
    {
        $applications = ConsumerApplication::query()
            ->where('branch_id', $branch->id)
            ->with(['stageEvents'])
            ->get();

        $stats = [
            'TOTAL_CANDIDATES' => $applications->count(),
            'READY_RESERVED' => 0,
            'READY_SOLD' => 0,
            'ALREADY_BACKFILLED' => 0,
            'CONFLICT' => 0,
            'SKIPPED' => 0,
        ];

        $kavlingCounts = $applications
            ->map(fn (ConsumerApplication $app) => trim((string) $app->id_kavling))
            ->filter(fn (string $code) => $code !== '')
            ->countBy()
            ->all();

        $conflicts = [];
        foreach ($kavlingCounts as $code => $count) {
            if ($count > 1) {
                $conflicts[$code] = $count;
            }
        }

        foreach ($applications as $app) {
            $code = trim((string) $app->id_kavling);
            if ($code === '') {
                $stats['SKIPPED']++;

                continue;
            }
            if (isset($conflicts[$code])) {
                $stats['CONFLICT']++;

                continue;
            }
            $isSold = $app->stageEvents->contains(fn (ConsumerStageEvent $event) => in_array(strtolower($event->stage), ['akad', 'bast'], true));
            if ($isSold) {
                $stats['READY_SOLD']++;
            } else {
                $stats['READY_RESERVED']++;
            }
        }

        return $stats;
    }
}
