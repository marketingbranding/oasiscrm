<?php

namespace Tests\Unit;

use App\Enums\SalesLeadStatus;
use App\Models\SalesLead;
use App\Support\AppSettingCatalog;
use Tests\TestCase;

class SalesLeadStageTest extends TestCase
{
    public function test_br10_v3_stage_order_excludes_berkas_and_akad(): void
    {
        $this->assertSame(
            ['lead_baru', 'dihubungi', 'tatap_muka', 'survey', 'utj', 'gugur'],
            SalesLead::STAGE_V3_ORDER,
        );
        $this->assertSame('Lead Baru', SalesLead::STAGE_V3_LABELS['lead_baru']);
        $this->assertArrayNotHasKey('documents_completed_at', SalesLead::STAGE_V3_ORDER);
        $this->assertArrayNotHasKey('akad_at', SalesLead::STAGE_V3_ORDER);
    }

    public function test_br10_legacy_status_maps_to_canonical_stage(): void
    {
        $this->assertSame('lead_baru', SalesLead::stageV3FromLegacy(SalesLeadStatus::NoResponse));
        $this->assertSame('dihubungi', SalesLead::stageV3FromLegacy(SalesLeadStatus::Discussion));
        $this->assertSame('tatap_muka', SalesLead::stageV3FromLegacy(SalesLeadStatus::FaceToFace));
        $this->assertSame('survey', SalesLead::stageV3FromLegacy(SalesLeadStatus::SiteVisit));
        $this->assertSame('utj', SalesLead::stageV3FromLegacy(SalesLeadStatus::Akad));
    }

    public function test_br11_historical_process_fields_do_not_resolve_as_lead_stage(): void
    {
        $lead = new SalesLead([
            'documents_completed_at' => now(),
            'akad_at' => now(),
        ]);

        $this->assertSame('lead_baru', $lead->resolveStageV3());
    }

    public function test_br13_settings_register_reason_list_and_edit_limit(): void
    {
        $definitions = AppSettingCatalog::definitions();

        $this->assertSame('list', $definitions['sales_lead.gugur_reasons']['type']);
        $this->assertSame([
            'Tidak berminat',
            'Tidak bisa dihubungi',
            'Tidak lolos syarat subsidi',
            'Pindah ke developer lain',
            'Lainnya',
        ], $definitions['sales_lead.gugur_reasons']['default']);
        $this->assertSame('utj', $definitions['sales_lead.sales_max_edit_stage']['default']);
    }
}
