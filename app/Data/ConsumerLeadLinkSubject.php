<?php

namespace App\Data;

use App\Models\ConsumerApplication;
use App\Models\ConsumerNup;
use App\Models\KonsumenProgressSheetRow;
use App\Services\PhoneNormalizationService;
use Carbon\CarbonImmutable;

final readonly class ConsumerLeadLinkSubject
{
    public function __construct(
        public string $subjectType,
        public ?int $subjectId,
        public int $branchId,
        public ?int $projectId,
        public ?string $legacyConsumerKey,
        public ?int $sheetRowId,
        public ?string $name,
        public ?string $phone,
        public ?int $salesUserId,
        public ?string $kavling,
        public ?CarbonImmutable $enteredAt,
        public bool $isDecidable,
        public ?string $note = null,
    ) {}

    public static function normalizeLegacyConsumerKey(?string $value): ?string
    {
        $normalized = mb_strtolower(trim((string) $value));

        return $normalized === '' ? null : $normalized;
    }

    public function subjectKey(): string
    {
        return match ($this->subjectType) {
            'application', 'nup' => $this->subjectType.':'.$this->subjectId,
            'sheet' => 'sheet:'.$this->branchId.':'.$this->legacyConsumerKey,
            default => throw new \LogicException('Jenis subjek penautan Lead tidak valid.'),
        };
    }

    public static function fromApplication(ConsumerApplication $application): self
    {
        return new self(
            'application',
            $application->id,
            (int) $application->branch_id,
            $application->project_id === null ? null : (int) $application->project_id,
            null,
            null,
            $application->customer?->name ?: $application->nama_konsumen,
            app(PhoneNormalizationService::class)->normalize($application->customer?->phone),
            $application->sales_user_id === null ? null : (int) $application->sales_user_id,
            $application->kavling?->kavling_code ?: $application->kavling?->name ?: $application->id_kavling,
            self::date($application->booking_date ?? $application->historical_entered_at ?? $application->created_at),
            true,
        );
    }

    public static function fromNup(ConsumerNup $nup): self
    {
        return new self(
            'nup',
            $nup->id,
            (int) $nup->branch_id,
            $nup->project_id === null ? null : (int) $nup->project_id,
            null,
            null,
            $nup->customer?->name,
            app(PhoneNormalizationService::class)->normalize($nup->customer?->phone),
            isset($nup->metadata['sales_user_id']) ? (int) $nup->metadata['sales_user_id'] : null,
            data_get($nup->metadata, 'kavling'),
            self::date($nup->registered_at),
            true,
        );
    }

    public static function fromSheetRow(KonsumenProgressSheetRow $row, ?int $projectId = null, ?int $salesUserId = null): self
    {
        $data = collect($row->row_data ?? [])->mapWithKeys(fn ($value, $key): array => [mb_strtolower(trim((string) $key)) => is_scalar($value) ? trim((string) $value) : '']);
        $key = null;
        foreach (['[sys] id konsumen', 'id konsumen', 'id konsumen awal'] as $alias) {
            if (($value = $data->get($alias, '')) !== '') {
                $key = self::normalizeLegacyConsumerKey($value);
                break;
            }
        }

        $name = trim(preg_replace('/\s+/', ' ', (string) $data->get('nama konsumen', $data->get('nama', ''))));
        $name = $name === '' ? null : $name;
        $phone = app(PhoneNormalizationService::class)->normalize($data->get('no hp', $data->get('phone', null)));
        $enteredAt = self::date($data->get('tanggal', $data->get('tanggal booking', null)));

        return new self(
            'sheet',
            null,
            (int) $row->branch_id,
            $projectId,
            $key,
            $row->id,
            $name,
            $phone,
            $salesUserId,
            $data->get('kavling', $data->get('kav', null)) ?: null,
            $enteredAt,
            $key !== null,
            $key === null ? 'ID Konsumen kosong di sheet' : null,
        );
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value);
    }
}
