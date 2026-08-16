<?php

namespace App\Data;

final readonly class ConsumerComparisonRecord
{
    public function __construct(
        public string $legacyKey,
        public ?int $localApplicationId,
        public ?string $customerName,
        public ?string $phone,
        public ?int $branchId,
        public ?int $projectId,
        public ?string $salesLabel,
        public ?int $salesUserId,
        public ?string $kavlingLabel,
        public ?int $kavlingId,
        public ?string $applicationStatus,
        public ?string $currentStage,
        public ?string $bookingDate,
        public ?string $akadDate,
        public ?string $bankName,
        public ?string $bankStatus,
        public array $values = [],
        public array $notes = [],
    ) {}

    public function toArray(): array
    {
        return [
            'legacy_key' => $this->legacyKey,
            'local_application_id' => $this->localApplicationId,
            'customer_name' => $this->customerName,
            'phone' => $this->phone,
            'branch_id' => $this->branchId,
            'project_id' => $this->projectId,
            'sales_label' => $this->salesLabel,
            'sales_user_id' => $this->salesUserId,
            'kavling_label' => $this->kavlingLabel,
            'kavling_id' => $this->kavlingId,
            'application_status' => $this->applicationStatus,
            'current_stage' => $this->currentStage,
            'booking_date' => $this->bookingDate,
            'akad_date' => $this->akadDate,
            'bank_name' => $this->bankName,
            'bank_status' => $this->bankStatus,
            'values' => $this->values,
            'notes' => $this->notes,
        ];
    }
}
