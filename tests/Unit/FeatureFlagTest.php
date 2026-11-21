<?php

namespace Tests\Unit;

use App\Services\AppSettingResolver;
use App\Support\FeatureFlag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_prd_v3_flags_default_to_false(): void
    {
        $flags = FeatureFlag::all();

        $this->assertSame([
            'org_hierarchical_monitoring' => false,
            'lead_stages_v3' => false,
            'transaction_events_v3' => false,
        ], $flags);
    }

    public function test_scoped_flag_defaults_off_and_supports_branch_rollout_modes(): void
    {
        $this->assertFalse(FeatureFlag::enabledForBranch('lead_stages_v3', 1));

        DB::table('app_settings')->insert([
            'key' => 'lead_stages_v3',
            'value' => json_encode(['mode' => 'branches', 'branch_ids' => [1, 3]], JSON_THROW_ON_ERROR),
            'group' => 'feature_flags',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(AppSettingResolver::class)->forget('lead_stages_v3');

        $this->assertTrue(FeatureFlag::enabledForBranch('lead_stages_v3', 1));
        $this->assertFalse(FeatureFlag::enabledForBranch('lead_stages_v3', 2));
        $this->assertFalse(FeatureFlag::enabledForBranch('lead_stages_v3', null));
    }

    public function test_scoped_flag_boolean_compatibility_maps_true_to_all_and_false_to_off(): void
    {
        DB::table('app_settings')->insert([
            'key' => 'transaction_events_v3',
            'value' => json_encode(true, JSON_THROW_ON_ERROR),
            'group' => 'feature_flags',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(AppSettingResolver::class)->forget('transaction_events_v3');

        $this->assertTrue(FeatureFlag::enabledForBranch('transaction_events_v3', 99));
    }

    public function test_feature_flag_reads_explicit_boolean_override_from_shared_resolver(): void
    {
        DB::table('app_settings')->insert([
            'key' => 'org_hierarchical_monitoring',
            'value' => json_encode(true, JSON_THROW_ON_ERROR),
            'group' => 'feature_flags',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(AppSettingResolver::class)->forget('org_hierarchical_monitoring');

        $this->assertTrue(FeatureFlag::enabled('org_hierarchical_monitoring'));
    }
}
