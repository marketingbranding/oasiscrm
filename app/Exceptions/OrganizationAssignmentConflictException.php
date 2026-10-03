<?php

namespace App\Exceptions;

use App\Models\OrganizationAssignment;
use Exception;

class OrganizationAssignmentConflictException extends Exception
{
    public function __construct(public readonly ?OrganizationAssignment $currentAssignment)
    {
        parent::__construct('Struktur organisasi telah berubah. Muat ulang lalu coba lagi.');
    }
}
