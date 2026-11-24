<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\BranchMigrationState;
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

    private const MAX_REASON_LENGTH = 2000;

    private const MAX_SUGGESTION_COUNT = 20;

    private const MAX_RATIONALE_KEYS = 12;

    private const MAX_RATIONALE_KEY_LENGTH = 64;

    private const MAX_RATIONALE_VALUE_LENGTH = 200;

    private const SAFE_REASON_PATTERN = '/^[\\p{L}\\p{N}\\s.,:;!?()\\[\\]\\/_+%\'-]+$/u';

    public function decide(
        ConsumerApplication $consumer,
        string $status,
        ?SalesLead $lead,
        array $attributes,
        User $actor,
    ): ConsumerLeadLinkDecision {
        $status = trim($status);
        $this->validateStatus($status);
        $this->validateLeadArgument($lead, $status);
        $reason = $this->assertReason($attributes, $status);
        $legacyAnchor = $this->legacyAnchor($attributes, $status);
        $suggestions = $this->safeSuggestions($attributes['suggestions'] ?? null);

        return DB::transaction(function () use ($consumer, $status, $lead, $actor, $reason, $legacyAnchor, $suggestions): ConsumerLeadLinkDecision {
            $lockedConsumer = ConsumerApplication::query()->lockForUpdate()->findOrFail($consumer->id);
            Branch::query()->whereKey($lockedConsumer->branch_id)->lockForUpdate()->firstOrFail();
            BranchMigrationState::query()
                ->where('branch_id', $lockedConsumer->branch_id)
                ->lockForUpdate()
                ->first();
            $this->branchMigrationState->assertOasisConsumerInputAllowed((int) $lockedConsumer->branch_id);
            $this->authorize($actor, $lockedConsumer);
            $this->assertSuggestionScope($actor, $lockedConsumer, $suggestions);

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
                $expectedSalesLeadId = $lockedLead?->id;
                if ((int) ($lockedConsumer->sales_lead_id ?? 0) !== (int) ($expectedSalesLeadId ?? 0)) {
                    $lockedConsumer->update(['sales_lead_id' => $expectedSalesLeadId]);
                }

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

    private function validateLeadArgument(?SalesLead $lead, string $status): void
    {
        if ($status === ConsumerLeadLinkDecision::STATUS_LINKED && ! $lead instanceof SalesLead) {
            throw ValidationException::withMessages(['sales_lead_id' => 'Lead wajib dipilih untuk keputusan Lead.']);
        }

        if ($status !== ConsumerLeadLinkDecision::STATUS_LINKED && $lead instanceof SalesLead) {
            throw ValidationException::withMessages(['sales_lead_id' => 'Lead tidak boleh dikirim untuk keputusan ini.']);
        }
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

    private function assertSuggestionScope(User $actor, ConsumerApplication $consumer, ?array $suggestions): void
    {
        foreach ($suggestions ?? [] as $suggestion) {
            if (! isset($suggestion['lead_id'])) {
                continue;
            }

            $lead = SalesLead::query()->find($suggestion['lead_id']);
            if (! $lead instanceof SalesLead) {
                throw ValidationException::withMessages(['suggestions' => 'Lead pada snapshot saran tidak ditemukan.']);
            }

            $this->assertLinkedLeadScope($actor, $consumer, $lead);
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
        $rawReason = $attributes['reason'] ?? null;
        if ($rawReason !== null && ! is_string($rawReason)) {
            throw ValidationException::withMessages(['reason' => 'Alasan harus berupa teks.']);
        }

        $reason = trim($rawReason ?? '');
        if (in_array($status, [ConsumerLeadLinkDecision::STATUS_WITHOUT_LEAD, ConsumerLeadLinkDecision::STATUS_LEGACY], true)
            && $reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan wajib diisi untuk keputusan tanpa Lead.']);
        }

        if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw ValidationException::withMessages(['reason' => 'Alasan maksimal 2000 karakter.']);
        }

        if ($reason !== '' && preg_match(self::SAFE_REASON_PATTERN, $reason) !== 1) {
            throw ValidationException::withMessages(['reason' => 'Alasan memuat karakter yang tidak didukung.']);
        }

        return $reason === '' ? null : $reason;
    }

    private function legacyAnchor(array $attributes, string $status): ?string
    {
        $rawAnchor = $attributes['legacy_anchor'] ?? null;
        if ($rawAnchor !== null && ! is_string($rawAnchor)) {
            throw ValidationException::withMessages(['legacy_anchor' => 'Jangkar Data Lama tidak valid.']);
        }

        $anchor = trim($rawAnchor ?? '');
        $allowed = ['[SYS] ID Konsumen', 'ID Konsumen Awal'];
        if ($status !== ConsumerLeadLinkDecision::STATUS_LEGACY && $anchor !== '') {
            throw ValidationException::withMessages(['legacy_anchor' => 'Jangkar Data Lama hanya boleh untuk keputusan Data Lama.']);
        }
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
        if (! is_array($suggestions) || ! array_is_list($suggestions) || count($suggestions) > self::MAX_SUGGESTION_COUNT) {
            throw ValidationException::withMessages(['suggestions' => 'Snapshot saran tidak valid atau terlalu besar.']);
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
                if (! is_array($suggestion['rationale']) || count($suggestion['rationale']) > self::MAX_RATIONALE_KEYS) {
                    throw ValidationException::withMessages(['suggestions' => 'Alasan snapshot saran tidak valid atau terlalu besar.']);
                }
                $snapshot['rationale'] = collect($suggestion['rationale'])->mapWithKeys(function (mixed $value, mixed $key): array {
                    if (! is_string($key)
                        || mb_strlen($key) > self::MAX_RATIONALE_KEY_LENGTH
                        || (! is_scalar($value) && $value !== null)
                        || ($value !== null && mb_strlen((string) $value) > self::MAX_RATIONALE_VALUE_LENGTH)) {
                        throw ValidationException::withMessages(['suggestions' => 'Snapshot saran memuat data tidak aman.']);
                    }

                    return [$key => $value];
                })->all();
            }

            return $snapshot;
        })->values()->all();
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
                'reason_present' => $decision->reason !== null,
                'legacy_anchor' => $legacyAnchor,
                'decision_source' => $decision->decision_source,
                'actor_id' => $actor->id,
                'consumer_application_id' => $consumer->id,
            ],
        ]);
    }
}
