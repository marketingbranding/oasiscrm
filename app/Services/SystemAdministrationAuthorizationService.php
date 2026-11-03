<?php

namespace App\Services;

use App\Models\User;

final class SystemAdministrationAuthorizationService
{
    public const BRANCHES = 'branches';

    public const PROJECTS = 'projects';

    public const KAVLINGS = 'kavlings';

    public const ROLES = 'roles';

    public const PERMISSIONS = 'permissions';

    private const AREAS = [
        self::BRANCHES,
        self::PROJECTS,
        self::KAVLINGS,
        self::ROLES,
        self::PERMISSIONS,
    ];

    public function canManage(User $actor, string $area): bool
    {
        return $actor->isAccountActive()
            && $actor->is_active
            && $actor->isSuperadmin()
            && in_array($area, self::AREAS, true);
    }

    public function assertCanManage(User $actor, string $area): void
    {
        abort_unless($this->canManage($actor, $area), 403, 'Akses administrasi sistem tidak diizinkan.');
    }
}
