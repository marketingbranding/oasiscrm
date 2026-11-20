<?php

namespace Tests\Unit;

use App\Services\AppSettingResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class AppSettingResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_br06_reminder_minutes_uses_catalog_default_and_typed_override(): void
    {
        $resolver = app(AppSettingResolver::class);

        $this->assertSame(15, $resolver->int('sales_agenda.reminder_lead_minutes'));

        DB::table('app_settings')->insert([
            'key' => 'sales_agenda.reminder_lead_minutes',
            'value' => json_encode(20, JSON_THROW_ON_ERROR),
            'group' => 'sales_agenda',
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        $resolver->forget('sales_agenda.reminder_lead_minutes');

        $this->assertSame(20, $resolver->int('sales_agenda.reminder_lead_minutes'));
    }

    public function test_stale_lead_days_uses_catalog_default_and_typed_override(): void
    {
        $resolver = app(AppSettingResolver::class);

        $this->assertSame(2, $resolver->int('sales_monitoring.stale_lead_days'));

        DB::table('app_settings')->insert([
            'key' => 'sales_monitoring.stale_lead_days',
            'value' => json_encode(5, JSON_THROW_ON_ERROR),
            'group' => 'sales_monitoring',
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        $resolver->forget('sales_monitoring.stale_lead_days');

        $this->assertSame(5, $resolver->int('sales_monitoring.stale_lead_days'));
    }

    public function test_unknown_setting_key_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(AppSettingResolver::class)->value('unknown.setting');
    }

    public function test_feature_flags_default_to_false(): void
    {
        $resolver = app(AppSettingResolver::class);

        $this->assertFalse($resolver->bool('org_hierarchical_monitoring'));
        $this->assertFalse($resolver->bool('lead_stages_v3'));
        $this->assertFalse($resolver->bool('work_hours_v3'));
        $this->assertFalse($resolver->bool('transaction_events_v3'));
    }
}
