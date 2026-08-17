<?php

namespace App\Services;

use App\Data\ConsumerComparisonRecord;
use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\LeadMaster;

final class LocalConsumerReadService
{
    /** @return array<int, ConsumerComparisonRecord> */
    public function records(Branch $branch, LeadMaster $project): array
    {
        $applications = ConsumerApplication::query()
            ->with(['customer', 'sales', 'kavling', 'legacyIdentities', 'stageEvents', 'bankProcesses'])
            ->where('branch_id', $branch->id)
            ->where('project_id', $project->id)
            ->get();

        return $applications->map(function (ConsumerApplication $application) use ($branch) {
            $identity = $application->legacyIdentities->whereNotNull('external_key')->sortByDesc('updated_at')->first();
            $stage = $application->current_stage ?: $application->stageEvents->sortByDesc('occurred_at')->first()?->stage;
            $bank = $application->bankProcesses->sort(function ($left, $right): int {
                $leftKey = [$left->submitted_at?->timestamp ?? PHP_INT_MIN, $left->updated_at?->timestamp ?? PHP_INT_MIN, $left->id];
                $rightKey = [$right->submitted_at?->timestamp ?? PHP_INT_MIN, $right->updated_at?->timestamp ?? PHP_INT_MIN, $right->id];

                return $rightKey <=> $leftKey;
            })->first();

            return new ConsumerComparisonRecord(
                legacyKey: $identity?->external_key ?? 'local:'.$application->id,
                localApplicationId: $application->id,
                customerName: $application->customer?->name,
                phone: $this->phone($application->customer?->phone),
                branchId: $branch->id,
                projectId: $application->project_id,
                salesLabel: $application->sales?->name,
                salesUserId: $application->sales_user_id,
                kavlingLabel: $application->kavling?->kavling_code ?: $application->kavling?->name,
                kavlingId: $application->kavling_id,
                applicationStatus: $application->application_status,
                currentStage: $stage,
                bookingDate: $application->booking_date?->format('Y-m-d'),
                akadDate: $application->akad_date?->format('Y-m-d'),
                bankName: $bank?->bank_name,
                bankStatus: $bank?->status,
                values: ['provenance' => $application->legacyIdentities->map(fn ($identity) => ['external_key' => $identity->external_key, 'legacy_source' => $identity->legacy_source, 'application_id' => $identity->consumer_application_id])->values()->all()],
            );
        })->all();
    }

    private function phone(?string $value): ?string
    {
        $phone = preg_replace('/[^0-9+]/', '', (string) $value);
        if (str_starts_with($phone, '+62')) {
            return '0'.substr($phone, 3);
        }
        if (str_starts_with($phone, '62')) {
            return '0'.substr($phone, 2);
        }

        return $phone ?: null;
    }
}
