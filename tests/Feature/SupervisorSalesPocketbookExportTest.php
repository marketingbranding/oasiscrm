<?php

namespace Tests\Feature;

use App\Exports\SalesAgendaExport;
use App\Exports\SupervisorSalesAgendaExport;
use App\Exports\SupervisorSalesLeadExport;
use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\SalesLead;
use App\Models\User;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class SupervisorSalesPocketbookExportTest extends TestCase
{
    public function test_agenda_export_has_exact_columns_and_one_row_with_joined_coordinators(): void
    {
        $sales = new User(['name' => 'Sales Satu']);
        $sales->id = 7;
        $branch = new Branch(['name' => 'Solo']);
        $project = new LeadMaster(['project_name' => 'Solo Project']);
        $agenda = new ContentItem([
            'owner_user_id' => 7,
            'scheduled_date' => Carbon::parse('2026-08-10'),
            'title' => 'Kunjungan',
            'sales_activity_category' => 'Survey Lokasi',
            'location' => 'Kantor',
            'activity_result' => 'Lanjut',
            'status' => 'done',
        ]);
        $agenda->setRelation('owner', $sales);
        $agenda->setRelation('branch', $branch);
        $agenda->setRelation('salesProject', $project);

        $sheet = $this->sheet(SupervisorSalesAgendaExport::toBrowser(
            collect([$agenda]),
            [7 => ['Koordinator Z', 'Koordinator A', 'Koordinator Z']],
            'agenda.xlsx',
        ));

        $this->assertSame([
            'Tanggal Agenda', 'Koordinator', 'Sales', 'Cabang', 'Proyek', 'Kategori Aktivitas', 'Agenda', 'Lokasi', 'Hasil', 'Status',
        ], array_slice($sheet->rangeToArray('A1:J1')[0], 0, 10));
        $this->assertSame('Koordinator A; Koordinator Z', $sheet->getCell('B2')->getValue());
        $this->assertSame('Survey Lokasi', $sheet->getCell('F2')->getValue());
        $this->assertSame('Kunjungan', $sheet->getCell('G2')->getValue());
        $this->assertSame(2, $sheet->getHighestDataRow());
    }

    public function test_sales_agenda_export_has_exact_columns_and_blank_null_category(): void
    {
        $agenda = new ContentItem([
            'scheduled_date' => Carbon::parse('2026-08-10'),
            'title' => 'Kunjungan',
            'status' => 'planned',
        ]);

        $sheet = $this->sheet(SalesAgendaExport::toBrowser(collect([$agenda]), 'agenda.xlsx'));

        $this->assertSame([
            'Tanggal Agenda', 'Kategori Aktivitas', 'Sales', 'Cabang', 'Proyek', 'Agenda', 'Lokasi', 'Hasil', 'Status',
        ], array_slice($sheet->rangeToArray('A1:I1')[0], 0, 9));
        $this->assertNull($sheet->getCell('B2')->getValue());
        $this->assertSame('Kunjungan', $sheet->getCell('F2')->getValue());
    }

    public function test_lead_export_has_exact_columns_one_row_per_record_and_canonical_sync_labels(): void
    {
        $sales = new User(['name' => 'Sales Satu']);
        $sales->id = 7;
        $branch = new Branch(['name' => 'Solo']);
        $project = new LeadMaster(['project_name' => 'Solo Project']);
        $labels = [
            'pending_create' => 'Belum Sync',
            'synced' => 'Tersinkron',
            'pending_update' => 'Perlu Sync Ulang',
            'sync_failed' => 'Sync Gagal',
        ];
        $leads = collect(array_keys($labels))->map(function (string $status, int $index) use ($sales, $branch, $project): SalesLead {
            $lead = new SalesLead([
                'sales_user_id' => 7,
                'lead_date' => Carbon::parse('2026-08-10'),
                'customer_name' => 'Lead '.($index + 1),
                'phone' => '=danger',
                'source' => 'Referral',
                'platform' => 'WhatsApp',
                'campaign_name' => 'Promo',
                'sync_status' => $status,
            ]);
            $lead->setRelation('sales', $sales);
            $lead->setRelation('branch', $branch);
            $lead->setRelation('project', $project);

            return $lead;
        });

        $sheet = $this->sheet(SupervisorSalesLeadExport::toBrowser(
            $leads,
            [7 => ['Koordinator Z', 'Koordinator A', 'Koordinator A']],
            'lead.xlsx',
        ));

        $this->assertSame([
            'Tanggal Lead', 'Koordinator', 'Sales PIC', 'Nama Konsumen', 'No HP', 'Cabang', 'Proyek',
            'Sumber Lead', 'Kanal Masuk', 'Aktivitas Lead', 'Status Lead', 'Status Sync',
        ], array_slice($sheet->rangeToArray('A1:L1')[0], 0, 12));
        $this->assertSame('Koordinator A; Koordinator Z', $sheet->getCell('B2')->getValue());
        $this->assertSame('=danger', $sheet->getCell('E2')->getValue());
        $this->assertSame('s', $sheet->getCell('E2')->getDataType());
        $this->assertSame(array_values($labels), array_map(
            fn (array $row) => $row[0],
            $sheet->rangeToArray('L2:L5'),
        ));
        $this->assertSame($leads->count() + 1, $sheet->getHighestDataRow());
    }

    private function sheet(BinaryFileResponse $response): Worksheet
    {
        $path = $response->getFile()->getPathname();
        $workbook = IOFactory::load($path);
        $sheet = clone $workbook->getActiveSheet();
        $workbook->disconnectWorksheets();
        @unlink($path);

        return $sheet;
    }
}
