<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\SalesLead;
use App\Models\User;
use App\Support\FeatureFlag;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

class SalesMonitoringProjectionService
{
    public function __construct(
        private readonly ReportingHierarchyService $hierarchy,
        private readonly SalesAgendaReceiptService $receiptService,
    ) {}

    public function project(User $viewer, int $ownerId, string $recordType, mixed $record): mixed
    {
        if (! FeatureFlag::enabled('org_hierarchical_monitoring')) {
            return $record;
        }

        $distance = $this->hierarchy->distance($viewer, $ownerId);
        if ($distance === null || $distance <= 1) {
            return $record;
        }

        $summary = [];
        if ($recordType === 'agenda') {
            $date = $this->agendaDate($record);
            $owner = data_get($record, 'owner');
            if ($date instanceof CarbonImmutable && $owner instanceof User) {
                $summary = $this->receiptService->summarize($owner, $date);
            }
        }

        return $this->projectRecord($recordType, $record, $summary);
    }

    public function projectCollection(User $viewer, string $recordType, Collection $records, string $ownerAttribute): Collection
    {
        if (! FeatureFlag::enabled('org_hierarchical_monitoring') || $records->isEmpty()) {
            return $records;
        }

        $distances = [];
        $agendaSummaries = [];

        return $records->map(function (mixed $record) use ($viewer, $recordType, $ownerAttribute, &$distances, &$agendaSummaries): mixed {
            $ownerId = (int) data_get($record, $ownerAttribute);
            $distances[$ownerId] ??= $this->hierarchy->distance($viewer, $ownerId);
            $distance = $distances[$ownerId];
            if ($distance === null || $distance <= 1) {
                return $record;
            }

            $summary = null;
            if ($recordType === 'agenda') {
                $date = $this->agendaDate($record);
                $key = $ownerId.'|'.($date?->toDateString() ?? '');
                if ($date instanceof CarbonImmutable && ! array_key_exists($key, $agendaSummaries)) {
                    $owner = data_get($record, 'owner');
                    $agendaSummaries[$key] = $owner instanceof User
                        ? $this->receiptService->summarize($owner, $date)
                        : [];
                }
                $summary = $agendaSummaries[$key] ?? [];
            }

            return $this->projectRecord($recordType, $record, $summary);
        });
    }

    private function projectRecord(string $recordType, mixed $record, ?array $agendaSummary = null): mixed
    {
        return match ($recordType) {
            'lead' => $this->lead($record),
            'agenda' => $this->agenda($record, $agendaSummary ?? []),
            default => $record,
        };
    }

    /** @return array{lead_date:?string, name:string, sales_name:?string, project_name:?string, stage:?string, last_active_at:?string, needs_attention:bool, has_unowned_queue:bool} */
    private function lead(SalesLead|array $lead): array
    {
        $status = data_get($lead, 'current_status');
        $stage = is_object($status) && method_exists($status, 'label') ? $status->label() : $status;
        $lastActiveAt = data_get($lead, 'latest_activity_at')
            ?? data_get($lead, 'latestStatusHistory.changed_at')
            ?? data_get($lead, 'current_status_changed_at')
            ?? data_get($lead, 'lead_date');

        return [
            'lead_date' => $this->dateString(data_get($lead, 'lead_date')),
            'name' => (string) data_get($lead, 'customer_name', ''),
            'sales_name' => data_get($lead, 'sales.name'),
            'project_name' => data_get($lead, 'project.project_name'),
            'stage' => $stage,
            'last_active_at' => $this->dateTimeString($lastActiveAt),
            'needs_attention' => (bool) data_get($lead, 'needs_attention', false),
            'has_unowned_queue' => (bool) data_get($lead, 'has_unowned_queue', false),
        ];
    }

    private function dateString(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : ($value !== null ? (string) $value : null);
    }

    private function dateTimeString(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : ($value !== null ? (string) $value : null);
    }

    /** @return array{sales_name:?string, project_name:?string, date:?string, total_minutes:int, target_minutes:int, submitted:bool, activity_count:int, late_activity_count:int} */
    private function agenda(ContentItem|array $agenda, array $summary = []): array
    {
        $date = $this->agendaDate($agenda);

        return [
            'sales_name' => data_get($agenda, 'owner.name'),
            'project_name' => data_get($agenda, 'salesProject.project_name') ?? data_get($agenda, 'project_name'),
            'date' => $date?->toDateString(),
            'total_minutes' => (int) ($summary['normal_minutes'] ?? data_get($agenda, 'duration_minutes', 0)),
            'target_minutes' => (int) ($summary['target_minutes'] ?? 0),
            'submitted' => (bool) ($summary['submitted'] ?? false),
            'activity_count' => (int) ($summary['agenda_count'] ?? 1),
            'late_activity_count' => (int) ($summary['late_activity_count'] ?? 0),
        ];
    }

    private function agendaDate(ContentItem|array $agenda): ?CarbonImmutable
    {
        $dateValue = data_get($agenda, 'scheduled_date');

        return $dateValue instanceof CarbonImmutable
            ? $dateValue
            : ($dateValue instanceof DateTimeInterface ? CarbonImmutable::instance($dateValue) : ($dateValue !== null ? CarbonImmutable::parse((string) $dateValue) : null));
    }
}
