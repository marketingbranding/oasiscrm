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
            'work_hours_v3' => false,
            'transaction_events_v3' => false,
        ], $flags);
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
