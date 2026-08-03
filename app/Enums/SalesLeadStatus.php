<?php

namespace App\Enums;

enum SalesLeadStatus: string
{
    case NoResponse = 'no_response';
    case Discussion = 'discussion';
    case SiteVisit = 'site_visit';
    case Freelance = 'freelance';
    case Utj = 'utj';
    case SlikCheck = 'slik_check';
    case SlikRejected = 'slik_rejected';
    case Akad = 'akad';

    public const PRIMARY_PRECEDENCE = [
        self::NoResponse,
        self::Discussion,
        self::SiteVisit,
        self::Utj,
        self::SlikCheck,
        self::SlikRejected,
        self::Akad,
    ];

    public const MANUAL = [
        self::NoResponse,
        self::Discussion,
        self::SiteVisit,
    ];

    public static function fromInput(string|self $status): self
    {
        if ($status instanceof self) {
            return $status;
        }

        $normalized = strtolower(trim($status));
        $normalized = str_replace([' ', '-'], '_', $normalized);

        return self::from(match ($normalized) {
            'cek_silk', 'cek_slik' => self::SlikCheck->value,
            default => $normalized,
        });
    }

    public function isManual(): bool
    {
        return in_array($this, self::MANUAL, true);
    }

    public function label(): string
    {
        return match ($this) {
            self::NoResponse => 'No Respon',
            self::Discussion => 'Diskusi',
            self::SiteVisit => 'Cek Lokasi',
            self::Freelance => 'Jadi Freelance',
            self::Utj => 'UTJ',
            self::SlikCheck => 'Cek SLIK',
            self::SlikRejected => 'Tidak Lolos BI Checking',
            self::Akad => 'Akad',
        };
    }

    public function spreadsheetValue(): string
    {
        return $this->label();
    }

    public function precedence(): ?int
    {
        $position = array_search($this, self::PRIMARY_PRECEDENCE, true);

        return $position === false ? null : $position;
    }
}
