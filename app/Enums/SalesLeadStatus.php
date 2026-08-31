<?php

namespace App\Enums;

enum SalesLeadStatus: string
{
    case NoResponse = 'no_response';
    case Discussion = 'discussion';
    case FaceToFace = 'face_to_face';
    case SiteVisit = 'site_visit';
    case Freelance = 'freelance';
    case Utj = 'utj';
    case SlikCheck = 'slik_check';
    case SlikRejected = 'slik_rejected';
    case Akad = 'akad';

    public const PRIMARY_PRECEDENCE = [
        self::NoResponse,
        self::Discussion,
        self::FaceToFace,
        self::SiteVisit,
        self::Utj,
        self::SlikCheck,
        self::SlikRejected,
        self::Akad,
    ];

    public const MANUAL = [
        self::NoResponse,
        self::Discussion,
        self::FaceToFace,
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
            'no_respon', 'no_response' => self::NoResponse->value,
            'diskusi' => self::Discussion->value,
            'tatap_muka' => self::FaceToFace->value,
            'cek_lokasi' => self::SiteVisit->value,
            'cek_silk', 'cek_slik' => self::SlikCheck->value,
            'tidak_lolos_bi_checking' => self::SlikRejected->value,
            'jadi_freelance' => self::Freelance->value,
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
            self::FaceToFace => 'Tatap Muka',
            self::SiteVisit => 'Cek Lokasi',
            self::Freelance => 'Jadi Freelance',
            self::Utj => 'UTJ',
            self::SlikCheck => 'Cek Slik',
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
