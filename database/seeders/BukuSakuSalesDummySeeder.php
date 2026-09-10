<?php

namespace Database\Seeders;

use App\Models\ContentItem;
use App\Models\SalesLead;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local smoke/UAT dummy data for Buku Saku Sales (30 leads + 12 agendas = 42 rows).
 *
 * LOCAL ONLY - never run in production.
 * Leads use sync_status "local" so the coordinator push service never picks
 * them up for real spreadsheet writes. Re-running is safe (idempotent guard).
 */
class BukuSakuSalesDummySeeder extends Seeder
{
    public function run(): void
    {
        if (SalesLead::query()->where('customer_name', 'like', 'UAT Konsumen%')->exists()) {
            $this->command->info('BukuSakuSalesDummySeeder: UAT data already present, skipping.');

            return;
        }

        $sales = User::query()->where('email', 'sales@oasis.local')->firstOrFail();
        $branchId = (int) $sales->branch_id;
        $projects = [37, 38, 39, 40, 41, 42, 43];

        $statuses = array_merge(
            array_fill(0, 8, 'no_response'),
            array_fill(0, 10, 'discussion'),
            array_fill(0, 5, 'face_to_face'),
            array_fill(0, 7, 'site_visit'),
        );
        $sources = ['Referensi', 'Pameran', 'TikTok', 'Instagram', 'Spanduk'];
        $platforms = ['WhatsApp', 'Telepon', 'Kunjungan Langsung'];
        $activities = ['Chat WhatsApp', 'Telepon', 'Kunjungan', 'Live TikTok', 'Pameran Perumahan'];
        $notes = [
            'Minta info cicilan 15 tahun.',
            'Mau survei akhir pekan.',
            'Bandingkan dengan proyek sebelah.',
            'Tanya promo akhir bulan.',
            'Follow-up minggu depan.',
            null,
        ];

        foreach (range(1, 30) as $i) {
            $leadDate = today()->subDays($i % 30);
            SalesLead::query()->create([
                'branch_id' => $branchId,
                'project_id' => $projects[($i - 1) % count($projects)],
                'sales_user_id' => $sales->id,
                'lead_date' => $leadDate->toDateString(),
                'customer_name' => sprintf('UAT Konsumen %02d', $i),
                'phone' => sprintf('0812%07d', 1000000 + $i),
                'source' => $sources[($i - 1) % count($sources)],
                'platform' => $platforms[($i - 1) % count($platforms)],
                'campaign_name' => $activities[($i - 1) % count($activities)],
                'notes' => $notes[($i - 1) % count($notes)],
                'current_status' => $statuses[$i - 1],
                'current_status_changed_at' => $leadDate,
                'current_status_source' => 'manual',
                'current_status_source_id' => (string) $sales->id,
                'sync_status' => 'local',
                'created_by' => $sales->id,
            ]);
        }

        $agendaStatuses = array_merge(
            array_fill(0, 4, 'planned'),
            array_fill(0, 2, 'confirmed'),
            array_fill(0, 4, 'done'),
            ['cancelled'],
            ['rescheduled'],
        );
        $categories = ContentItem::SALES_ACTIVITY_CATEGORIES;
        $titles = [
            'Follow-up cicilan UAT Konsumen 01',
            'Cek lokasi kavling blok A',
            'Telepon penawaran promo',
            'Tatap muka presentasi brosur',
            'Live TikTok open house',
            'Canvassing perumahan sekitar',
            'Administrasi berkas awal',
            'Rapat koordinasi tim',
            'Pameran akhir pekan',
            'Follow-up survei ulang',
            'Pembuatan konten testimoni',
            'Kunjungan ulang konsumen ragu',
        ];

        foreach (range(1, 12) as $i) {
            $status = $agendaStatuses[$i - 1];
            ContentItem::query()->create([
                'branch_id' => $branchId,
                'sales_project_id' => $projects[($i - 1) % count($projects)],
                'item_type' => 'agenda',
                'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
                'visibility' => 'personal',
                'title' => $titles[$i - 1],
                'sales_activity_category' => $categories[($i - 1) % count($categories)],
                'scheduled_date' => today()->subDays(13 - $i)->toDateString(),
                'status' => $status,
                'activity_result' => $status === 'done' ? 'Selesai sesuai rencana UAT.' : null,
                'owner_user_id' => $sales->id,
                'created_by' => $sales->id,
            ]);
        }

        $this->command->info('BukuSakuSalesDummySeeder: 30 leads + 12 agendas created.');
    }
}
