<?php

namespace App\Enums;

enum DanaTalanganBridgeStatus: string
{
    case Disabled = 'disabled';
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';
    case Active = 'active';
}
