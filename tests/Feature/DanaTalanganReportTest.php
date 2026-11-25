<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DanaTalangan;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class DanaTalanganReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_br43_report_separates_period_payment_and_integer_cent_totals(): void
    {
        [$branch, $user, $project] = $this->fixture();
        $inPeriod = DanaTalangan::create(['tanggal' => '2026-07-10', 'nama_konsumen' => 'A', 'project_name' => $project->project_name, 'project_id' => $project->id, 'branch_id' => $branch->id, 'nominal' => '100.01', 'status' => 'sanggup', 'created_by' => $user->id]);
        $outside = DanaTalangan::create(['tanggal' => '2026-06-10', 'nama_konsumen' => 'B', 'project_name' => $project->project_name, 'project_id' => $project->id, 'branch_id' => $branch->id, 'nominal' => '999.99', 'status' => 'lunas', 'created_by' => $user->id]);
        $inPeriod->payments()->createMany([
            ['paid_at' => '2026-07-05', 'amount' => '50.01', 'is_verified' => true, 'created_by' => $user->id],
            ['paid_at' => '2026-07-20', 'amount' => '10.01', 'is_verified' => false, 'created_by' => $user->id],
            ['paid_at' => '2026-08-01', 'amount' => '60.00', 'is_verified' => true, 'created_by' => $user->id],
        ]);
        $outside->payments()->create(['paid_at' => '2026-07-15', 'amount' => '999.99', 'is_verified' => true, 'created_by' => $user->id]);

        $response = $this->actingAs($user)->getJson(route('dana-talangan.report', ['branch_id' => $branch->id, 'project_id' => $project->id, 'from' => '2026-07-01', 'to' => '2026-07-31']))->assertOk();
        $response->assertJsonPath('count', 1)->assertJsonPath('nominal', '100.01')->assertJsonPath('verified', '50.01')->assertJsonPath('unverified', '10.01')->assertJsonPath('received_verified', '1050.00')->assertJsonPath('received_unverified', '10.01')->assertJsonPath('remaining', '50.00')->assertJsonPath('overpaid', '0.00')->assertJsonPath('by_status.sanggup', 1);
        $this->assertStringNotContainsString('nama_konsumen', $response->getContent());
    }

    public function test_report_filters_out_of_scope_branch_and_rejects_invalid_dates(): void
    {
        [$branch, $user] = $this->fixture();
        $otherBranch = Branch::create(['name' => 'Other', 'code' => 'OTH', 'is_active' => true]);
        $this->actingAs($user)->getJson(route('dana-talangan.report', ['branch_id' => $otherBranch->id, 'from' => '2026-07-01', 'to' => '2026-07-31']))->assertForbidden();
        $this->actingAs($user)->getJson(route('dana-talangan.report', ['from' => '2026-08-01', 'to' => '2026-07-01']))->assertUnprocessable();
    }

    public function test_report_page_and_export_have_safe_operational_contract(): void
    {
        [$branch, $user] = $this->fixture();
        $params = ['branch_id' => $branch->id, 'from' => '2026-07-01', 'to' => '2026-07-31'];
        $this->actingAs($user)->get(route('dana-talangan.report', $params))->assertOk()->assertSee('Laporan Dana Talangan')->assertSee('Terverifikasi diterima dalam periode');
        $response = $this->actingAs($user)->get(route('dana-talangan.report.export', $params))->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $workbook = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $values = implode(' ', array_map(fn (array $row): string => implode(' ', array_map(static fn (mixed $value): string => (string) $value, $row)), $workbook->getActiveSheet()->toArray()));
        $this->assertStringNotContainsString('NIK', $values);
        $this->assertStringNotContainsString('HP', $values);
        $this->assertStringNotContainsString('Alamat', $values);
    }

    private function fixture(): array
    {
        $branch = Branch::create(['name' => 'Cabang Test', 'code' => 'TST', 'is_active' => true]);
        $user = User::factory()->create(['role_id' => Role::query()->where('slug', 'admin')->value('id'), 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek Test', 'is_active' => true]);

        return [$branch, $user, $project];
    }
}
