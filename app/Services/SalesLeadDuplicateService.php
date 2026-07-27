<?php

namespace App\Services;

use App\Models\SalesLead;
use App\Models\User;
use Illuminate\Support\Collection;

class SalesLeadDuplicateService
{
    public function __construct(private readonly PhoneNormalizationService $phones) {}

    public function matches(User $user, ?string $phone, ?int $exceptId = null): Collection
    {
        $normalized = $this->phones->normalize($phone);
        if ($normalized === null) {
            return collect();
        }

        return SalesLead::query()->visibleTo($user)
            ->where('normalized_phone', $normalized)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->with(['sales:id,name', 'branch:id,name', 'project:id,project_name'])
            ->latest('lead_date')
            ->limit(10)
            ->get()
            ->map(fn (SalesLead $lead) => [
                'id' => $lead->id,
                'sales' => $lead->sales?->name,
                'branch' => $lead->branch?->name,
                'project' => $lead->project?->project_name,
                'date' => $lead->lead_date?->toDateString(),
            ]);
    }
}
