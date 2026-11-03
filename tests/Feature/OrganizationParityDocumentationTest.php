<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationParityDocumentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_phase_2_evidence_document_names_all_required_sources_scopes_and_safety_rules(): void
    {
        $path = base_path('docs/ORGANIZATION_SCOPE_PARITY_PHASE_2.md');
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        foreach ([
            'organization_assignments',
            'supervisor_user_id',
            'sales_coordinator_sales',
            'branch_user',
            'project_user',
            'OrganizationScopeService',
            'OrganizationGraphService',
            'ReportingHierarchyService',
            'WorkspaceAccessService',
            'CrmAuthorizationService',
            'SalesTeamScopeService',
            'own',
            'team',
            'assigned',
            'project',
            'branch',
            'descendants',
            'organization-wide',
            'system-wide',
            'historical',
            'EXPECTED_COMPATIBILITY_DIFFERENCE',
            'MISSING_GRAPH_ASSIGNMENT',
            'STALE_LEGACY_RELATION',
            'PRIMARY_BRANCH_FALLBACK',
            'HISTORICAL_ACCESS',
            'ROLE_SPECIFIC_RULE',
            'SALES_COORDINATOR_MAPPING',
            'PROJECT_ASSIGNMENT_DIFFERENCE',
            'GRAPH_TRAVERSAL_DIFFERENCE',
            'DATA_INTEGRITY_PROBLEM',
            'IMPLEMENTATION_BUG',
            'UNKNOWN',
            'organization:backfill --dry-run',
            'Do not perform production cutover',
        ] as $required) {
            $this->assertStringContainsString($required, $contents, $required);
        }

        foreach ([
            'sales',
            'sales_coordinator',
            'supervisor',
            'manager',
            'branch_manager',
            'sas',
            'sam',
            'pusat',
            'admin',
            'staff',
            'superadmin',
        ] as $role) {
            $this->assertStringContainsString($role, $contents, $role);
        }
    }

    public function test_phase_2_evidence_document_contains_required_completion_sections_and_readiness_gates(): void
    {
        $contents = file_get_contents(base_path('docs/ORGANIZATION_SCOPE_PARITY_PHASE_2.md'));
        $this->assertIsString($contents);

        foreach (range('A', 'Q') as $section) {
            $this->assertStringContainsString("## {$section}.", $contents, $section);
        }

        foreach ([
            'organization:parity',
            'users_scanned',
            'assignments_proposed',
            'created',
            'already_migrated',
            'production_cutover_performed',
            'zero unknown mismatches',
            'zero critical cross-branch and cross-project leakage differences',
            'legacy-read rollback',
            'Phase 3',
        ] as $required) {
            $this->assertStringContainsString($required, $contents, $required);
        }
    }

    public function test_phase_2_changelog_migration_is_idempotent(): void
    {
        $title = 'Diagnostik Paritas Organisasi dan Scope';
        $migrationPath = database_path('migrations/2026_10_30_000002_add_organization_scope_parity_changelog.php');
        $this->assertFileExists($migrationPath);

        $migration = require $migrationPath;
        $migration->up();
        $migration->up();

        $this->assertSame(1, \DB::table('changelogs')->whereNull('version')->where('title', $title)->count());
    }

    public function test_phase_2_5_remediation_report_contains_complete_evidence_and_blocked_gate(): void
    {
        $path = base_path('docs/ORGANIZATION_PARITY_REMEDIATION_PHASE_2_5.md');
        $this->assertFileExists($path);
        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        foreach (range('A', 'S') as $section) {
            $this->assertStringContainsString("## {$section}.", $contents, $section);
        }

        foreach ([
            'SECURITY_CRITICAL',
            'ACCESS_LOSS',
            'STRUCTURAL',
            'HISTORICAL',
            'EXPECTED',
            'UNKNOWN',
            'EXPANSION',
            'LOSS',
            'MISSING_GRAPH_ASSIGNMENT',
            'SALES_COORDINATOR_MAPPING',
            'LEGACY_STALE',
            'LEGACY_ORPHANED',
            'LEGACY_CROSS_TREE',
            'LEGACY_DUPLICATE',
            'LEGACY_INVALID_PARENT',
            'LEGACY_HISTORICAL_ONLY',
            '11',
            '21',
            '22',
            '23',
            'NOT READY',
            'BLOCKED',
            'graph_mode=legacy',
            'Do not execute the cutover',
        ] as $required) {
            $this->assertStringContainsString($required, $contents, $required);
        }
    }

    public function test_phase_2_5_changelog_migration_is_idempotent(): void
    {
        $title = 'Remediasi Paritas Organisasi dengan Gate Cutover';
        $migrationPath = database_path('migrations/2026_10_30_000003_add_organization_parity_remediation_changelog.php');
        $this->assertFileExists($migrationPath);

        $migration = require $migrationPath;
        $migration->up();
        $migration->up();

        $this->assertSame(1, \DB::table('changelogs')->whereNull('version')->where('title', $title)->count());
    }

    public function test_phase_2_6_relationship_semantics_report_contains_required_decisions_and_safety_boundary(): void
    {
        $path = base_path('docs/ORGANIZATION_RELATIONSHIP_SEMANTICS_PHASE_2_6.md');
        $this->assertFileExists($path);
        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        foreach (range('A', 'R') as $section) {
            $this->assertStringContainsString("## {$section}.", $contents, $section);
        }

        foreach ([
            'reports_to',
            'sales_team_member_of',
            'supervisor_user_id',
            'sales_coordinator_sales',
            'graph_mode=legacy',
            'production_cutover_performed=false',
            'Decision **A: both relationships are valid simultaneously**',
            '11',
            '21',
            '22',
            '23',
            'Do not cut over',
            'Phase 2.7',
        ] as $required) {
            $this->assertStringContainsString($required, $contents, $required);
        }
    }

    public function test_phase_2_6_changelog_migration_is_idempotent(): void
    {
        $title = 'Semantika Relasi Organisasi Reporting dan Sales Team';
        $migrationPath = database_path('migrations/2026_10_30_000004_add_phase_2_6_organization_relationship_semantics_changelog.php');
        $this->assertFileExists($migrationPath);

        $migration = require $migrationPath;
        $migration->up();
        $migration->up();

        $this->assertSame(1, \DB::table('changelogs')->whereNull('version')->where('title', $title)->count());
    }
}
