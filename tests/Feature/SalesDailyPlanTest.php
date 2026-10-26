<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesCoordinatorSales;
use App\Models\SalesDailyPlan;
use App\Models\SalesDailyPlanItem;
use App\Models\SalesDailyPlanItemEvidence;
use App\Models\SalesDailyPlanItemReschedule;
use App\Models\User;
use App\Services\SalesDailyPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

class SalesDailyPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_plan_item_persists_sales_activity_category_and_location(): void
    {
        [, , $sales] = $this->context();
        $plan = SalesDailyPlan::create([
            'sales_user_id' => $sales->id,
            'work_date' => '2026-10-26',
            'status' => 'draft',
        ]);

        $item = SalesDailyPlanItem::create([
            'sales_daily_plan_id' => $plan->id,
            'position' => 0,
            'sales_activity_category' => 'Canvassing',
            'activity' => 'Kunjungan calon konsumen',
            'location' => 'Perumahan Solo',
            'detail' => 'Presentasi unit',
            'duration_minutes' => 60,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        $this->assertSame('Canvassing', $item->fresh()->sales_activity_category);
        $this->assertSame('Perumahan Solo', $item->fresh()->location);
    }

    public function test_daily_plan_item_evidence_relation_returns_upload_order(): void
    {
        [, , $sales] = $this->context();
        $plan = SalesDailyPlan::create([
            'sales_user_id' => $sales->id,
            'work_date' => '2026-10-26',
            'status' => 'submitted',
        ]);
        $item = SalesDailyPlanItem::create([
            'sales_daily_plan_id' => $plan->id,
            'position' => 0,
            'activity' => 'Kunjungan calon konsumen',
            'duration_minutes' => 60,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        SalesDailyPlanItemEvidence::create([
            'sales_daily_plan_item_id' => $item->id,
            'uploaded_by_user_id' => $sales->id,
            'storage_path' => 'sales-daily-plan-evidence/first.webp',
            'file_path' => 'sales-daily-plan-evidence/first.webp',
            'original_name' => 'first.jpg',
            'mime_type' => 'image/webp',
            'width' => 100,
            'height' => 100,
            'size_bytes' => 1000,
            'sha256' => str_repeat('a', 64),
        ]);
        SalesDailyPlanItemEvidence::create([
            'sales_daily_plan_item_id' => $item->id,
            'uploaded_by_user_id' => $sales->id,
            'storage_path' => 'sales-daily-plan-evidence/second.webp',
            'file_path' => 'sales-daily-plan-evidence/second.webp',
            'original_name' => 'second.jpg',
            'mime_type' => 'image/webp',
            'width' => 100,
            'height' => 100,
            'size_bytes' => 2000,
            'sha256' => str_repeat('b', 64),
        ]);

        $this->assertSame(
            ['first.jpg', 'second.jpg'],
            $item->evidence()->pluck('original_name')->all(),
        );
    }

    public function test_daily_plan_reschedule_audit_relationships_link_source_target_and_actor(): void
    {
        [, , $sales] = $this->context();
        $sourcePlan = SalesDailyPlan::create([
            'sales_user_id' => $sales->id,
            'work_date' => '2026-10-26',
            'status' => 'submitted',
        ]);
        $targetPlan = SalesDailyPlan::create([
            'sales_user_id' => $sales->id,
            'work_date' => '2026-10-27',
            'status' => 'draft',
        ]);
        $source = SalesDailyPlanItem::create([
            'sales_daily_plan_id' => $sourcePlan->id,
            'position' => 0,
            'activity' => 'Agenda asal',
            'duration_minutes' => 60,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);
        $target = SalesDailyPlanItem::create([
            'sales_daily_plan_id' => $targetPlan->id,
            'position' => 0,
            'activity' => 'Agenda asal',
            'duration_minutes' => 60,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        $audit = SalesDailyPlanItemReschedule::create([
            'source_item_id' => $source->id,
            'target_item_id' => $target->id,
            'actor_user_id' => $sales->id,
            'source_work_date' => '2026-10-26',
            'target_work_date' => '2026-10-27',
            'reason' => 'Ada acara mendadak.',
        ]);

        $this->assertSame($audit->id, $source->rescheduleTo()->firstOrFail()->id);
        $this->assertSame($audit->id, $target->rescheduleFrom()->firstOrFail()->id);
        $this->assertSame($source->id, $audit->sourceItem->id);
        $this->assertSame($target->id, $audit->targetItem->id);
        $this->assertSame($sales->id, $audit->actor->id);
    }

    public function test_duration_first_items_are_allocated_around_the_lunch_break(): void
    {
        [, , $sales] = $this->context();
        $plan = app(SalesDailyPlanService::class)->saveDraft($sales, CarbonImmutable::parse('2026-10-26'), [
            ['activity' => 'Prospek pagi', 'detail' => 'Hubungi daftar baru', 'duration_minutes' => 180],
            ['activity' => 'Follow-up', 'detail' => 'Kirim materi', 'duration_minutes' => 60],
            ['activity' => 'Administrasi', 'detail' => 'Perbarui catatan', 'duration_minutes' => 180],
        ]);

        $items = $plan->items()->orderBy('position')->get();
        $this->assertSame(['09:00', '13:00', '14:00'], $items->map(fn ($item) => substr($item->start_time, 0, 5))->all());
        $this->assertSame(['12:00', '14:00', '17:00'], $items->map(fn ($item) => substr($item->end_time, 0, 5))->all());
        $this->assertSame(420, $plan->totalDurationMinutes());
    }

    public function test_daily_plan_service_persists_category_and_location_in_a_draft(): void
    {
        [, , $sales] = $this->context();
        $plan = app(SalesDailyPlanService::class)->saveDraft($sales, CarbonImmutable::parse('2026-10-26'), [
            [
                'sales_activity_category' => 'Canvassing',
                'activity' => 'Kunjungan calon konsumen',
                'location' => 'Perumahan Solo',
                'detail' => 'Presentasi unit',
                'duration_minutes' => 60,
            ],
        ]);

        $item = $plan->items()->firstOrFail();

        $this->assertSame('Canvassing', $item->sales_activity_category);
        $this->assertSame('Perumahan Solo', $item->location);
    }

    public function test_daily_plan_store_requires_category_and_activity_title(): void
    {
        [, , $sales] = $this->context();
        $sales->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($sales)
            ->post(route('sales-daily-plans.store'), [
                'work_date' => '2026-10-26',
                'activities' => [['duration_minutes' => 420]],
            ])
            ->assertSessionHasErrors([
                'activities.0.sales_activity_category',
                'activities.0.activity',
            ]);
    }

    public function test_coordinator_cannot_revise_a_plan_with_an_item_from_another_plan(): void
    {
        [$branch, , $sales] = $this->context();
        $coordinator = $this->user('sales_coordinator', $branch, 'Koordinator');
        SalesCoordinatorSales::create([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $sales->id,
            'is_active' => true,
        ]);
        $service = app(SalesDailyPlanService::class);
        $service->saveDraft($sales, CarbonImmutable::parse('2026-10-26'), [
            ['activity' => 'Plan A', 'duration_minutes' => 420],
        ]);
        $planA = $service->submit($sales, CarbonImmutable::parse('2026-10-26'));
        $service->saveDraft($sales, CarbonImmutable::parse('2026-10-27'), [
            ['activity' => 'Plan B', 'duration_minutes' => 420],
        ]);
        $planB = $service->submit($sales, CarbonImmutable::parse('2026-10-27'));
        $itemA = $planA->items()->firstOrFail();
        $itemB = $planB->items()->firstOrFail();

        try {
            app(SalesDailyPlanService::class)->revise($coordinator, $planA, [
                ['id' => $itemB->id, 'activity' => 'Tidak valid', 'duration_minutes' => 420],
            ]);
            $this->fail('An item from another Daily Plan must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('activities.0.id', $exception->errors());
        }

        $this->assertDatabaseHas('sales_daily_plan_items', ['id' => $itemA->id, 'activity' => 'Plan A']);
    }

    public function test_owner_can_upload_two_daily_plan_item_evidence_files_and_audit_each_upload(): void
    {
        Storage::fake('agenda_evidence');
        [, , $sales] = $this->context();
        $item = $this->submittedItem($sales);

        foreach (['first.jpg', 'second.png'] as $filename) {
            $this->actingAs($sales)
                ->post(route('sales-daily-plan-items.evidence.store', $item), [
                    'photo' => UploadedFile::fake()->image($filename),
                ])
                ->assertRedirect()
                ->assertSessionDoesntHaveErrors();
        }

        $this->assertSame(2, $item->evidence()->count());
        $this->assertCount(2, Storage::disk('agenda_evidence')->allFiles());
        $this->assertSame(2, ActivityLog::where('event', 'sales_daily_plan_item_evidence_uploaded')->count());
    }

    public function test_third_or_invalid_daily_plan_item_evidence_does_not_change_storage_or_database(): void
    {
        Storage::fake('agenda_evidence');
        [, , $sales] = $this->context();
        $item = $this->submittedItem($sales);
        $route = route('sales-daily-plan-items.evidence.store', $item);

        $this->actingAs($sales)->post($route, ['photo' => UploadedFile::fake()->image('first.jpg')]);
        $this->actingAs($sales)->post($route, ['photo' => UploadedFile::fake()->image('second.jpg')]);

        $this->actingAs($sales)
            ->post($route, ['photo' => UploadedFile::fake()->image('third.jpg')])
            ->assertSessionHasErrors('photo');

        $this->assertSame(2, $item->evidence()->count());
        $this->assertCount(2, Storage::disk('agenda_evidence')->allFiles());

        $newItem = $this->submittedItem($sales, '2026-10-27');
        $this->actingAs($sales)
            ->post(route('sales-daily-plan-items.evidence.store', $newItem), [
                'photo' => UploadedFile::fake()->createWithContent('invalid.png', 'not an image'),
            ])
            ->assertSessionHasErrors('photo');

        $this->assertSame(0, $newItem->evidence()->count());
        $this->assertCount(2, Storage::disk('agenda_evidence')->allFiles());
    }

    public function test_owner_can_complete_submitted_item_once_and_completed_item_rejects_mutations(): void
    {
        Storage::fake('agenda_evidence');
        [, , $sales] = $this->context();
        $item = $this->submittedItem($sales);

        $this->actingAs($sales)
            ->patch(route('sales-daily-plan-items.complete', $item), ['activity_result' => 'Hasil kunjungan'])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertSame('completed', $item->fresh()->status);
        $this->assertSame(1, ActivityLog::where('event', 'sales_daily_plan_item_completed')->count());

        $this->actingAs($sales)
            ->post(route('sales-daily-plan-items.evidence.store', $item), [
                'photo' => UploadedFile::fake()->image('late.jpg'),
            ])
            ->assertForbidden();
        $this->actingAs($sales)
            ->patch(route('sales-daily-plan-items.complete', $item), ['activity_result' => 'Hasil kedua'])
            ->assertForbidden();

        $this->assertSame('Hasil kunjungan', $item->fresh()->activity_result);
        $this->assertSame(0, $item->evidence()->count());
    }

    public function test_daily_plan_item_evidence_and_completion_require_owner_and_submitted_plan(): void
    {
        Storage::fake('agenda_evidence');
        [$branch, , $sales] = $this->context();
        $otherSales = $this->user('sales', $branch, 'Sales Lain');
        $item = $this->submittedItem($sales);
        $evidenceRoute = route('sales-daily-plan-items.evidence.store', $item);
        $completeRoute = route('sales-daily-plan-items.complete', $item);

        $this->actingAs($otherSales)->post($evidenceRoute, [
            'photo' => UploadedFile::fake()->image('other.jpg'),
        ])->assertForbidden();
        $this->actingAs($otherSales)->patch($completeRoute, ['activity_result' => 'Tidak boleh'])->assertForbidden();

        $draft = app(SalesDailyPlanService::class)->saveDraft($sales, CarbonImmutable::parse('2026-10-27'), [
            ['activity' => 'Draft', 'duration_minutes' => 420],
        ]);
        $draftItem = $draft->items()->firstOrFail();
        $this->actingAs($sales)->post(route('sales-daily-plan-items.evidence.store', $draftItem), [
            'photo' => UploadedFile::fake()->image('draft.jpg'),
        ])->assertForbidden();
        $this->actingAs($sales)->patch(route('sales-daily-plan-items.complete', $draftItem), [
            'activity_result' => 'Belum boleh',
        ])->assertForbidden();

        $this->assertSame(0, $draftItem->evidence()->count());
        $this->assertSame('planned', $draftItem->fresh()->status);
    }

    public function test_sales_can_save_a_short_draft_but_submit_requires_exactly_420_minutes(): void
    {
        [, , $sales] = $this->context();
        $service = app(SalesDailyPlanService::class);
        $service->saveDraft($sales, CarbonImmutable::parse('2026-10-26'), [
            ['activity' => 'Prospek', 'duration_minutes' => 60],
        ]);

        $this->expectException(ValidationException::class);
        $service->submit($sales, CarbonImmutable::parse('2026-10-26'));
    }

    public function test_submitted_plan_cannot_be_changed_by_sales_but_sales_can_complete_an_item(): void
    {
        [, , $sales] = $this->context();
        $service = app(SalesDailyPlanService::class);
        $service->saveDraft($sales, CarbonImmutable::parse('2026-10-26'), [
            ['activity' => 'Prospek', 'duration_minutes' => 420],
        ]);
        $plan = $service->submit($sales, CarbonImmutable::parse('2026-10-26'));
        $item = $plan->items()->firstOrFail();

        $service->completeItem($sales, $item, 'Hasil follow-up');
        $this->assertSame('Hasil follow-up', $item->fresh()->activity_result);
        $this->assertSame('completed', $item->fresh()->status);

        $this->expectException(AccessDeniedHttpException::class);
        $service->saveDraft($sales, CarbonImmutable::parse('2026-10-26'), [
            ['activity' => 'Tidak boleh', 'duration_minutes' => 420],
        ]);
    }

    public function test_sales_can_reschedule_one_submitted_item_without_shifting_later_items(): void
    {
        [, , $sales] = $this->context();
        $service = app(SalesDailyPlanService::class);
        $service->saveDraft($sales, CarbonImmutable::parse('2026-10-26'), [
            ['activity' => 'Agenda asal', 'duration_minutes' => 60],
            ['activity' => 'Agenda kedua', 'duration_minutes' => 180],
            ['activity' => 'Agenda ketiga', 'duration_minutes' => 180],
        ]);
        $sourcePlan = $service->submit($sales, CarbonImmutable::parse('2026-10-26'));
        $sourceItems = $sourcePlan->items()->orderBy('position')->get();

        $targetPlan = $service->saveDraft($sales, CarbonImmutable::parse('2026-10-27'), [
            ['activity' => 'Agenda tujuan lama', 'duration_minutes' => 120],
        ]);
        $targetExisting = $targetPlan->items()->firstOrFail();

        $result = $service->rescheduleItem($sales, $sourceItems[0], 'Ada meeting mendadak.');
        $source = $result['source']->fresh();
        $target = $result['target']->fresh(['plan']);
        $laterItems = $sourcePlan->items()->orderBy('position')->get();
        $targetItems = $target->plan->items()->orderBy('position')->get();

        $this->assertSame('rescheduled', $source->status);
        $this->assertSame('09:00', substr($source->start_time, 0, 5));
        $this->assertSame('10:00', substr($source->end_time, 0, 5));
        $this->assertSame('10:00', substr($laterItems[1]->start_time, 0, 5));
        $this->assertSame('14:00', substr($laterItems[2]->start_time, 0, 5));
        $this->assertSame($targetExisting->id, $targetItems[0]->id);
        $this->assertSame('09:00', substr($targetItems[0]->start_time, 0, 5));
        $this->assertSame($target->id, $targetItems[1]->id);
        $this->assertSame('11:00', substr($target->start_time, 0, 5));
        $this->assertSame('12:00', substr($target->end_time, 0, 5));
        $this->assertDatabaseHas('sales_daily_plan_item_reschedules', [
            'source_item_id' => $source->id,
            'target_item_id' => $target->id,
            'reason' => 'Ada meeting mendadak.',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'sales_daily_plan_item_rescheduled',
            'subject_id' => $source->id,
        ]);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'sales_daily_plan_item_rescheduled_copy_created',
            'subject_id' => $target->id,
        ]);
        $this->assertSame('rescheduled', $service->itemState($source, CarbonImmutable::parse('2026-10-26 15:00')));

        $this->expectException(AccessDeniedHttpException::class);
        $service->completeItem($sales, $source, 'Tidak boleh diselesaikan ulang.');
    }

    public function test_reschedule_preserves_existing_destination_times_and_copies_activity_fields(): void
    {
        [, , $sales] = $this->context();
        $service = app(SalesDailyPlanService::class);
        $service->saveDraft($sales, CarbonImmutable::parse('2026-10-26'), [
            [
                'sales_activity_category' => 'Follow Up',
                'activity' => 'Agenda asal',
                'location' => 'Kantor klien',
                'detail' => 'Bawa proposal.',
                'duration_minutes' => 60,
            ],
            ['activity' => 'Agenda kedua', 'duration_minutes' => 360],
        ]);
        $sourcePlan = $service->submit($sales, CarbonImmutable::parse('2026-10-26'));
        $sourceItem = $sourcePlan->items()->firstOrFail();
        $targetPlan = $service->saveDraft($sales, CarbonImmutable::parse('2026-10-27'), [
            ['activity' => 'Agenda tujuan lama', 'duration_minutes' => 120],
        ]);
        $targetExisting = $targetPlan->items()->firstOrFail();
        $targetExisting->forceFill(['start_time' => '15:00:00', 'end_time' => '17:00:00'])->save();

        $result = $service->rescheduleItem($sales, $sourceItem, 'Klien meminta perubahan jadwal.');

        $this->assertSame('15:00', substr($targetExisting->fresh()->start_time, 0, 5));
        $this->assertSame('17:00', substr($targetExisting->fresh()->end_time, 0, 5));
        $this->assertSame('Follow Up', $result['target']->sales_activity_category);
        $this->assertSame('Kantor klien', $result['target']->location);
        $this->assertSame('Bawa proposal.', $result['target']->detail);
        $this->assertSame(60, $result['target']->duration_minutes);
    }

    public function test_rescheduled_target_can_be_saved_again_without_deleting_audit_item(): void
    {
        [, , $sales] = $this->context();
        $service = app(SalesDailyPlanService::class);
        $service->saveDraft($sales, CarbonImmutable::parse('2026-10-26'), [
            ['activity' => 'Agenda asal', 'duration_minutes' => 60],
            ['activity' => 'Agenda kedua', 'duration_minutes' => 360],
        ]);
        $sourcePlan = $service->submit($sales, CarbonImmutable::parse('2026-10-26'));
        $result = $service->rescheduleItem($sales, $sourcePlan->items()->firstOrFail(), 'Perlu dipindahkan.');
        $target = $result['target']->fresh();

        $saved = $service->saveDraft($sales, $target->plan->work_date->toImmutable(), [[
            'id' => $target->id,
            'activity' => 'Agenda dipindahkan diperbarui',
            'duration_minutes' => 60,
        ]]);

        $this->assertDatabaseHas('sales_daily_plan_item_reschedules', [
            'target_item_id' => $target->id,
        ]);
        $this->assertSame('Agenda dipindahkan diperbarui', $saved->items()->whereKey($target->id)->value('activity'));
    }

    public function test_coordinator_cannot_remove_a_rescheduled_source_item_from_revision(): void
    {
        [$branch, , $sales] = $this->context();
        $coordinator = $this->user('sales_coordinator', $branch, 'Koordinator Riwayat');
        SalesCoordinatorSales::create([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $sales->id,
            'is_active' => true,
        ]);
        $service = app(SalesDailyPlanService::class);
        $service->saveDraft($sales, CarbonImmutable::parse('2026-10-26'), [
            ['activity' => 'Agenda asal', 'duration_minutes' => 60],
            ['activity' => 'Agenda tetap', 'duration_minutes' => 360],
        ]);
        $sourcePlan = $service->submit($sales, CarbonImmutable::parse('2026-10-26'));
        $sourceItem = $sourcePlan->items()->firstOrFail();
        $service->rescheduleItem($sales, $sourceItem, 'Perlu dipindahkan.');

        $this->expectException(ValidationException::class);
        $service->revise($coordinator, $sourcePlan, [[
            'id' => $sourcePlan->items()->where('activity', 'Agenda tetap')->value('id'),
            'activity' => 'Agenda tetap',
            'duration_minutes' => 360,
        ], [
            'activity' => 'Pengganti',
            'duration_minutes' => 60,
        ]]);
    }

    public function test_reschedule_skips_full_plans_and_weekends_before_creating_a_draft(): void
    {
        [, , $sales] = $this->context();
        $service = app(SalesDailyPlanService::class);
        $service->saveDraft($sales, CarbonImmutable::parse('2026-10-30'), [
            ['activity' => 'Agenda Jumat', 'duration_minutes' => 420],
        ]);
        $sourcePlan = $service->submit($sales, CarbonImmutable::parse('2026-10-30'));
        $sourceItem = $sourcePlan->items()->firstOrFail();
        $service->saveDraft($sales, CarbonImmutable::parse('2026-11-02'), [
            ['activity' => 'Agenda Senin penuh', 'duration_minutes' => 420],
        ]);

        $result = $service->rescheduleItem($sales, $sourceItem, 'Pekerjaan lapangan tertunda.');

        $this->assertSame('2026-11-03', $result['target']->plan->work_date->toDateString());
        $this->assertSame('draft', $result['target']->plan->status);
        $this->assertSame(420, $result['target']->duration_minutes);
    }

    public function test_completed_or_draft_items_cannot_be_rescheduled(): void
    {
        [, , $sales] = $this->context();
        $service = app(SalesDailyPlanService::class);
        $draft = $service->saveDraft($sales, CarbonImmutable::parse('2026-10-26'), [
            ['activity' => 'Agenda draft', 'duration_minutes' => 60],
        ]);
        $draftItem = $draft->items()->firstOrFail();

        $this->expectException(AccessDeniedHttpException::class);
        $service->rescheduleItem($sales, $draftItem, 'Tidak boleh.');
    }

    public function test_already_rescheduled_item_cannot_be_rescheduled_again(): void
    {
        [, , $sales] = $this->context();
        $service = app(SalesDailyPlanService::class);
        $service->saveDraft($sales, CarbonImmutable::parse('2026-10-26'), [
            ['activity' => 'Agenda asal', 'duration_minutes' => 420],
        ]);
        $sourcePlan = $service->submit($sales, CarbonImmutable::parse('2026-10-26'));
        $sourceItem = $sourcePlan->items()->firstOrFail();
        $service->rescheduleItem($sales, $sourceItem, 'Pindah sekali.');

        $this->expectException(AccessDeniedHttpException::class);
        $service->rescheduleItem($sales, $sourceItem->fresh(), 'Pindah lagi.');
    }

    public function test_coordinator_can_reorder_a_submitted_plan_without_changing_durations(): void
    {
        [$branch, , $sales] = $this->context();
        $coordinator = $this->user('sales_coordinator', $branch, 'Koordinator');
        SalesCoordinatorSales::create([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $sales->id,
            'is_active' => true,
        ]);
        $service = app(SalesDailyPlanService::class);
        $service->saveDraft($sales, CarbonImmutable::parse('2026-10-26'), [
            ['activity' => 'Pertama', 'duration_minutes' => 180],
            ['activity' => 'Kedua', 'duration_minutes' => 240],
        ]);
        $plan = $service->submit($sales, CarbonImmutable::parse('2026-10-26'));
        $items = $plan->items()->orderBy('position')->get();

        $revised = $service->revise($coordinator, $plan, [
            ['id' => $items[1]->id, 'activity' => 'Kedua', 'duration_minutes' => 240],
            ['id' => $items[0]->id, 'activity' => 'Pertama', 'duration_minutes' => 180],
        ]);

        $revisedItems = $revised->items()->orderBy('position')->get();
        $this->assertSame(['Kedua', 'Pertama'], $revisedItems->pluck('activity')->all());
        $this->assertSame([240, 180], $revisedItems->pluck('duration_minutes')->all());
        $this->assertSame(['09:00', '14:00'], $revisedItems->map(fn ($item) => substr($item->start_time, 0, 5))->all());
        $this->assertDatabaseHas('sales_daily_plan_revisions', [
            'sales_daily_plan_id' => $plan->id,
            'actor_user_id' => $coordinator->id,
            'action' => 'revised',
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $sales->id,
            'type' => 'sales_daily_plan_revised',
        ]);
    }

    public function test_daily_plan_rejects_weekends_and_more_than_420_minutes(): void
    {
        [, , $sales] = $this->context();
        $service = app(SalesDailyPlanService::class);

        try {
            $service->saveDraft($sales, CarbonImmutable::parse('2026-10-25'), [
                ['activity' => 'Minggu', 'duration_minutes' => 60],
            ]);
            $this->fail('Weekend plans must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('work_date', $exception->errors());
        }

        $this->expectException(ValidationException::class);
        $service->saveDraft($sales, CarbonImmutable::parse('2026-10-26'), [
            ['activity' => 'Terlalu lama', 'duration_minutes' => 421],
        ]);
    }

    public function test_sales_can_reach_the_daily_plan_store_route(): void
    {
        [, , $sales] = $this->context();
        $sales->forceFill(['email_verified_at' => now()])->save();

        $response = $this->actingAs($sales)->post(route('sales-daily-plans.store'), [
            'work_date' => '2026-10-26',
            'activities' => [
                ['sales_activity_category' => 'Canvassing', 'activity' => 'Prospek', 'duration_minutes' => 420],
            ],
        ]);

        $response->assertRedirect();
        $this->assertTrue(SalesDailyPlan::query()
            ->where('sales_user_id', $sales->id)
            ->whereDate('work_date', '2026-10-26')
            ->where('status', 'draft')
            ->exists());
    }

    public function test_sales_can_reschedule_daily_plan_item_through_the_named_route(): void
    {
        [, , $sales] = $this->context();
        $service = app(SalesDailyPlanService::class);
        $service->saveDraft($sales, CarbonImmutable::parse('2026-10-26'), [
            ['activity' => 'Agenda route', 'duration_minutes' => 420],
        ]);
        $plan = $service->submit($sales, CarbonImmutable::parse('2026-10-26'));
        $item = $plan->items()->firstOrFail();

        $this->actingAs($sales)
            ->post(route('sales-daily-plan-items.reschedule', $item), ['reason' => 'Ada acara mendadak.'])
            ->assertRedirect(route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => '2026-10-26']))
            ->assertSessionHas('success');

        $this->assertSame('rescheduled', $item->fresh()->status);
    }

    public function test_daily_plan_reschedule_route_requires_a_reason(): void
    {
        [, , $sales] = $this->context();
        $service = app(SalesDailyPlanService::class);
        $service->saveDraft($sales, CarbonImmutable::parse('2026-10-26'), [
            ['activity' => 'Agenda validasi', 'duration_minutes' => 420],
        ]);
        $plan = $service->submit($sales, CarbonImmutable::parse('2026-10-26'));
        $item = $plan->items()->firstOrFail();

        $this->actingAs($sales)
            ->post(route('sales-daily-plan-items.reschedule', $item), [])
            ->assertSessionHasErrors('reason');
    }

    public function test_sales_cannot_reschedule_another_sales_daily_plan_item(): void
    {
        [$branch, , $sales] = $this->context();
        $otherSales = $this->user('sales', $branch, 'Sales Lain');
        $service = app(SalesDailyPlanService::class);
        $service->saveDraft($sales, CarbonImmutable::parse('2026-10-26'), [
            ['activity' => 'Agenda pemilik', 'duration_minutes' => 420],
        ]);
        $plan = $service->submit($sales, CarbonImmutable::parse('2026-10-26'));
        $item = $plan->items()->firstOrFail();

        $this->actingAs($otherSales)
            ->post(route('sales-daily-plan-items.reschedule', $item), ['reason' => 'Tidak boleh.'])
            ->assertForbidden();

        $this->assertSame('planned', $item->fresh()->status);
    }

    public function test_daily_plan_item_state_tracks_upcoming_ongoing_completed_and_overdue_items(): void
    {
        [, , $sales] = $this->context();
        $plan = SalesDailyPlan::create([
            'sales_user_id' => $sales->id,
            'work_date' => '2026-10-26',
            'status' => 'submitted',
        ]);
        $item = SalesDailyPlanItem::create([
            'sales_daily_plan_id' => $plan->id,
            'position' => 0,
            'activity' => 'Prospek',
            'duration_minutes' => 60,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'status' => 'planned',
        ]);
        $service = app(SalesDailyPlanService::class);

        $this->assertSame('upcoming', $service->itemState($item, CarbonImmutable::parse('2026-10-26 08:59')));
        $this->assertSame('ongoing', $service->itemState($item, CarbonImmutable::parse('2026-10-26 09:30')));
        $this->assertSame('overdue', $service->itemState($item, CarbonImmutable::parse('2026-10-26 10:01')));

        $item->update(['status' => 'completed']);

        $this->assertSame('completed', $service->itemState($item->fresh(), CarbonImmutable::parse('2026-10-26 09:30')));
    }

    public function test_daily_plan_reschedule_changelog_is_present_once(): void
    {
        $title = 'Penjadwalan Ulang Kertas Agenda Harian';

        $this->assertDatabaseHas('changelogs', [
            'version' => null,
            'title' => $title,
            'category' => 'changed',
        ]);
        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());
    }

    public function test_submitted_daily_plan_paper_groups_items_by_state(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-26 09:30:00', config('app.timezone')));
        try {
            [, , $sales] = $this->context();
            $sales->forceFill(['email_verified_at' => now()])->save();
            $plan = SalesDailyPlan::create([
                'sales_user_id' => $sales->id,
                'work_date' => '2026-10-26',
                'status' => 'submitted',
            ]);
            SalesDailyPlanItem::create([
                'sales_daily_plan_id' => $plan->id,
                'position' => 0,
                'activity' => 'Prospek',
                'duration_minutes' => 60,
                'start_time' => '09:00:00',
                'end_time' => '10:00:00',
                'status' => 'planned',
            ]);
            SalesDailyPlanItem::create([
                'sales_daily_plan_id' => $plan->id,
                'position' => 1,
                'activity' => 'Follow-up',
                'duration_minutes' => 60,
                'start_time' => '10:00:00',
                'end_time' => '11:00:00',
                'status' => 'planned',
            ]);
            SalesDailyPlanItem::create([
                'sales_daily_plan_id' => $plan->id,
                'position' => 2,
                'activity' => 'Administrasi',
                'duration_minutes' => 60,
                'start_time' => '08:00:00',
                'end_time' => '09:00:00',
                'status' => 'completed',
            ]);

            $this->actingAs($sales)->get(route('sales-agendas.index', ['work_date' => '2026-10-26']))
                ->assertOk()
                ->assertSee('Agenda yang sedang berjalan')
                ->assertSee('Akan datang')
                ->assertSee('Selesai');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_submitted_daily_plan_items_expose_evidence_and_read_only_completed_history(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-26 09:30:00', config('app.timezone')));
        try {
            [, , $sales] = $this->context();
            $sales->forceFill(['email_verified_at' => now()])->save();
            $plan = SalesDailyPlan::create([
                'sales_user_id' => $sales->id,
                'work_date' => '2026-10-26',
                'status' => 'submitted',
            ]);
            $planned = SalesDailyPlanItem::create([
                'sales_daily_plan_id' => $plan->id,
                'position' => 0,
                'sales_activity_category' => 'Canvassing',
                'activity' => 'Kunjungan terjadwal',
                'location' => 'Perumahan Solo',
                'detail' => 'Presentasi unit',
                'duration_minutes' => 60,
                'start_time' => '09:00:00',
                'end_time' => '10:00:00',
                'status' => 'planned',
            ]);
            $completed = SalesDailyPlanItem::create([
                'sales_daily_plan_id' => $plan->id,
                'position' => 1,
                'sales_activity_category' => 'Follow-up',
                'activity' => 'Follow-up selesai',
                'duration_minutes' => 360,
                'start_time' => '10:00:00',
                'end_time' => '17:00:00',
                'activity_result' => 'Hasil selesai',
                'status' => 'completed',
                'completed_by' => $sales->id,
                'completed_at' => now(),
            ]);
            SalesDailyPlanItemEvidence::create([
                'sales_daily_plan_item_id' => $completed->id,
                'uploaded_by_user_id' => $sales->id,
                'storage_path' => 'sales-daily-plan-evidence/completed.webp',
                'original_name' => 'completed.webp',
                'mime_type' => 'image/webp',
                'width' => 100,
                'height' => 100,
                'size_bytes' => 1000,
                'sha256' => str_repeat('a', 64),
            ]);

            $html = $this->actingAs($sales)
                ->get(route('sales-agendas.index', ['work_date' => '2026-10-26']))
                ->assertOk()
                ->assertSee('420 menit')
                ->assertSee('Agenda yang sedang berjalan')
                ->assertSee('Kunjungan terjadwal')
                ->assertSee('Unggah Bukti Foto')
                ->assertSee('Selesaikan')
                ->assertSee(route('sales-daily-plan-items.evidence.store', $planned), false)
                ->assertSee(route('sales-daily-plan-items.evidence.show', [$completed, $completed->evidence()->first()]), false)
                ->assertSee('Hasil selesai')
                ->assertDontSee('Agenda Tambahan')
                ->getContent();

            $this->assertSame(1, substr_count($html, 'name="activity_result"'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_daily_plan_single_workflow_changelog_is_unique_and_rendered(): void
    {
        $title = 'Kertas Agenda Harian Menjadi Alur Tunggal dengan Bukti Foto';
        $role = Role::firstOrCreate(['slug' => 'superadmin'], ['name' => 'Super Admin', 'is_superadmin' => true]);
        $superadmin = User::factory()->create([
            'role_id' => $role->id,
            'password_changed_at' => now(),
        ]);

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());
        $this->actingAs($superadmin)->get(route('changelogs.index'))->assertOk()->assertSeeText($title);
    }

    private function context(): array
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $project = LeadMaster::create([
            'branch_id' => $branch->id,
            'project_name' => 'Solo Project',
            'is_active' => true,
        ]);
        $sales = $this->user('sales', $branch, 'Solo Sales');
        $sales->assignedProjects()->attach($project, ['is_primary' => true]);

        return [$branch, $project, $sales];
    }

    private function submittedItem(User $sales, string $date = '2026-10-26'): SalesDailyPlanItem
    {
        app(SalesDailyPlanService::class)->saveDraft($sales, CarbonImmutable::parse($date), [
            ['activity' => 'Agenda tersubmit', 'duration_minutes' => 420],
        ]);

        return app(SalesDailyPlanService::class)->submit($sales, CarbonImmutable::parse($date))->items()->firstOrFail();
    }

    private function user(string $roleSlug, ?Branch $branch = null, ?string $name = null): User
    {
        $role = Role::firstOrCreate([
            'slug' => $roleSlug,
        ], [
            'name' => ucfirst($roleSlug),
            'is_superadmin' => $roleSlug === 'superadmin',
        ]);

        return User::factory()->create([
            'name' => $name ?? ucfirst($roleSlug),
            'role_id' => $role->id,
            'branch_id' => $branch?->id,
            'password_changed_at' => now(),
        ]);
    }
}
