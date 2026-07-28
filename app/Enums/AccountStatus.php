<?php

namespace App\Enums;

enum AccountStatus: string
{
    case PendingInvitation = 'pending_invitation';
    case Invited = 'invited';
    case Active = 'active';
    case Suspended = 'suspended';
    case Inactive = 'inactive';
}
