<?php

namespace Tests\Feature;

use App\Enums\SalesLeadStatus;
use App\Models\Promo;
use App\Support\SalesLeadMasterData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesLeadMasterDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_local_master_data_and_seeded_promos_are_available(): void
    {
        $this->assertSame(['Online', 'Offline', 'Referral', 'Freelance', 'Lead Cabang'], SalesLeadMasterData::SOURCES);
        $this->assertSame(['TikTok', 'Facebook', 'Instagram', 'WhatsApp', 'Website', 'Marketplace', 'Canvassing', 'Pameran', 'Event', 'Telepon', 'Datang Langsung', 'Tidak Diketahui'], SalesLeadMasterData::CHANNELS);
        $this->assertSame(['Live', 'Konten Organik', 'Story', 'Reel', 'Iklan Berbayar', 'Marketplace', 'Broadcast WhatsApp', 'Follow Up Data Lama', 'Sebar Brosur', 'Pameran', 'Event', 'Referral', 'Freelance', 'Datang Langsung', 'Tidak Diketahui'], SalesLeadMasterData::ACTIVITIES);
        $this->assertSame(['No Promo', 'DP 1.5 Juta All in', 'DP 3 Juta All in'], Promo::query()->orderBy('id')->pluck('name')->all());
    }

    public function test_sales_lead_statuses_use_canonical_values_and_exact_labels(): void
    {
        $this->assertSame([
            'no_response' => 'No Respon',
            'discussion' => 'Diskusi',
            'face_to_face' => 'Tatap Muka',
            'site_visit' => 'Cek Lokasi',
            'freelance' => 'Jadi Freelance',
            'utj' => 'UTJ',
            'slik_check' => 'Cek Slik',
            'slik_rejected' => 'Tidak Lolos BI Checking',
            'akad' => 'Akad',
        ], collect(SalesLeadStatus::cases())->mapWithKeys(fn (SalesLeadStatus $status) => [$status->value => $status->label()])->all());
        $this->assertSame(SalesLeadStatus::FaceToFace, SalesLeadStatus::fromInput('Tatap Muka'));
        $this->assertTrue(SalesLeadStatus::FaceToFace->isManual());
        $this->assertNotSame(SalesLeadStatus::SiteVisit, SalesLeadStatus::FaceToFace);
    }

    public function test_legacy_survey_labels_are_not_statuses(): void
    {
        foreach (['Tatap Muka Konsumen', 'Survey', 'Survey Lokasi'] as $label) {
            $this->assertNull(SalesLeadStatus::tryFrom($label));
        }
    }
}
