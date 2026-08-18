<?php

namespace Tests\Feature;

use App\Enums\SalesLeadStatus;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesCoordinatorSales;
use App\Models\SalesLead;
use App\Models\SalesLeadSiteVisit;
use App\Models\SalesLeadStatusHistory;
use App\Models\User;
use App\Services\GoogleSheetsApiService;
use App\Services\SalesLeadSpreadsheetWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesLeadSiteVisitResultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_results_visibility_uses_exact_or_evidence_and_survives_later_statuses(): void
    {
        [$sales, $lead] = $this->context();
        $this->assertFalse($lead->shouldShowSiteVisitResults());

        $lead->update(['current_status' => SalesLeadStatus::Utj]);
        $this->assertFalse($lead->fresh()->shouldShowSiteVisitResults());

        $lead->update(['current_status' => SalesLeadStatus::SiteVisit]);
        $this->assertTrue($lead->fresh()->shouldShowSiteVisitResults());

        $lead->update(['current_status' => SalesLeadStatus::Utj]);
        $this->history($lead, SalesLeadStatus::SiteVisit);
        $this->assertTrue($lead->fresh()->shouldShowSiteVisitResults());
        $this->assertTrue($lead->fresh()->load('statusHistories')->shouldShowSiteVisitResults());

        SalesLeadStatusHistory::query()->delete();
        $this->incompleteVisit($lead, $sales);
        $this->assertTrue($lead->fresh()->shouldShowSiteVisitResults());
        $this->assertTrue($lead->fresh()->load('siteVisits')->shouldShowSiteVisitResults());
    }

    public function test_request_validates_every_value_notes_and_rejects_forged_identity_fields(): void
    {
        [$sales, $lead] = $this->context();
        config()->set('services.google_sheets.sales_lead_sync_enabled', false);

        foreach (['pagi', 'siang', 'sore', 'malam'] as $time) {
            [$valueSales, $valueLead] = $this->context();
            $this->actingAs($valueSales)->postJson(route('sales-leads.site-visits.store', $valueLead), $this->complete($time))->assertOk();
        }
        foreach (['follow up', 'non ok', 'utj'] as $status) {
            [$valueSales, $valueLead] = $this->context();
            $payload = $this->complete('pagi', $status, $status === 'non ok' ? 'Tidak sesuai' : 'Catatan');
            $this->actingAs($valueSales)->postJson(route('sales-leads.site-visits.store', $valueLead), $payload)->assertOk();
        }

        [$invalidSales, $invalidLead] = $this->context();
        $this->actingAs($invalidSales)->from(route('sales-leads.show', $invalidLead))
            ->post(route('sales-leads.site-visits.store', $invalidLead), $this->complete('pagi', 'non ok', null))
            ->assertSessionHasErrors('keterangan');

        foreach (['customer_name', 'sales_lead_id', 'project_id', 'branch_id'] as $field) {
            [$invalidSales, $invalidLead] = $this->context();
            $this->actingAs($invalidSales)->from(route('sales-leads.show', $invalidLead))
                ->post(route('sales-leads.site-visits.store', $invalidLead), $this->complete() + [$field => '1'])
                ->assertSessionHasErrors($field);
        }
    }

    public function test_complete_create_stores_exact_fields_advances_utj_and_does_not_regress_slik_or_akad(): void
    {
        [$sales, $lead] = $this->context();
        config()->set('services.google_sheets.sales_lead_sync_enabled', false);
        $payload = $this->complete('malam', 'utj', 'Catatan tepat');

        $this->actingAs($sales)->postJson(route('sales-leads.site-visits.store', $lead), $payload)->assertOk();
        $visit = $lead->siteVisits()->firstOrFail();
        $this->assertSame('2026-08-13', $visit->visit_date->toDateString());
        $this->assertSame('malam', $visit->visit_time);
        $this->assertSame('utj', $visit->visit_status);
        $this->assertSame('Catatan tepat', $visit->notes);
        $this->assertSame('2026-08-13 19:00:00', $visit->visited_at->format('Y-m-d H:i:s'));
        $this->assertSame(SalesLeadStatus::Utj, $lead->fresh()->current_status);
        $this->assertDatabaseHas('sales_lead_status_histories', ['sales_lead_id' => $lead->id, 'status' => 'utj', 'source' => 'site_visit']);

        foreach ([SalesLeadStatus::SlikCheck, SalesLeadStatus::Akad] as $status) {
            $lead->update(['current_status' => $status]);
            $this->actingAs($sales)->postJson(route('sales-leads.site-visits.store', $lead), $this->complete('siang', 'follow up'))->assertOk();
            $this->assertSame($status, $lead->fresh()->current_status);
            $visit = $lead->siteVisits()->latest('id')->firstOrFail();
            $this->actingAs($sales)->patchJson(route('sales-leads.site-visits.update', [$lead, $visit]), $this->complete('sore', 'non ok', 'Tetap'))
                ->assertOk();
            $this->assertSame($status, $lead->fresh()->current_status);
        }
    }

    public function test_multiple_posts_uuid_retry_incomplete_completion_and_completed_edit_keep_rows(): void
    {
        [$sales, $lead] = $this->context();
        config()->set('services.google_sheets.sales_lead_sync_enabled', false);
        $uuid = (string) Str::uuid();

        $this->actingAs($sales)->postJson(route('sales-leads.site-visits.store', $lead), ['completion' => 'isi_nanti', 'operation_uuid' => $uuid])->assertOk();
        $this->actingAs($sales)->postJson(route('sales-leads.site-visits.store', $lead), ['completion' => 'isi_nanti', 'operation_uuid' => $uuid])->assertOk();
        $visit = $lead->siteVisits()->sole();
        $this->assertFalse($visit->is_completed);
        $this->assertNull($visit->visit_date);

        $this->actingAs($sales)->patchJson(route('sales-leads.site-visits.update', [$lead, $visit]), $this->complete())->assertOk();
        $this->assertSame(1, $lead->siteVisits()->count());
        $this->assertTrue($visit->fresh()->is_completed);

        $this->actingAs($sales)->patchJson(route('sales-leads.site-visits.update', [$lead, $visit]), $this->complete('sore', 'follow up', 'Diubah'))->assertOk();
        $this->actingAs($sales)->postJson(route('sales-leads.site-visits.store', $lead), $this->complete('malam', 'follow up'))->assertOk();
        $this->assertSame(2, $lead->siteVisits()->count());
        $this->assertSame('malam', $lead->fresh()->siteVisits()->latest('id')->firstOrFail()->visit_time);
    }

    public function test_forged_lead_visit_pair_is_not_found(): void
    {
        [$sales, $lead] = $this->context();
        [, $otherLead] = $this->context();
        $visit = $this->incompleteVisit($otherLead, $sales);

        $this->actingAs($sales)->patchJson(route('sales-leads.site-visits.update', [$lead, $visit]), $this->complete())->assertNotFound();
    }

    public function test_sales_owner_can_read_and_write_but_non_owner_and_standalone_sales_cannot(): void
    {
        [$sales, $lead, $project] = $this->context();
        $other = $this->user('sales', $lead->branch);
        $other->assignedProjects()->attach($project, ['is_primary' => true, 'is_active' => true]);
        $standalone = $this->user('sales', $lead->branch);

        $this->actingAs($sales)->get(route('sales-leads.show', $lead))->assertOk();
        $this->actingAs($sales)->postJson(route('sales-leads.site-visits.store', $lead), ['completion' => 'isi_nanti'])->assertOk();
        $this->actingAs($other)->get(route('sales-leads.show', $lead))->assertForbidden();
        $this->actingAs($other)->postJson(route('sales-leads.site-visits.store', $lead), ['completion' => 'isi_nanti'])->assertForbidden();
        $this->actingAs($standalone)->get(route('sales-leads.show', $lead))->assertForbidden();
    }

    public function test_coordinator_uses_same_site_visit_workflow_for_current_team_sales(): void
    {
        [$sales, $lead, $project] = $this->context();
        $lead->update(['current_status' => SalesLeadStatus::SiteVisit]);
        $coordinator = $this->user('sales_coordinator', $lead->branch);
        $coordinator->assignedProjects()->attach($project, ['is_primary' => true, 'is_active' => true]);
        SalesCoordinatorSales::create(['coordinator_user_id' => $coordinator->id, 'sales_user_id' => $sales->id, 'is_active' => true]);

        $this->actingAs($coordinator)->get(route('sales-leads.show', $lead))->assertOk()->assertSee('Isi Hasil Cek Lokasi');
        $this->actingAs($coordinator)->postJson(route('sales-leads.site-visits.store', $lead), $this->complete())->assertOk();
        $visit = $lead->siteVisits()->sole();
        $this->assertSame($sales->id, $lead->fresh()->sales_user_id);
        $this->assertSame($lead->id, $visit->sales_lead_id);
        $this->actingAs($coordinator)->patchJson(route('sales-leads.site-visits.update', [$lead, $visit]), $this->complete('siang'))->assertOk();
    }

    public function test_coordinator_cannot_record_site_visit_for_inactive_or_unrelated_sales(): void
    {
        [$sales, $lead, $project] = $this->context();
        $coordinator = $this->user('sales_coordinator', $lead->branch);
        $coordinator->assignedProjects()->attach($project, ['is_primary' => true, 'is_active' => true]);
        SalesCoordinatorSales::create(['coordinator_user_id' => $coordinator->id, 'sales_user_id' => $sales->id, 'is_active' => false]);
        $this->actingAs($coordinator)->postJson(route('sales-leads.site-visits.store', $lead), ['completion' => 'isi_nanti'])->assertForbidden();
        SalesCoordinatorSales::create(['coordinator_user_id' => $coordinator->id, 'sales_user_id' => $this->user('sales', $lead->branch)->id, 'is_active' => true]);
        $this->actingAs($coordinator)->postJson(route('sales-leads.site-visits.store', $lead), ['completion' => 'isi_nanti'])->assertForbidden();
    }

    public function test_coordinator_supervisor_and_admin_have_scoped_read_only_access(): void
    {
        [$sales, $lead] = $this->context();
        [, $foreignLead] = $this->context();
        $coordinator = $this->user('sales_coordinator', $lead->branch);
        SalesCoordinatorSales::create(['coordinator_user_id' => $coordinator->id, 'sales_user_id' => $sales->id]);
        $historical = $this->user('sales_coordinator', $lead->branch);
        $supervisor = $this->user('supervisor', $lead->branch);
        $admin = $this->user('admin', $lead->branch);
        foreach ([$coordinator, $supervisor, $admin] as $viewer) {
            $viewer->assignedProjects()->attach($lead->project_id, ['is_primary' => true, 'is_active' => true]);
        }

        foreach ([$coordinator, $supervisor, $admin] as $viewer) {
            $this->actingAs($viewer)->get(route('sales-leads.show', $lead))->assertOk();
            if ($viewer->role?->slug === 'sales_coordinator') {
                $this->actingAs($viewer)->postJson(route('sales-leads.site-visits.store', $lead), ['completion' => 'isi_nanti'])->assertOk();
            } else {
                $this->actingAs($viewer)->postJson(route('sales-leads.site-visits.store', $lead), ['completion' => 'isi_nanti'])->assertForbidden();
            }
            $this->actingAs($viewer)->get(route('sales-leads.show', $foreignLead))->assertForbidden();
        }
        $this->actingAs($historical)->get(route('sales-leads.show', $lead))->assertForbidden();
    }

    public function test_local_mode_never_resolves_writer_or_google_and_audit_is_safe_once_per_write(): void
    {
        [$sales, $lead] = $this->context();
        config()->set('services.google_sheets.sales_lead_sync_enabled', false);
        $this->app->bind(SalesLeadSpreadsheetWriter::class, fn () => throw new \RuntimeException('writer resolved'));
        $this->app->bind(GoogleSheetsApiService::class, fn () => throw new \RuntimeException('google resolved'));
        $userBefore = $sales->fresh()->getAttributes();

        $this->actingAs($sales)->postJson(route('sales-leads.site-visits.store', $lead), $this->complete())->assertOk();
        $visit = $lead->siteVisits()->sole();
        $this->actingAs($sales)->patchJson(route('sales-leads.site-visits.update', [$lead, $visit]), $this->complete('siang'))->assertOk();

        $logs = ActivityLog::where('subject_type', SalesLeadSiteVisit::class)->where('subject_id', $visit->id)->get();
        $this->assertSame(['sales_lead_site_visit_created', 'sales_lead_site_visit_updated'], $logs->pluck('event')->all());
        $this->assertFalse($logs->contains(fn (ActivityLog $log) => array_key_exists('notes', $log->properties) || str_contains($log->description, 'Catatan')));
        $this->assertSame($userBefore, $sales->fresh()->getAttributes());
    }

    private function context(): array
    {
        $branch = Branch::create(['name' => 'Branch '.Str::random(5), 'code' => strtoupper(Str::random(5)), 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Project '.Str::random(5), 'is_active' => true]);
        $sales = $this->user('sales', $branch);
        $sales->assignedProjects()->attach($project, ['is_primary' => true, 'is_active' => true]);
        $lead = SalesLead::create(['branch_id' => $branch->id, 'project_id' => $project->id, 'sales_user_id' => $sales->id, 'lead_date' => '2026-08-13', 'customer_name' => 'Customer', 'created_by' => $sales->id, 'updated_by' => $sales->id]);

        return [$sales, $lead, $project];
    }

    private function user(string $role, Branch $branch): User
    {
        $user = User::factory()->create(['role_id' => Role::where('slug', $role)->value('id'), 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $user->branches()->syncWithoutDetaching([$branch->id => ['can_view' => true, 'can_edit' => true, 'can_sync' => true]]);

        return $user;
    }

    private function complete(string $time = 'pagi', string $status = 'follow up', ?string $notes = 'Catatan'): array
    {
        return ['completion' => 'complete', 'tanggal' => '2026-08-13', 'waktu' => $time, 'status' => $status, 'keterangan' => $notes];
    }

    private function incompleteVisit(SalesLead $lead, User $actor): SalesLeadSiteVisit
    {
        return SalesLeadSiteVisit::create(['sales_lead_id' => $lead->id, 'branch_id' => $lead->branch_id, 'actor_id' => $actor->id, 'operation_uuid' => (string) Str::uuid(), 'status' => 'incomplete', 'is_completed' => false]);
    }

    private function history(SalesLead $lead, SalesLeadStatus $status): void
    {
        SalesLeadStatusHistory::create(['sales_lead_id' => $lead->id, 'branch_id' => $lead->branch_id, 'actor_id' => $lead->sales_user_id, 'operation_uuid' => (string) Str::uuid(), 'status' => $status, 'source' => 'test', 'changed_at' => now()]);
    }
}
