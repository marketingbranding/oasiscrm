<?php

namespace Tests\Feature;

use App\Enums\SalesLeadStatus;
use App\Exceptions\SalesLeadSpreadsheetContractException;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\SalesLeadConsumerLink;
use App\Models\SalesLeadSlikAttempt;
use App\Models\User;
use App\Services\SalesLeadLifecycleService;
use App\Services\SalesLeadSpreadsheetWriter;
use App\ValueObjects\SalesLeadSpreadsheetWriteResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class SalesLeadLifecycleOperationsTest extends TestCase
{
    use RefreshDatabase;

    private int $remoteRowNumber = 2;

    public function test_primary_sales_cannot_use_lifecycle_operation_endpoints(): void
    {
        [, $project, , $lead] = $this->context();
        $sales = $this->user('sales', $lead->branch, 'Primary Sales');
        $sales->assignedProjects()->attach($project->id, ['is_primary' => true, 'is_active' => true]);
        $consumer = $this->consumerLink($lead);
        $attempt = SalesLeadSlikAttempt::create([
            'sales_lead_id' => $lead->id, 'branch_id' => $lead->branch_id, 'actor_id' => $lead->sales_user_id,
            'consumer_link_id' => $consumer->id, 'operation_uuid' => (string) Str::uuid(),
            'oasis_sync_id' => (string) Str::uuid(), 'sheet_name' => 'bi_checking', 'remote_row_number' => 4,
            'status' => 'submitted', 'nik' => $consumer->nik, 'id_kavling' => $consumer->id_kavling,
        ]);

        $this->actingAs($sales)->patchJson(route('sales-leads.lifecycle-status.update', $lead), ['status' => 'discussion'])->assertForbidden();
        $this->actingAs($sales)->postJson(route('sales-leads.site-visits.store', $lead), $this->visitData('2026-08-04'))->assertForbidden();
        $this->actingAs($sales)->postJson(route('sales-leads.consumer.store', $lead), ['project_id' => $project->id, 'nik' => '1123456789012345', 'id_kavling' => 'A-2'])->assertForbidden();
        $this->actingAs($sales)->postJson(route('sales-leads.slik.store', $lead), ['tanggal_slik' => '2026-08-05'])->assertForbidden();
        $this->actingAs($sales)->patchJson(route('sales-leads.slik.reject', [$lead, $attempt]), ['hasil_slik' => 'KOL 3', 'keterangan' => 'Ditolak'])->assertForbidden();
        $this->actingAs($sales)->postJson(route('sales-leads.freelance.store', $lead), ['nik_koordinator' => 'KOORD-01'])->assertForbidden();
    }

    public function test_manual_status_endpoint_accepts_only_three_manual_values_and_rejects_forged_organization_ids(): void
    {
        [, , $sales, $lead] = $this->context();

        $this->actingAs($sales)->patchJson(route('sales-leads.lifecycle-status.update', $lead), [
            'status' => 'discussion',
        ])->assertOk()->assertJsonPath('status', 'discussion');

        foreach (['utj', 'slik_check', 'slik_rejected', 'akad', 'freelance'] as $status) {
            $this->actingAs($sales)->from(route('sales-pocketbook.index'))->patch(route('sales-leads.lifecycle-status.update', $lead), [
                'status' => $status,
            ])->assertRedirect()->assertSessionHasErrors('status');
        }

        $this->actingAs($sales)->from(route('sales-pocketbook.index'))->patch(route('sales-leads.lifecycle-status.update', $lead), [
            'status' => 'site_visit',
            'branch_id' => $lead->branch_id,
        ])->assertRedirect()->assertSessionHasErrors('branch_id');
    }

    public function test_site_visit_supports_isi_nanti_multiple_rows_and_utj_outcome_does_not_set_utj(): void
    {
        [$branch, , $sales, $lead] = $this->context();
        $writer = Mockery::mock(SalesLeadSpreadsheetWriter::class);
        $writer->shouldReceive('append')->times(3)->withArgs(function (SalesLead $writtenLead, string $sheet, array $fields): bool {
            return $writtenLead->branch_id > 0 && $sheet === 'data_ceklok' && $fields['status_ceklok'] === 'utj';
        })->andReturnUsing(fn (SalesLead $writtenLead, string $sheet, array $fields, string $uuid) => $this->writeResult($writtenLead, $sheet, $uuid));
        $this->app->instance(SalesLeadSpreadsheetWriter::class, $writer);
        $service = app(SalesLeadLifecycleService::class);

        $incomplete = $service->recordSiteVisit($lead, ['completion' => 'isi_nanti'], $sales);
        $first = $service->recordSiteVisit($lead->fresh(), $this->visitData('2026-08-04'), $sales);
        $second = $service->recordSiteVisit($lead->fresh(), $this->visitData('2026-08-05'), $sales);
        $operationUuid = (string) Str::uuid();
        $idempotent = $service->recordSiteVisit($lead->fresh(), $this->visitData('2026-08-06') + ['operation_uuid' => $operationUuid], $sales);
        $retried = $service->recordSiteVisit($lead->fresh(), $this->visitData('2026-08-06') + ['operation_uuid' => $operationUuid], $sales);

        $this->assertFalse($incomplete->is_completed);
        $this->assertNull($incomplete->remote_row_number);
        $this->assertSame($branch->id, $first->branch_id);
        $this->assertSame(4, $lead->siteVisits()->count());
        $this->assertNotSame($first->operation_uuid, $second->operation_uuid);
        $this->assertTrue($idempotent->is($retried));
        $this->assertSame(SalesLeadStatus::SiteVisit, $lead->fresh()->current_status);
        $this->assertNull($lead->fresh()->utj_at);

        $this->actingAs($sales)->from(route('sales-pocketbook.index'))->post(route('sales-leads.site-visits.store', $lead), [
            'completion' => 'isi_nanti', 'branch_id' => $branch->id,
        ])->assertRedirect()->assertSessionHasErrors('branch_id');
    }

    public function test_consumer_enforces_nik_rules_preserves_leading_zero_and_blocks_branch_duplicate(): void
    {
        [, $project, $sales, $lead] = $this->context();
        $this->mockAppendWriter();

        $this->actingAs($sales)->from(route('sales-pocketbook.index'))->post(route('sales-leads.consumer.store', $lead), [
            'project_id' => $project->id,
            'nik' => '0000000000000000',
            'id_kavling' => 'A-1',
        ])->assertRedirect()->assertSessionHasErrors('nik');
        $this->actingAs($sales)->from(route('sales-pocketbook.index'))->post(route('sales-leads.consumer.store', $lead), [
            'project_id' => $project->id,
            'nik' => '1234',
            'id_kavling' => 'A-1',
        ])->assertRedirect()->assertSessionHasErrors('nik');
        $this->actingAs($sales)->from(route('sales-pocketbook.index'))->post(route('sales-leads.consumer.store', $lead), [
            'project_id' => $project->id,
            'nik' => '0123456789012345',
        ])->assertRedirect()->assertSessionHasErrors('id_kavling');

        $nik = '0123456789012345';
        $this->actingAs($sales)->postJson(route('sales-leads.consumer.store', $lead), [
            'project_id' => $project->id,
            'nik' => $nik,
            'id_kavling' => 'A-1',
        ])->assertOk()->assertJsonPath('sheet_type', 'data_konsumen');

        $this->assertSame($nik, SalesLeadConsumerLink::firstOrFail()->nik);
        $this->assertSame(SalesLeadStatus::Utj, $lead->fresh()->current_status);
        $this->assertNotNull($lead->fresh()->consumer_converted_at);

        $otherLead = $this->lead($sales, $project, 'Duplicate NIK');
        $this->actingAs($sales)->from(route('sales-pocketbook.index'))->post(route('sales-leads.consumer.store', $otherLead), [
            'project_id' => $project->id,
            'nik' => $nik,
            'id_kavling' => 'A-2',
        ])->assertRedirect()->assertSessionHasErrors('nik');
    }

    public function test_consumer_uses_nup_sheet_and_remote_failure_never_advances_status(): void
    {
        [, $project, $sales, $lead] = $this->context();
        $project->update(['is_nup_eligible' => true]);
        $writer = Mockery::mock(SalesLeadSpreadsheetWriter::class);
        $writer->shouldReceive('append')->once()->withArgs(fn (SalesLead $item, string $sheet) => $sheet === 'data_konsumen_nup')
            ->andReturnUsing(fn (SalesLead $item, string $sheet, array $fields, string $uuid) => $this->writeResult($item, $sheet, $uuid));
        $this->app->instance(SalesLeadSpreadsheetWriter::class, $writer);

        app(SalesLeadLifecycleService::class)->convertToConsumer($lead, [
            'project_id' => $project->id,
            'nik' => '0123456789012345',
        ], $sales);
        $this->assertSame('data_konsumen_nup', $lead->consumerLinks()->firstOrFail()->sheet_type);
        $this->assertSame(SalesLeadStatus::NoResponse, $lead->fresh()->current_status);
        $this->assertNull($lead->fresh()->consumer_converted_at);

        [, , $otherSales, $failedLead] = $this->context();
        $failedWriter = Mockery::mock(SalesLeadSpreadsheetWriter::class);
        $failedWriter->shouldReceive('append')->once()->andThrow(SalesLeadSpreadsheetContractException::writeFailed());
        $this->app->instance(SalesLeadSpreadsheetWriter::class, $failedWriter);
        try {
            app(SalesLeadLifecycleService::class)->convertToConsumer($failedLead, [
                'nik' => '1123456789012345', 'id_kavling' => 'B-1',
            ], $otherSales);
            $this->fail('Remote failure was not propagated.');
        } catch (SalesLeadSpreadsheetContractException) {
            $this->assertSame(SalesLeadStatus::NoResponse, $failedLead->fresh()->current_status);
            $this->assertSame(0, $failedLead->consumerLinks()->count());
        }
    }

    public function test_same_lead_can_progress_from_nup_to_normal_consumer_with_the_same_nik(): void
    {
        [, $project, $sales, $lead] = $this->context();
        $project->update(['is_nup_eligible' => true]);
        $writer = Mockery::mock(SalesLeadSpreadsheetWriter::class);
        $writer->shouldReceive('append')->twice()
            ->andReturnUsing(fn (SalesLead $item, string $sheet, array $fields, string $uuid) => $this->writeResult($item, $sheet, $uuid));
        $this->app->instance(SalesLeadSpreadsheetWriter::class, $writer);
        $nik = '0123456789012345';

        app(SalesLeadLifecycleService::class)->convertToConsumer($lead, [
            'project_id' => $project->id,
            'nik' => $nik,
        ], $sales);
        $project->update(['is_nup_eligible' => false]);
        app(SalesLeadLifecycleService::class)->convertToConsumer($lead->fresh(), [
            'project_id' => $project->id,
            'nik' => $nik,
            'id_kavling' => 'A-9',
        ], $sales);

        $this->assertSame(2, $lead->consumerLinks()->where('nik', $nik)->count());
        $this->assertSame(SalesLeadStatus::Utj, $lead->fresh()->current_status);
        $this->assertNotNull($lead->fresh()->consumer_converted_at);
    }

    public function test_slik_requires_normal_consumer_blocks_active_duplicate_and_rolls_back_remote_failure(): void
    {
        [, , $sales, $lead] = $this->context();
        $service = app(SalesLeadLifecycleService::class);
        try {
            $service->submitToSlik($lead, ['tanggal_slik' => '2026-08-05'], $sales);
            $this->fail('SLIK without a consumer was accepted.');
        } catch (ValidationException) {
            $this->assertSame(0, $lead->slikAttempts()->count());
        }

        $consumer = $this->consumerLink($lead);
        $this->mockAppendWriter();
        $attempt = app(SalesLeadLifecycleService::class)->submitToSlik($lead, ['tanggal_slik' => '2026-08-05'], $sales);
        $this->assertSame($consumer->id, $attempt->consumer_link_id);
        $this->assertSame(SalesLeadStatus::SlikCheck, $lead->fresh()->current_status);

        try {
            app(SalesLeadLifecycleService::class)->submitToSlik($lead->fresh(), ['tanggal_slik' => '2026-08-06'], $sales);
            $this->fail('A second active SLIK attempt was accepted.');
        } catch (ValidationException) {
            $this->assertSame(1, $lead->slikAttempts()->count());
        }

        [, , $failedSales, $failedLead] = $this->context();
        $this->consumerLink($failedLead);
        $failedLead->update(['current_status' => SalesLeadStatus::Utj]);
        $failedWriter = Mockery::mock(SalesLeadSpreadsheetWriter::class);
        $failedWriter->shouldReceive('append')->once()->andThrow(SalesLeadSpreadsheetContractException::writeFailed());
        $this->app->instance(SalesLeadSpreadsheetWriter::class, $failedWriter);
        try {
            app(SalesLeadLifecycleService::class)->submitToSlik($failedLead, ['tanggal_slik' => '2026-08-07'], $failedSales);
            $this->fail('A failed remote SLIK write advanced local state.');
        } catch (SalesLeadSpreadsheetContractException) {
            $this->assertSame(0, $failedLead->slikAttempts()->count());
            $this->assertSame(SalesLeadStatus::Utj, $failedLead->fresh()->current_status);
        }
    }

    public function test_slik_rejection_updates_stable_remote_row_before_local_status_and_cross_branch_is_denied(): void
    {
        [, , $sales, $lead] = $this->context();
        $consumer = $this->consumerLink($lead);
        $attempt = SalesLeadSlikAttempt::create([
            'sales_lead_id' => $lead->id, 'branch_id' => $lead->branch_id, 'actor_id' => $sales->id,
            'consumer_link_id' => $consumer->id, 'operation_uuid' => (string) Str::uuid(),
            'oasis_sync_id' => (string) Str::uuid(), 'sheet_name' => 'bi_checking', 'remote_row_number' => 4,
            'status' => 'submitted', 'nik' => $consumer->nik, 'id_kavling' => $consumer->id_kavling,
        ]);
        $writer = Mockery::mock(SalesLeadSpreadsheetWriter::class);
        $writer->shouldReceive('updateBySyncId')->once()->withArgs(function (SalesLead $item, string $sheet, string $syncId, array $fields) use ($attempt): bool {
            $this->assertSame('submitted', $attempt->fresh()->status);

            return $item->id === $attempt->sales_lead_id && $sheet === 'bi_checking'
                && $syncId === $attempt->oasis_sync_id && $fields['hasil_slik'] === 'KOL 3';
        })->andReturn(new SalesLeadSpreadsheetWriteResult('sheet-'.$lead->branch_id, 'bi_checking', 19, $attempt->oasis_sync_id));
        $this->app->instance(SalesLeadSpreadsheetWriter::class, $writer);

        app(SalesLeadLifecycleService::class)->markSlikRejected($lead, $attempt, [
            'hasil_slik' => 'KOL 3', 'keterangan' => 'Tidak memenuhi ketentuan.',
        ], $sales);
        $this->assertSame('rejected', $attempt->fresh()->status);
        $this->assertSame(19, $attempt->fresh()->remote_row_number);
        $this->assertSame(SalesLeadStatus::SlikRejected, $lead->fresh()->current_status);

        $admin = $this->user('admin', $lead->branch, 'Admin Cabang');
        $this->assertTrue($sales->can('markSlikRejected', $lead));
        $this->assertTrue($admin->can('markSlikRejected', $lead));

        [, , $otherSales] = $this->context();
        $this->assertFalse($otherSales->can('markSlikRejected', $lead));
        $this->actingAs($otherSales)->patchJson(route('sales-leads.slik.reject', [$lead, $attempt]), [
            'hasil_slik' => 'KOL 4', 'keterangan' => 'Lintas cabang',
        ])->assertForbidden();
    }

    public function test_freelance_uses_ojt_resolves_supervisor_requires_fallback_and_coexists_with_consumer(): void
    {
        [, $project, $sales, $lead] = $this->context();
        $coordinator = $this->user('sales_coordinator', $lead->branch, 'Koordinator');
        $sales->update(['supervisor_user_id' => $coordinator->id]);
        $this->consumerLink($lead);
        $writer = Mockery::mock(SalesLeadSpreadsheetWriter::class);
        $writer->shouldReceive('append')->once()->withArgs(function (SalesLead $item, string $sheet, array $fields): bool {
            return $sheet === 'data_sales' && $fields['nik_sales'] === 'OJT'
                && $fields['nik_koordinator'] === 'KOORD-01' && $fields['nama_koordinator'] === 'Koordinator';
        })->andReturnUsing(fn (SalesLead $item, string $sheet, array $fields, string $uuid) => $this->writeResult($item, $sheet, $uuid));
        $this->app->instance(SalesLeadSpreadsheetWriter::class, $writer);

        $link = app(SalesLeadLifecycleService::class)->convertToFreelance($lead, ['nik_koordinator' => 'KOORD-01'], $sales);
        $this->assertSame('OJT', $link->nik_sales);
        $this->assertSame($coordinator->id, $link->coordinator_user_id);
        $this->assertSame(SalesLeadStatus::NoResponse, $lead->fresh()->current_status);
        $this->assertTrue($lead->fresh()->is_freelance);

        $this->expectException(ValidationException::class);
        app(SalesLeadLifecycleService::class)->convertToFreelance($lead->fresh(), ['nik_koordinator' => 'KOORD-01'], $sales);
    }

    public function test_freelance_fallback_coordinator_and_branch_writes_remain_isolated(): void
    {
        [$firstBranch, , $firstSales, $firstLead] = $this->context();
        [$secondBranch, , $secondSales, $secondLead] = $this->context();
        $firstCoordinator = $this->user('sales_coordinator', $firstBranch, 'First Coordinator');
        $secondCoordinator = $this->user('sales_coordinator', $secondBranch, 'Second Coordinator');
        $this->actingAs($firstSales)->from(route('sales-pocketbook.index'))->post(route('sales-leads.freelance.store', $firstLead), [
            'nik_koordinator' => 'K-1',
        ])->assertRedirect()->assertSessionHasErrors('coordinator_user_id');
        $writer = Mockery::mock(SalesLeadSpreadsheetWriter::class);
        $writer->shouldReceive('append')->twice()->andReturnUsing(fn (SalesLead $item, string $sheet, array $fields, string $uuid) => $this->writeResult($item, $sheet, $uuid));
        $this->app->instance(SalesLeadSpreadsheetWriter::class, $writer);

        $service = app(SalesLeadLifecycleService::class);
        $first = $service->convertToFreelance($firstLead, ['nik_koordinator' => 'K-1', 'coordinator_user_id' => $firstCoordinator->id], $firstSales);
        $second = $service->convertToFreelance($secondLead, ['nik_koordinator' => 'K-2', 'coordinator_user_id' => $secondCoordinator->id], $secondSales);

        $this->assertSame($firstBranch->id, $first->branch_id);
        $this->assertSame($secondBranch->id, $second->branch_id);
        $this->assertNotSame($first->oasis_sync_id, $second->oasis_sync_id);
    }

    private function context(): array
    {
        $branch = Branch::create(['name' => 'Branch '.Str::random(6), 'code' => strtoupper(Str::random(5)), 'sheet_id' => 'sheet-'.Str::random(8), 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Project '.Str::random(6), 'is_active' => true]);
        $sales = $this->user('manager', $branch, 'Manager '.Str::random(5));
        $sales->assignedProjects()->attach($project->id, ['is_primary' => true, 'is_active' => true]);
        $lead = $this->lead($sales, $project);

        return [$branch, $project, $sales, $lead];
    }

    private function user(string $roleSlug, Branch $branch, string $name): User
    {
        $role = Role::where('slug', $roleSlug)->firstOrFail();

        return User::factory()->create([
            'name' => $name, 'role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now(),
        ]);
    }

    private function lead(User $sales, LeadMaster $project, string $name = 'Lifecycle Lead'): SalesLead
    {
        return SalesLead::create([
            'branch_id' => $project->branch_id, 'project_id' => $project->id, 'sales_user_id' => $sales->id,
            'lead_date' => '2026-08-03', 'customer_name' => $name, 'phone' => '081234567890',
            'created_by' => $sales->id, 'updated_by' => $sales->id,
        ]);
    }

    private function consumerLink(SalesLead $lead): SalesLeadConsumerLink
    {
        return SalesLeadConsumerLink::create([
            'sales_lead_id' => $lead->id, 'branch_id' => $lead->branch_id, 'actor_id' => $lead->sales_user_id,
            'operation_uuid' => (string) Str::uuid(), 'oasis_sync_id' => (string) Str::uuid(),
            'sheet_name' => 'data_konsumen', 'remote_row_number' => 3, 'status' => 'completed',
            'sheet_type' => 'data_konsumen', 'nik' => '0123456789012345', 'id_kavling' => 'A-1', 'converted_at' => now(),
        ]);
    }

    private function mockAppendWriter(): void
    {
        $writer = Mockery::mock(SalesLeadSpreadsheetWriter::class);
        $writer->shouldReceive('append')->once()->andReturnUsing(fn (SalesLead $item, string $sheet, array $fields, string $uuid) => $this->writeResult($item, $sheet, $uuid));
        $this->app->instance(SalesLeadSpreadsheetWriter::class, $writer);
    }

    private function writeResult(SalesLead $lead, string $sheet, string $uuid): SalesLeadSpreadsheetWriteResult
    {
        return new SalesLeadSpreadsheetWriteResult('sheet-'.$lead->branch_id, $sheet, ++$this->remoteRowNumber, $uuid);
    }

    private function visitData(string $date): array
    {
        return ['completion' => 'complete', 'tanggal' => $date, 'waktu' => 'pagi', 'status' => 'utj', 'keterangan' => 'Kunjungan'];
    }
}
