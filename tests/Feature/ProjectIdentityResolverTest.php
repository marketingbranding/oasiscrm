<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LeadMaster;
use App\Services\ProjectIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProjectIdentityResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_exact_normalized_canonical_and_sheet_alias_with_branch_isolation(): void
    {
        $branch = Branch::create(['name' => 'Resolver Branch', 'code' => 'RES', 'is_active' => true]);
        $otherBranch = Branch::create(['name' => 'Other Branch', 'code' => 'OTH', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Canonical Project', 'sheet_project_name' => 'Sheet Alias', 'is_active' => true]);
        LeadMaster::create(['branch_id' => $otherBranch->id, 'project_name' => 'Canonical Project', 'sheet_project_name' => 'Sheet Alias', 'is_active' => true]);
        $resolver = app(ProjectIdentityResolver::class);

        $this->assertTrue($resolver->resolveExact($branch, ' canonical   PROJECT ')->is($project));
        $this->assertTrue($resolver->resolveExact($branch->id, ' sheet alias ')->is($project));
        $this->assertSame(['Canonical Project', 'Sheet Alias'], $resolver->labels($project));
    }

    public function test_duplicate_identity_is_ambiguous_with_existing_issue_api(): void
    {
        $branch = Branch::create(['name' => 'Duplicate Branch', 'code' => 'DUP', 'is_active' => true]);
        LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'First', 'sheet_project_name' => 'Shared Alias', 'is_active' => true]);
        LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Shared Alias', 'is_active' => true]);
        $resolver = app(ProjectIdentityResolver::class);

        $this->assertSame([null, 'project_ambiguous'], $resolver->resolveExactWithIssue($branch, 'shared alias'));
        $this->expectException(ValidationException::class);
        $resolver->resolveExact($branch, 'shared alias');
    }

    public function test_duplicate_canonical_without_alias_is_ambiguous_and_unique_alias_resolves(): void
    {
        $branch = Branch::create(['name' => 'Dup Canonical', 'code' => 'DPC', 'is_active' => true]);
        $otherBranch = Branch::create(['name' => 'Dup Other', 'code' => 'DPO', 'is_active' => true]);
        LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Dup Project', 'is_active' => true]);
        $second = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Dup Project', 'sheet_project_name' => 'Dup Alias', 'is_active' => true]);
        LeadMaster::create(['branch_id' => $otherBranch->id, 'project_name' => 'Dup Project', 'is_active' => true]);
        $resolver = app(ProjectIdentityResolver::class);

        $this->assertSame([null, 'project_ambiguous'], $resolver->resolveExactWithIssue($branch, 'dup project'));
        $this->assertNull($resolver->resolveExactOrNull($branch, 'Dup Project'));
        $this->assertTrue($resolver->resolveExact($branch, 'dup alias')->is($second));
        $this->assertSame([null, 'project_ambiguous'], $resolver->resolveExactAcrossBranchesWithIssue('dup project'));
        $this->assertSame([null, 'project_not_found'], $resolver->resolveExactWithIssue($branch, 'missing project'));
    }

    public function test_inactive_projects_are_excluded_by_default_and_available_explicitly(): void
    {
        $branch = Branch::create(['name' => 'Inactive Branch', 'code' => 'INA', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Inactive Project', 'is_active' => false]);
        $resolver = app(ProjectIdentityResolver::class);

        $this->assertNull($resolver->resolveExact($branch, 'Inactive Project'));
        $this->assertTrue($resolver->resolveExact($branch, 'Inactive Project', false)->is($project));
    }
}
