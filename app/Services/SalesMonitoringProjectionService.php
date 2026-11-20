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

        return match ($recordType) {
            'lead' => $this->lead($record),
            'agenda' => $this->agenda($record),
            default => $record,
        };
    }

    public function projectCollection(User $viewer, string $recordType, Collection $records, string $ownerAttribute): Collection
    {
        return $records->map(fn (mixed $record): mixed => $this->project(
            $viewer,
            (int) data_get($record, $ownerAttribute),
            $recordType,
            $record,
        ));
    }

    /** @return array{lead_date:?string, name:string, sales_name:?string, project_name:?string, stage:?string, last_active_at:?string} */
    private function lead(SalesLead|array $lead): array
    {
        $status = data_get($lead, 'current_status');
        $stage = is_object($status) && method_exists($status, 'label') ? $status->label() : $status;
        $lastActiveAt = data_get($lead, 'updated_at')
            ?? data_get($lead, 'current_status_changed_at')
            ?? data_get($lead, 'lead_date');

        return [
            'lead_date' => $this->dateString(data_get($lead, 'lead_date')),
            'name' => (string) data_get($lead, 'customer_name', ''),
            'sales_name' => data_get($lead, 'sales.name'),
            'project_name' => data_get($lead, 'project.project_name'),
            'stage' => $stage,
            'last_active_at' => $this->dateTimeString($lastActiveAt),
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

    /** @return array{sales_name:?string, date:?string, total_minutes:int, target_minutes:int, submitted:bool, activity_count:int, late_activity_count:int} */
    private function agenda(ContentItem|array $agenda): array
    {
        $dateValue = data_get($agenda, 'scheduled_date');
        $date = $dateValue instanceof CarbonImmutable
            ? $dateValue
            : ($dateValue instanceof DateTimeInterface ? CarbonImmutable::instance($dateValue) : ($dateValue !== null ? CarbonImmutable::parse((string) $dateValue) : null));
        $owner = data_get($agenda, 'owner');
        $summary = $owner instanceof User && $date instanceof CarbonImmutable
            ? $this->receiptService->summarize($owner, $date)
            : [];
        $status = (string) data_get($agenda, 'status');
        $isLate = $date instanceof CarbonImmutable
            && $date->isPast()
            && ! in_array($status, ['done', 'cancelled', 'rescheduled'], true);

        return [
            'sales_name' => data_get($agenda, 'owner.name'),
            'date' => $date?->toDateString(),
            'total_minutes' => (int) ($summary['normal_minutes'] ?? data_get($agenda, 'duration_minutes', 0)),
            'target_minutes' => (int) ($summary['target_minutes'] ?? 0),
            'submitted' => (bool) ($summary['submitted'] ?? false),
            'activity_count' => (int) ($summary['agenda_count'] ?? 1),
            'late_activity_count' => $isLate ? 1 : 0,
        ];
    }
}
