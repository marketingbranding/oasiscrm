<?php

namespace App\Enums;

enum SalesCategory: string
{
    case Inhouse = 'INHOUSE';
    case Ojt = 'OJT';

    public function label(): string
    {
        return match ($this) {
            self::Inhouse => 'Inhouse',
            self::Ojt => 'OJT',
        };
    }
}
