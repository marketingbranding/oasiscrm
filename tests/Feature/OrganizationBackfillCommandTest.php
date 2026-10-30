<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_write_requires_explicit_selection_and_confirmation(): void
    {
        config(['app.env' => 'testing', 'organization.backfill_isolated' => true]);

        $this->artisan('organization:backfill', ['--write' => true])
            ->assertExitCode(1);
        $this->artisan('organization:backfill', ['--write' => true, '--confirm-isolated' => true])
            ->assertExitCode(1);
    }

    public function test_write_and_dry_run_cannot_be_combined(): void
    {
        $this->artisan('organization:backfill', [
            '--write' => true,
            '--dry-run' => true,
            '--confirm-isolated' => true,
            '--user' => [1],
        ])->assertExitCode(1);
    }

    public function test_selected_controlled_write_accepts_all_explicit_guards(): void
    {
        $branch = Branch::query()->create(['name' => 'CMD2', 'code' => 'CMD2', 'is_active' => true]);
        $parent = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'supervisor')->firstOrFail()->id,
            'branch_id' => $branch->id,
        ]);
        $child = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'staff')->firstOrFail()->id,
            'branch_id' => $branch->id,
        ]);
        $child->forceFill(['supervisor_user_id' => $parent->id])->saveQuietly();
        config(['app.env' => 'testing', 'organization.backfill_isolated' => true]);

        $this->artisan('organization:backfill', [
            '--write' => true,
            '--confirm-isolated' => true,
            '--user' => [$child->id],
        ])->assertExitCode(0);

        $this->assertDatabaseHas('organization_assignments', [
            'user_id' => $child->id,
            'parent_user_id' => $parent->id,
            'relationship_type' => 'reports_to',
            'is_active' => true,
        ]);
    }

    public function test_branch_selection_rejects_users_from_another_primary_branch(): void
    {
        $first = Branch::query()->create(['name' => 'B1', 'code' => 'B1', 'is_active' => true]);
        $second = Branch::query()->create(['name' => 'B2', 'code' => 'B2', 'is_active' => true]);
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'staff')->firstOrFail()->id,
            'branch_id' => $second->id,
        ]);

        $this->artisan('organization:backfill', [
            '--dry-run' => true,
            '--branch' => $first->id,
            '--user' => [$user->id],
        ])->assertExitCode(1);
    }
}
