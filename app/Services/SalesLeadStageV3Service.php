<?php

namespace App\Services;

use App\Enums\SalesLeadStatus;
use App\Models\SalesLead;
use App\Models\SalesLeadStageHistory;
use App\Models\User;
use App\Support\FeatureFlag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesLeadStageV3Service
{
    public function __construct(
        private readonly SalesLeadLifecycleService $legacyLifecycle,
        private readonly AppSettingResolver $settings,
    ) {}

    public function setStage(SalesLead $lead, string $stage, User $actor, ?string $operationUuid = null): SalesLead
    {
        return $this->transition($lead, $stage, 'web', null, $actor, $operationUuid);
    }

    public function correctStage(SalesLead $lead, string $stage, string $reason, User $actor, ?string $operationUuid = null): SalesLead
    {
        if (blank(trim($reason))) {
            throw ValidationException::withMessages(['reason' => 'Alasan Koreksi Data wajib diisi.']);
        }

        return $this->transition($lead, $stage, 'correction', $reason, $actor, $operationUuid);
    }

    public function closeAsGugur(SalesLead $lead, string $reason, User $actor, ?string $operationUuid = null): SalesLead
    {
        if (! FeatureFlag::enabled('lead_stages_v3')) {
            throw ValidationException::withMessages(['stage' => 'Tahap Lead v3 belum diaktifkan.']);
        }

        if (! in_array($reason, $this->settings->list('sales_lead.gugur_reasons'), true)) {
            throw ValidationException::withMessages(['reason' => 'Alasan Gugur tidak terdaftar di Pengaturan.']);
        }

        return DB::transaction(function () use ($lead, $reason, $actor, $operationUuid): SalesLead {
            $locked = SalesLead::query()->lockForUpdate()->findOrFail($lead->id);
            $this->assertOperationAvailable($locked, $operationUuid);
            if ($this->stagePosition($locked->resolveStageV3()) >= $this->stagePosition('utj')) {
                throw ValidationException::withMessages(['stage' => 'Lead setelah UTJ tidak dapat ditutup sebagai Gugur.']);
            }

            $changedAt = now();
            $this->writeTransition($locked, 'gugur', $actor, $changedAt, $operationUuid, 'web', $reason);
            $locked->update([
                'gugur_at' => $changedAt,
                'gugur_reason' => $reason,
                'current_status' => SalesLeadStatus::NoResponse,
                'current_status_changed_at' => $changedAt,
                'current_status_source' => 'web',
                'current_status_source_id' => (string) $actor->id,
                'updated_by' => $actor->id,
            ]);
            $locked->logSalesActivity('gugur', [
                'branch_id' => $locked->branch_id,
                'project_id' => $locked->project_id,
                'sales_user_id' => $locked->sales_user_id,
                'stage' => 'gugur',
                'stage_label' => SalesLead::STAGE_V3_LABELS['gugur'],
            ]);

            return $locked->fresh();
        });
    }

    private function transition(SalesLead $lead, string $stage, string $source, ?string $reason, User $actor, ?string $operationUuid): SalesLead
    {
        if (! FeatureFlag::enabled('lead_stages_v3')) {
            throw ValidationException::withMessages(['stage' => 'Tahap Lead v3 belum diaktifkan.']);
        }
        if (! in_array($stage, SalesLead::STAGE_V3_ORDER, true) || $stage === 'gugur') {
            throw ValidationException::withMessages(['stage' => 'Tahap Lead tidak valid.']);
        }
        if ($source === 'web' && $this->stagePosition($stage) < $this->stagePosition($lead->resolveStageV3())) {
            throw ValidationException::withMessages(['stage' => 'Mundur tahap hanya dapat dilakukan lewat Koreksi Data.']);
        }

        return DB::transaction(function () use ($lead, $stage, $source, $reason, $actor, $operationUuid): SalesLead {
            $locked = SalesLead::query()->lockForUpdate()->findOrFail($lead->id);
            $this->assertOperationAvailable($locked, $operationUuid);
            if ($locked->resolveStageV3() === 'gugur') {
                throw ValidationException::withMessages(['stage' => 'Lead Gugur tidak dapat diubah.']);
            }
            if ($source === 'web' && $this->stagePosition($stage) < $this->stagePosition($locked->resolveStageV3())) {
                throw ValidationException::withMessages(['stage' => 'Mundur tahap hanya dapat dilakukan lewat Koreksi Data.']);
            }

            $changedAt = now();
            $this->writeTransition($locked, $stage, $actor, $changedAt, $operationUuid, $source, $reason);
            $legacyStatus = match ($stage) {
                'lead_baru' => SalesLeadStatus::NoResponse,
                'dihubungi' => SalesLeadStatus::Discussion,
                'tatap_muka' => SalesLeadStatus::FaceToFace,
                'survey' => SalesLeadStatus::SiteVisit,
                'utj' => SalesLeadStatus::Utj,
            };
            $locked->update([
                'lead_stage' => $stage,
                'lead_stage_changed_at' => $changedAt,
                'current_status' => $legacyStatus,
                'current_status_changed_at' => $changedAt,
                'current_status_source' => $source,
                'current_status_source_id' => (string) $actor->id,
                'updated_by' => $actor->id,
            ]);
            $this->legacyLifecycle->recordStatusHistory($locked, $legacyStatus, $source, (string) $actor->id, $actor, $changedAt, ['reason' => $reason], $operationUuid);

            return $locked->fresh();
        });
    }

    private function writeTransition(SalesLead $lead, string $stage, User $actor, mixed $changedAt, ?string $operationUuid, string $source, ?string $reason): SalesLeadStageHistory
    {
        return SalesLeadStageHistory::query()->create([
            'sales_lead_id' => $lead->id,
            'branch_id' => $lead->branch_id,
            'actor_id' => $actor->id,
            'from_stage' => $lead->resolveStageV3(),
            'to_stage' => $stage,
            'source' => $source,
            'source_id' => (string) $actor->id,
            'operation_uuid' => $operationUuid ?: (string) Str::uuid(),
            'reason' => $reason,
            'changed_at' => $changedAt,
        ]);
    }

    private function assertOperationAvailable(SalesLead $lead, ?string $operationUuid): void
    {
        if ($operationUuid === null) {
            return;
        }

        $existing = SalesLeadStageHistory::query()
            ->where('branch_id', $lead->branch_id)
            ->where('operation_uuid', $operationUuid)
            ->first();
        if ($existing !== null && (int) $existing->sales_lead_id !== (int) $lead->id) {
            throw ValidationException::withMessages(['operation_uuid' => 'Identitas operasi sudah digunakan oleh Lead lain.']);
        }
    }

    private function stagePosition(string $stage): int
    {
        return array_search($stage, SalesLead::STAGE_V3_ORDER, true);
    }
}
