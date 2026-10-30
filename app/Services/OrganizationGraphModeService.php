<?php

namespace App\Services;

use App\Models\User;

final class OrganizationGraphModeService
{
    private const MODES = ['legacy', 'shadow', 'graph'];

    public function configuredMode(): string
    {
        return match (strtolower(trim((string) config('organization.graph_mode', 'legacy')))) {
            'shadow' => 'shadow',
            'graph', 'canonical' => 'graph',
            default => 'legacy',
        };
    }

    public function modeFor(User $viewer, ?int $branchId = null): string
    {
        $configured = $this->configuredMode();
        if ($configured === 'legacy') {
            return 'legacy';
        }

        if (! $this->safeEnvironment()) {
            return 'legacy';
        }

        if ($configured === 'shadow') {
            return 'shadow';
        }

        if ($this->environment() === 'testing') {
            return 'graph';
        }

        $diagnostics = $this->activationDiagnostics($viewer, $branchId);

        return $diagnostics['matched_by'] !== null ? 'graph' : 'legacy';
    }

    public function isLegacy(User $viewer, ?int $branchId = null): bool
    {
        return $this->modeFor($viewer, $branchId) === 'legacy';
    }

    public function isShadow(User $viewer, ?int $branchId = null): bool
    {
        return $this->modeFor($viewer, $branchId) === 'shadow';
    }

    public function isGraph(User $viewer, ?int $branchId = null): bool
    {
        return $this->modeFor($viewer, $branchId) === 'graph';
    }

    /** @return array<string, mixed> */
    public function activationDiagnostics(User $viewer, ?int $branchId = null): array
    {
        $configured = $this->configuredMode();
        $userAllowlist = $this->parseAllowlist(config('organization.graph_user_ids', ''));
        $branchAllowlist = $this->parseAllowlist(config('organization.graph_branch_ids', ''));
        $allowlistValid = $userAllowlist['valid'] && $branchAllowlist['valid'];
        $userMatched = $allowlistValid && in_array((int) $viewer->id, $userAllowlist['ids'], true);
        $branchMatched = $allowlistValid && $branchId !== null && in_array($branchId, $branchAllowlist['ids'], true);
        $matchedBy = $userMatched ? 'user_allowlist' : ($branchMatched ? 'branch_allowlist' : null);
        $reason = match (true) {
            ! $allowlistValid => 'invalid_allowlist',
            ! $this->safeEnvironment() && $configured !== 'legacy' => 'unsafe_environment',
            $configured === 'legacy' => 'configured_legacy',
            $configured === 'shadow' => 'shadow_global',
            $this->environment() === 'testing' => 'testing_environment',
            $matchedBy !== null => null,
            $userAllowlist['ids'] === [] && $branchAllowlist['ids'] === [] => 'empty_allowlist',
            default => 'allowlist_no_match',
        };

        return [
            'configured_mode' => $configured,
            'effective_mode' => $this->modeForWithoutDiagnostics($viewer, $branchId),
            'environment' => app()->environment(),
            'safe_environment' => $this->safeEnvironment(),
            'allowlist_valid' => $allowlistValid,
            'user_allowlist_count' => count($userAllowlist['ids']),
            'branch_allowlist_count' => count($branchAllowlist['ids']),
            'matched_by' => $matchedBy,
            'reason' => $reason,
        ];
    }

    private function modeForWithoutDiagnostics(User $viewer, ?int $branchId): string
    {
        $configured = $this->configuredMode();
        if ($configured === 'legacy' || ! $this->safeEnvironment()) {
            return 'legacy';
        }
        if ($configured === 'shadow' || $this->environment() === 'testing') {
            return $configured === 'shadow' ? 'shadow' : 'graph';
        }

        $userAllowlist = $this->parseAllowlist(config('organization.graph_user_ids', ''));
        $branchAllowlist = $this->parseAllowlist(config('organization.graph_branch_ids', ''));
        if (! $userAllowlist['valid'] || ! $branchAllowlist['valid']) {
            return 'legacy';
        }

        return in_array((int) $viewer->id, $userAllowlist['ids'], true)
            || ($branchId !== null && in_array($branchId, $branchAllowlist['ids'], true))
            ? 'graph'
            : 'legacy';
    }

    private function safeEnvironment(): bool
    {
        return in_array($this->environment(), ['local', 'testing'], true);
    }

    private function environment(): string
    {
        return (string) config('app.env', app()->environment());
    }

    /** @return array{valid: bool, ids: array<int>} */
    private function parseAllowlist(mixed $value): array
    {
        if (is_array($value)) {
            $tokens = $value;
        } else {
            $tokens = preg_split('/\s*,\s*/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY);
        }

        if ($tokens === false || $tokens === []) {
            return ['valid' => true, 'ids' => []];
        }

        $ids = [];
        foreach ($tokens as $token) {
            if (! is_scalar($token) || filter_var($token, FILTER_VALIDATE_INT) === false || (int) $token < 1) {
                return ['valid' => false, 'ids' => []];
            }
            $ids[] = (int) $token;
        }

        return ['valid' => true, 'ids' => array_values(array_unique($ids))];
    }
}
