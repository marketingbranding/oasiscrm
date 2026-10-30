<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrganizationGraphCutoverDocumentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_phase_2_7_report_contains_all_required_evidence_sections_and_safety_boundary(): void
    {
        $path = base_path('docs/ORGANIZATION_GRAPH_CUTOVER_PREPARATION_PHASE_2_7.md');
        $this->assertFileExists($path);
        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        foreach (range('A', 'T') as $section) {
            $this->assertStringContainsString("## {$section}.", $contents, $section);
        }

        foreach ([
            'graph_mode=legacy',
            'production_cutover_performed=false',
            'legacy',
            'shadow',
            'graph',
            'canonical',
            'reports_to',
            'sales_team_member_of',
            '11',
            '21',
            '22',
            '23',
            'READY',
            'BLOCKED',
            'NOT READY',
            'No global backfill',
            'Phase 3 did not begin',
        ] as $required) {
            $this->assertStringContainsString($required, $contents, $required);
        }
    }

    public function test_phase_2_7_changelog_migration_is_idempotent(): void
    {
        $title = 'Persiapan Cutover Terkendali Graph Organisasi';
        $migrationPath = database_path('migrations/2026_10_30_000005_add_phase_2_7_graph_cutover_preparation_changelog.php');
        $this->assertFileExists($migrationPath);

        $migration = require $migrationPath;
        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());
    }
}
