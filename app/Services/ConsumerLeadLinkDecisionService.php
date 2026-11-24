<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ConsumerApplication;
use App\Models\ConsumerLeadLinkDecision;
use App\Models\SalesLead;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConsumerLeadLinkDecisionService
{
    public function __construct(
        private readonly OrganizationScopeService $organizationScope,
        private readonly BranchMigrationStateService $branchMigrationState,
    ) {}

    public function decide(
        ConsumerApplication $consumer,
        string $status,
        ?SalesLead $lead,
        array $attributes,
        User $actor,
    ): ConsumerLeadLinkDecision {
        $status = trim($status);
        $this->validateStatus($status);
        $this->branchMigrationState->assertOasisConsumerInputAllowed((int) $consumer->branch_id);
        $this->authorize($actor, $consumer);
        $reason = $this->assertReason($attributes, $status);
        $legacyAnchor = $this->legacyAnchor($attributes, $status);
        $suggestions = $this->safeSuggestions($attributes['suggestions'] ?? null);

        return DB::transaction(function () use ($consumer, $status, $lead, $actor, $reason, $legacyAnchor, $suggestions): ConsumerLeadLinkDecision {
            $lockedConsumer = ConsumerApplication::query()->lockForUpdate()->findOrFail($consumer->id);
            $this->authorize($actor, $lockedConsumer);

            $lockedLead = null;
            if ($status === ConsumerLeadLinkDecision::STATUS_LINKED) {
                if (! $lead instanceof SalesLead) {
                    throw ValidationException::withMessages(['sales_lead_id' => 'Lead wajib dipilih untuk keputusan Lead.']);
                }

                $lockedLead = SalesLead::query()->lockForUpdate()->findOrFail($lead->id);
                $this->assertLinkedLeadScope($actor, $lockedConsumer, $lockedLead);
                $this->assertLeadNotActivelyLinked($lockedLead, (int) $lockedConsumer->id);
            } elseif ($lead !== null) {
                throw ValidationException::withMessages(['sales_lead_id' => 'Lead tidak boleh dikirim untuk keputusan ini.']);
            }

            $active = ConsumerLeadLinkDecision::query()
                ->where('consumer_application_id', $lockedConsumer->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($active !== null && $this->isSameDecision($active, $status, $lockedLead?->id, $reason, $legacyAnchor)) {
                return $active->fresh(['consumerApplication', 'salesLead', 'decidedBy']);
            }

            if ($active !== null) {
                $active->update(['is_active' => false]);
            }

            $decision = ConsumerLeadLinkDecision::query()->create([
                'consumer_application_id' => $lockedConsumer->id,
                'status' => $status,
                'sales_lead_id' => $lockedLead?->id,
                'decision_source' => 'manual',
                'suggestions' => $suggestions,
                'reason' => $reason,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'is_active' => true,
            ]);

            $lockedConsumer->update([
                'sales_lead_id' => $status === ConsumerLeadLinkDecision::STATUS_LINKED ? $lockedLead->id : null,
            ]);

            $this->audit($actor, $lockedConsumer, $decision, $active, $legacyAnchor);

            return $decision->fresh(['consumerApplication', 'salesLead', 'decidedBy']);
        });
    }

    private function authorize(User $actor, ConsumerApplication $consumer): void
    {
        if (! $actor->isAccountActive()
            || ! $actor->is_active
            || ! $actor->hasVerifiedEmail()
            || $actor->must_change_password
            || $actor->password_changed_at === null) {
            throw new AuthorizationException('Akun pengguna belum memenuhi syarat untuk mengelola keputusan penautan Lead.');
        }

        $hasManagePermission = $actor->hasPermission('consumer_progress.manage')
            || $actor->hasScopedPermission('consumer_progress', 'manage');
        if (! $hasManagePermission || ! $this->organizationScope->allowsProjectRecord(
            $actor,
            'consumer_progress',
            'manage',
            (int) $consumer->branch_id,
            $consumer->project_id,
        )) {
            throw new AuthorizationException('Anda tidak berwenang mengelola keputusan penautan Lead ini.');
        }
    }

    private function validateStatus(string $status): void
    {
        if (! in_array($status, ConsumerLeadLinkDecision::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Status keputusan penautan Lead tidak valid.']);
        }
    }

    private function assertLinkedLeadScope(User $actor, ConsumerApplication $consumer, SalesLead $lead): void
    {
        if ((int) $lead->branch_id !== (int) $consumer->branch_id
            || $lead->project_id !== $consumer->project_id
            || ! $this->organizationScope->allowsProjectRecord($actor, 'sales_pocketbook', 'view', (int) $lead->branch_id, $lead->project_id)
            || ! in_array((int) $lead->sales_user_id, $this->organizationScope->visibleUserIds($actor, 'sales_pocketbook', 'view', 'sales_leads'), true)) {
            throw new AuthorizationException('Lead berada di luar lingkup akses Anda.');
        }
    }

    private function assertLeadNotActivelyLinked(SalesLead $lead, int $consumerId): void
    {
        if (ConsumerLeadLinkDecision::query()
            ->where('sales_lead_id', $lead->id)
            ->where('status', ConsumerLeadLinkDecision::STATUS_LINKED)
            ->where('is_active', true)
            ->where('consumer_application_id', '!=', $consumerId)
            ->exists()) {
            throw ValidationException::withMessages(['sales_lead_id' => 'Lead sudah tertaut ke konsumen lain.']);
        }

        $linkedApplications = ConsumerApplication::query()
            ->where('sales_lead_id', $lead->id)
            ->whereKeyNot($consumerId)
            ->lockForUpdate()
            ->get();

        if ($linkedApplications->contains(fn (ConsumerApplication $application): bool => $application->canonicalTransactionStatus() === 'LANJUT')) {
            throw ValidationException::withMessages(['sales_lead_id' => 'Lead sudah tertaut ke konsumen lain.']);
        }
    }

    private function assertReason(array $attributes, string $status): ?string
    {
        $reason = trim((string) ($attributes['reason'] ?? ''));
        if (in_array($status, [ConsumerLeadLinkDecision::STATUS_WITHOUT_LEAD, ConsumerLeadLinkDecision::STATUS_LEGACY], true)
            && $reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan wajib diisi untuk keputusan tanpa Lead.']);
        }

        if (mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['reason' => 'Alasan maksimal 2000 karakter.']);
        }

        return $reason === '' ? null : $reason;
    }

    private function legacyAnchor(array $attributes, string $status): ?string
    {
        $anchor = trim((string) ($attributes['legacy_anchor'] ?? ''));
        $allowed = ['[SYS] ID Konsumen', 'ID Konsumen Awal'];
        if ($status === ConsumerLeadLinkDecision::STATUS_LEGACY && ! in_array($anchor, $allowed, true)) {
            throw ValidationException::withMessages(['legacy_anchor' => 'Jangkar Data Lama harus [SYS] ID Konsumen atau ID Konsumen Awal.']);
        }

        return $anchor === '' ? null : $anchor;
    }

    private function safeSuggestions(mixed $suggestions): ?array
    {
        if ($suggestions === null) {
            return null;
        }
        if (! is_array($suggestions) || ! array_is_list($suggestions)) {
            throw ValidationException::withMessages(['suggestions' => 'Snapshot saran tidak valid.']);
        }

        return collect($suggestions)->map(function (mixed $suggestion): array {
            if (! is_array($suggestion)) {
                throw ValidationException::withMessages(['suggestions' => 'Snapshot saran tidak valid.']);
            }

            $snapshot = [];
            if (isset($suggestion['lead_id'])) {
                if (! is_int($suggestion['lead_id']) && ! ctype_digit((string) $suggestion['lead_id'])) {
                    throw ValidationException::withMessages(['suggestions' => 'ID Lead pada snapshot saran tidak valid.']);
                }
                $snapshot['lead_id'] = (int) $suggestion['lead_id'];
            }
            if (isset($suggestion['score'])) {
                if (! is_numeric($suggestion['score']) || (float) $suggestion['score'] < 0 || (float) $suggestion['score'] > 1) {
                    throw ValidationException::withMessages(['suggestions' => 'Skor snapshot saran harus antara 0 dan 1.']);
                }
                $snapshot['score'] = (float) $suggestion['score'];
            }
            if (isset($suggestion['rationale'])) {
                if (! is_array($suggestion['rationale'])) {
                    throw ValidationException::withMessages(['suggestions' => 'Alasan snapshot saran tidak valid.']);
                }
                $snapshot['rationale'] = collect($suggestion['rationale'])->mapWithKeys(function (mixed $value, mixed $key): array {
                    if (! is_string($key) || mb_strlen($key) > 64 || (! is_scalar($value) && $value !== null)) {
                        throw ValidationException::withMessages(['suggestions' => 'Snapshot saran memuat data tidak aman.']);
                    }

                    return [$key => $value];
                })->all();
            }

            return $snapshot;
        })->take(20)->values()->all();
    }

    private function isSameDecision(
        ConsumerLeadLinkDecision $decision,
        string $status,
        ?int $leadId,
        ?string $reason,
        ?string $legacyAnchor,
    ): bool {
        if ($decision->status !== $status
            || (int) ($decision->sales_lead_id ?? 0) !== (int) ($leadId ?? 0)
            || $decision->reason !== $reason) {
            return false;
        }

        if ($status !== ConsumerLeadLinkDecision::STATUS_LEGACY) {
            return true;
        }

        $audit = ActivityLog::query()
            ->where('subject_type', ConsumerApplication::class)
            ->where('subject_id', $decision->consumer_application_id)
            ->where('event', 'consumer_lead_link_decision_confirmed')
            ->latest('id')
            ->first();

        return data_get($audit?->properties, 'legacy_anchor') === $legacyAnchor;
    }

    private function audit(
        User $actor,
        ConsumerApplication $consumer,
        ConsumerLeadLinkDecision $decision,
        ?ConsumerLeadLinkDecision $previous,
        ?string $legacyAnchor,
    ): void {
        ActivityLog::query()->create([
            'causer_id' => $actor->id,
            'subject_type' => ConsumerApplication::class,
            'subject_id' => $consumer->id,
            'event' => 'consumer_lead_link_decision_confirmed',
            'description' => 'Keputusan penautan Lead konsumen dikonfirmasi.',
            'properties' => [
                'decision_id' => $decision->id,
                'previous_decision_id' => $previous?->id,
                'status' => $decision->status,
                'sales_lead_id' => $decision->sales_lead_id,
                'reason' => $decision->reason,
                'legacy_anchor' => $legacyAnchor,
                'decision_source' => $decision->decision_source,
                'actor_id' => $actor->id,
                'consumer_application_id' => $consumer->id,
            ],
        ]);
    }
}
