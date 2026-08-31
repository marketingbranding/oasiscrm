<?php

namespace App\Services;

use App\Models\SalesLead;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class CoordinatorLeadPushService
{
    public function __construct(
        private readonly CoordinatorLeadTeamService $teams,
        private readonly SalesLeadSpreadsheetWriter $writer,
        private readonly SalesLeadService $leads,
        private readonly SalesLeadBridgeModeService $bridgeModes,
        private readonly SalesLeadBridgeService $bridge,
        private readonly WorkspaceAccessService $workspaceAccess,
    ) {}

    public function push(User $coordinator, ?int $branchId = null): array
    {
        if (! $this->teams->isCoordinator($coordinator)) {
            throw new \DomainException('Hanya koordinator Sales yang dapat mengirim lead tim.');
        }

        if ($branchId !== null && ! $this->workspaceAccess->canViewBranch($coordinator, $branchId)) {
            throw new \DomainException('Cabang tidak dapat diakses.');
        }

        $salesIds = $this->teams->currentSales($coordinator)->pluck('id');
        $query = SalesLead::query()
            ->whereIn('sales_user_id', $salesIds)
            ->whereIn('sync_status', ['pending_create', 'pending_update', 'sync_failed'])
            ->whereHas('branch', fn (Builder $query) => $query->where('is_active', true)->whereNotNull('sheet_id')->where('sheet_id', '!=', ''))
            ->whereIn('branch_id', $this->workspaceAccess->accessibleBranchIds($coordinator))
            ->when($branchId !== null, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->orderBy('id');

        $result = ['processed' => 0, 'synced' => 0, 'failed' => 0];
        foreach ($query->cursor() as $lead) {
            $result['processed']++;
            try {
                $lead->loadMissing('branch');
                if ($this->bridgeModes->isPushEnabled($lead->branch)) {
                    $outcome = $this->bridge->push($lead, $coordinator);
                    $result[$outcome['ok'] ?? false ? 'synced' : 'failed']++;

                    continue;
                }
                $write = $lead->last_synced_at
                    ? $this->writer->updateBySyncId($lead, 'lead', $lead->external_sync_id, $this->leads->spreadsheetFields($lead))
                    : $this->writer->append($lead, 'lead', $this->leads->spreadsheetFields($lead), $lead->external_sync_id);
                $externalLeadId = trim((string) ($write->rowValues['id_lead'] ?? ''));
                if ($externalLeadId !== '' && SalesLead::query()->where('branch_id', $lead->branch_id)->where('external_lead_id', $externalLeadId)->whereKeyNot($lead->id)->exists()) {
                    throw new \DomainException('ID lead dari spreadsheet sudah digunakan pada cabang ini.');
                }
                $lead->update([
                    'external_lead_id' => $externalLeadId !== '' ? $externalLeadId : $lead->external_lead_id,
                    'sync_status' => 'synced',
                    'last_synced_at' => now(),
                    'last_sync_error' => null,
                ]);
                $result['synced']++;
            } catch (Throwable $exception) {
                report($exception);
                $lead->update(['sync_status' => 'sync_failed', 'last_sync_error' => 'Sinkronisasi spreadsheet gagal.']);
                $result['failed']++;
            }
        }

        return $result;
    }
}
