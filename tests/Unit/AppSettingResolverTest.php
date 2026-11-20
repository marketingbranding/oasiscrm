<?php

namespace Tests\Unit;

use App\Services\AppSettingResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public function test_br_wp11_second_read_uses_cache_without_querying_database(): void
    {
        $resolver = app(AppSettingResolver::class);
        $resolver->forget('sales_agenda.reminder_lead_minutes');

        DB::table('app_settings')->insert([
            'key' => 'sales_agenda.reminder_lead_minutes',
            'value' => json_encode(20, JSON_THROW_ON_ERROR),
            'group' => 'sales_agenda',
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        $this->assertSame(20, $resolver->int('sales_agenda.reminder_lead_minutes'));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertSame(20, $resolver->int('sales_agenda.reminder_lead_minutes'));
        DB::disableQueryLog();

        $this->assertCount(0, DB::getQueryLog());
    }

    public function test_db_failure_does_not_cache_default_and_recovery_reads_stored_value(): void
    {
        $resolver = app(AppSettingResolver::class);
        $resolver->forget('sales_agenda.reminder_lead_minutes');

        Schema::rename('app_settings', 'app_settings_unavailable');
        $this->assertSame(15, $resolver->int('sales_agenda.reminder_lead_minutes'));

        Schema::rename('app_settings_unavailable', 'app_settings');
        DB::table('app_settings')->insert([
            'key' => 'sales_agenda.reminder_lead_minutes',
            'value' => json_encode(20, JSON_THROW_ON_ERROR),
            'group' => 'sales_agenda',
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        $this->assertSame(20, $resolver->int('sales_agenda.reminder_lead_minutes'));
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
