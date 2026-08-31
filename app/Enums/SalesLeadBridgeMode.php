<?php

namespace App\Enums;

enum SalesLeadBridgeMode: string
{
    case Off = 'off';
    case PushOnly = 'push_only';
    case Bidirectional = 'bidirectional';

    public function pushEnabled(): bool
    {
        return $this !== self::Off;
    }

    public function pullEnabled(): bool
    {
        return $this === self::Bidirectional;
    }
}
