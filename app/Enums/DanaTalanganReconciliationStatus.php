<?php

namespace App\Enums;

enum DanaTalanganReconciliationStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Ignored = 'ignored';
}
