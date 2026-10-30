<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationGraphModeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationGraphModeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_and_invalid_modes_fail_closed_to_legacy(): void
    {
        $viewer = $this->user();
        $resolver = app(OrganizationGraphModeService::class);

        config([
            'app.env' => 'local',
            'organization.graph_mode' => null,
            'organization.graph_user_ids' => '',
            'organization.graph_branch_ids' => '',
        ]);
        $this->assertSame('legacy', $resolver->configuredMode());
        $this->assertSame('legacy', $resolver->modeFor($viewer));

        config(['organization.graph_mode' => 'invalid']);
        $this->assertSame('legacy', $resolver->configuredMode());
        $this->assertSame('legacy', $resolver->modeFor($viewer));
    }

    public function test_shadow_is_global_in_safe_environments_and_legacy_remains_explicit(): void
    {
        $viewer = $this->user();
        $resolver = app(OrganizationGraphModeService::class);
        config(['app.env' => 'local', 'organization.graph_mode' => 'shadow']);

        $this->assertSame('shadow', $resolver->modeFor($viewer));
        $this->assertTrue($resolver->isShadow($viewer));

        config(['organization.graph_mode' => 'legacy']);
        $this->assertTrue($resolver->isLegacy($viewer));
    }

    public function test_graph_requires_explicit_allowlist_outside_testing(): void
    {
        $branch = $this->branch('MOD');
        $viewer = $this->user($branch);
        $resolver = app(OrganizationGraphModeService::class);
        config([
            'app.env' => 'local',
            'organization.graph_mode' => 'graph',
            'organization.graph_user_ids' => '',
            'organization.graph_branch_ids' => '',
        ]);

        $this->assertSame('legacy', $resolver->modeFor($viewer, $branch->id));
        $this->assertSame('empty_allowlist', $resolver->activationDiagnostics($viewer, $branch->id)['reason']);

        config(['organization.graph_user_ids' => (string) $viewer->id]);
        $this->assertSame('graph', $resolver->modeFor($viewer, $branch->id));
        $this->assertSame('user_allowlist', $resolver->activationDiagnostics($viewer, $branch->id)['matched_by']);
    }

    public function test_branch_allowlist_matches_only_the_current_branch_context(): void
    {
        $allowed = $this->branch('ALW');
        $other = $this->branch('OTH');
        $viewer = $this->user($allowed);
        $resolver = app(OrganizationGraphModeService::class);
        config([
            'app.env' => 'local',
            'organization.graph_mode' => 'graph',
            'organization.graph_user_ids' => '',
            'organization.graph_branch_ids' => (string) $allowed->id,
        ]);

        $this->assertSame('graph', $resolver->modeFor($viewer, $allowed->id));
        $this->assertSame('legacy', $resolver->modeFor($viewer, $other->id));
    }

    public function test_testing_environment_allows_explicit_graph_fixture_mode_and_canonical_alias(): void
    {
        $viewer = $this->user();
        $resolver = app(OrganizationGraphModeService::class);
        config([
            'app.env' => 'testing',
            'organization.graph_mode' => 'canonical',
            'organization.graph_user_ids' => '',
            'organization.graph_branch_ids' => '',
        ]);

        $this->assertSame('graph', $resolver->configuredMode());
        $this->assertSame('graph', $resolver->modeFor($viewer));
    }

    public function test_role_changes_do_not_change_effective_mode(): void
    {
        $viewer = $this->user();
        $resolver = app(OrganizationGraphModeService::class);
        config([
            'app.env' => 'local',
            'organization.graph_mode' => 'graph',
            'organization.graph_user_ids' => (string) $viewer->id,
        ]);

        $before = $resolver->modeFor($viewer);
        $viewer->forceFill(['role_id' => Role::query()->where('slug', 'superadmin')->firstOrFail()->id])->saveQuietly();
        $this->assertSame($before, $resolver->modeFor($viewer->fresh()));
    }

    public function test_malformed_allowlist_fails_closed_without_partial_activation(): void
    {
        $branch = $this->branch('BAD');
        $viewer = $this->user($branch);
        $resolver = app(OrganizationGraphModeService::class);
        config([
            'app.env' => 'local',
            'organization.graph_mode' => 'graph',
            'organization.graph_user_ids' => (string) $viewer->id.',oops',
            'organization.graph_branch_ids' => (string) $branch->id,
        ]);

        $this->assertSame('legacy', $resolver->modeFor($viewer, $branch->id));
        $this->assertFalse($resolver->activationDiagnostics($viewer, $branch->id)['allowlist_valid']);
        $this->assertSame('invalid_allowlist', $resolver->activationDiagnostics($viewer, $branch->id)['reason']);
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->create(['name' => $code, 'code' => $code, 'is_active' => true]);
    }

    private function user(?Branch $branch = null): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'staff')->firstOrFail()->id,
            'branch_id' => $branch?->id,
        ]);
    }
}
