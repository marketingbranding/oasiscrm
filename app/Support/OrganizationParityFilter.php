<?php

namespace App\Support;

use InvalidArgumentException;

final readonly class OrganizationParityFilter
{
    public const SEVERITIES = [
        'SECURITY_CRITICAL',
        'ACCESS_LOSS',
        'STRUCTURAL',
        'HISTORICAL',
        'EXPECTED',
        'UNKNOWN',
    ];

    public const ACCESS_DIRECTIONS = [
        'EXPANSION',
        'LOSS',
        'MIXED',
        'NO_CHANGE',
        'NOT_APPLICABLE',
    ];

    public const SCOPES = [
        'own',
        'team',
        'assigned',
        'project',
        'branch',
        'descendants',
        'organization-wide',
        'system-wide',
        'historical',
    ];

    public const MODULES = [
        'sales_pocketbook',
        'consumer_progress',
        'work_planner',
        'database',
        'bridge_fund',
        'expenses',
        'reporting',
        'organization',
    ];

    public function __construct(
        public ?int $userId = null,
        public ?int $branchId = null,
        public ?string $role = null,
        public ?string $scope = null,
        public ?string $module = null,
        public bool $onlyDifferences = false,
        public ?string $severity = null,
        public ?string $classification = null,
        public ?string $accessDirection = null,
        public bool $onlyCritical = false,
        public bool $onlyUnknown = false,
        public bool $onlyExpansion = false,
        public bool $onlyLoss = false,
        public bool $onlyConflicts = false,
    ) {
        if ($this->userId !== null && $this->userId < 1) {
            throw new InvalidArgumentException('userId must be a positive integer.');
        }

        if ($this->branchId !== null && $this->branchId < 1) {
            throw new InvalidArgumentException('branchId must be a positive integer.');
        }

        if ($this->scope !== null && ! in_array($this->scope, self::SCOPES, true)) {
            throw new InvalidArgumentException('Unsupported organization parity scope.');
        }

        if ($this->module !== null && ! in_array($this->module, self::MODULES, true)) {
            throw new InvalidArgumentException('Unsupported organization parity module.');
        }

        if ($this->severity !== null && ! in_array($this->severity, self::SEVERITIES, true)) {
            throw new InvalidArgumentException('Unsupported organization parity severity.');
        }

        if ($this->accessDirection !== null && ! in_array($this->accessDirection, self::ACCESS_DIRECTIONS, true)) {
            throw new InvalidArgumentException('Unsupported organization parity access direction.');
        }

        if ($this->onlyCritical && $this->onlyUnknown) {
            throw new InvalidArgumentException('Critical and unknown parity filters cannot be combined.');
        }
    }
}
