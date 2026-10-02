<?php

namespace App\Services;

use App\Models\ConsumerApplication;
use App\Models\Kavling;
use App\Models\SalesLead;
use App\Models\User;
use DomainException;

class ConsumerHandoffService
{
    public function __construct(private readonly ConsumerEntryService $entry) {}

    public function createFromLead(SalesLead $lead, array $data, User $actor): ConsumerApplication
    {
        $existing = ConsumerApplication::query()->where('sales_lead_id', $lead->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $kavlingId = null;
        if (filled($data['id_kavling'] ?? null)) {
            $kavlings = Kavling::query()->where('project_id', $lead->project_id)->where(function ($query) use ($data): void {
                $query->where('kavling_code', $data['id_kavling'])->orWhere('name', $data['id_kavling']);
            })->get();
            if ($kavlings->count() > 1) {
                throw new DomainException('ID kavling lead cocok dengan lebih dari satu unit.');
            }
            $kavlingId = $kavlings->first()?->id;
        }

        return $this->entry->create([
            'name' => $lead->customer_name,
            'phone' => $lead->phone,
            'nik' => $data['nik'] ?? null,
            'branch_id' => $lead->branch_id,
            'project_id' => $lead->project_id,
            'sales_user_id' => $lead->sales_user_id,
            'kavling_id' => $kavlingId,
            'sales_lead_id' => $lead->id,
            'payment_method' => ($data['status_cash'] ?? null) ? 'cash' : null,
            'entry_mode' => 'new',
            'acquisition_source' => 'lead',
            'notes' => $lead->notes,
        ], $actor, true);
    }
}
