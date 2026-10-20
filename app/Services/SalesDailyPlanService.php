<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\SalesDailyPlan;
use App\Models\User;
use App\Policies\SalesDailyPlanPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

class SalesDailyPlanService
{
    public function __construct(
        private readonly SalesDailyPlanScheduleService $schedule,
        private readonly CollaborationNotificationService $notifications,
    ) {}

    public function create(User $actor, string $date): SalesDailyPlan
    {
        $planDate = CarbonImmutable::parse($date);
        if (! $planDate->isWeekday()) {
            throw new InvalidArgumentException('Daily Plan hanya dapat dibuat pada hari kerja Senin sampai Jumat.');
        }
        if (! $actor->isSales() || ! $actor->branch_id) {
            throw new InvalidArgumentException('Daily Plan hanya dapat dibuat oleh Sales aktif yang memiliki cabang.');
        }

        return SalesDailyPlan::query()->firstOrCreate(
            ['sales_user_id' => $actor->id, 'plan_date' => $planDate->toDateString()],
            ['branch_id' => $actor->branch_id, 'status' => 'draft', 'last_modified_by' => $actor->id, 'lock_version' => 0],
        );
    }

    public function addAgenda(SalesDailyPlan $plan, User $actor, array $data): ContentItem
    {
        $this->assertPlanMutationAllowed($plan, $actor);
        if (filled($data['sales_project_id'] ?? null)) {
            $project = LeadMaster::query()->find((int) $data['sales_project_id']);
            if (! $project || ! $project->is_active || (int) $project->branch_id !== (int) $plan->branch_id) {
                throw new InvalidArgumentException('Proyek Agenda harus aktif dan berada di cabang Daily Plan.');
            }
        }
        $nextOrder = ((int) $plan->agendas()->max('sort_order')) + 1;
        $agenda = ContentItem::create([
            'branch_id' => $plan->branch_id,
            'sales_project_id' => $data['sales_project_id'] ?? null,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'daily_plan_id' => $plan->id,
            'visibility' => 'personal',
            'title' => $data['title'] ?? $data['sales_activity_category'],
            'task_detail' => $data['task_detail'],
            'scheduled_date' => $plan->plan_date->toDateString(),
            'status' => 'planned',
            'duration_minutes' => (int) $data['duration_minutes'],
            'sort_order' => $nextOrder,
            'sales_activity_category' => $data['sales_activity_category'],
            'owner_user_id' => $plan->sales_user_id,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $this->recalculate($plan, $actor);

        return $agenda->fresh();
    }

    public function updateAgenda(SalesDailyPlan $plan, ContentItem $agenda, User $actor, array $data): SalesDailyPlan
    {
        $this->assertAgendaBelongs($plan, $agenda);
        $this->assertPlanMutationAllowed($plan, $actor);
        $agenda->update([
            'title' => $data['title'] ?? $data['sales_activity_category'],
            'task_detail' => $data['task_detail'],
            'duration_minutes' => (int) $data['duration_minutes'],
            'sales_activity_category' => $data['sales_activity_category'],
            'updated_by' => $actor->id,
        ]);

        return $this->recalculate($plan, $actor);
    }

    public function deleteAgenda(SalesDailyPlan $plan, ContentItem $agenda, User $actor): SalesDailyPlan
    {
        $this->assertAgendaBelongs($plan, $agenda);
        $this->assertPlanMutationAllowed($plan, $actor);
        $agenda->delete();

        return $this->recalculate($plan, $actor);
    }

    /** @param array<int, int|string> $agendaIds */
    public function reorder(SalesDailyPlan $plan, array $agendaIds, User $actor): SalesDailyPlan
    {
        $this->assertPlanMutationAllowed($plan, $actor);
        $ids = array_values(array_unique(array_map('intval', $agendaIds)));
        $agendas = $plan->agendas()->whereIn('id', $ids)->get()->keyBy('id');
        if ($agendas->count() !== count($ids) || $agendas->count() !== $plan->agendas()->count()) {
            throw new InvalidArgumentException('Daftar Agenda reorder tidak sesuai dengan Daily Plan.');
        }
        foreach ($ids as $index => $id) {
            $agendas->get($id)->update(['sort_order' => $index + 1, 'updated_by' => $actor->id]);
        }

        $plan->logActivity('agenda_reordered', ['agenda_ids' => $ids]);

        return $this->recalculate($plan, $actor);
    }

    public function submit(SalesDailyPlan $plan, User $actor): SalesDailyPlan
    {
        if (! app(SalesDailyPlanPolicy::class)->submit($actor, $plan)) {
            throw new InvalidArgumentException('Anda tidak dapat mengirim Daily Plan ini.');
        }
        $schedule = $this->schedule->calculate($plan);
        $this->schedule->assertSubmitAllowed($schedule);
        $plan->update([
            'status' => 'submitted',
            'submitted_at' => now(),
            'submitted_by' => $actor->id,
            'last_modified_by' => $actor->id,
            'lock_version' => ((int) $plan->lock_version) + 1,
        ]);
        $plan->logActivity('daily_plan_submitted', ['total_minutes' => $schedule['total_minutes']]);
        $plan->loadMissing('sales.currentSalesCoordinators');
        foreach ($plan->sales->currentSalesCoordinators as $coordinator) {
            if (! $coordinator->isAccountActive() || ! $coordinator->is_active || ! $coordinator->hasPrimaryRole('sales_coordinator')) {
                continue;
            }
            $this->notifications->create(
                $coordinator,
                'sales_daily_plan_submitted',
                'Daily Plan dikirim',
                $plan->sales->name.' mengirim Daily Plan untuk '.$plan->plan_date->format('d/m/Y').'.',
                route('sales-daily-plans.index', ['date' => $plan->plan_date->toDateString()]),
                $plan,
            );
        }

        return $plan->fresh(['agendas']);
    }

    public function recalculate(SalesDailyPlan $plan, User $actor): SalesDailyPlan
    {
        $wasSubmitted = $plan->status === 'submitted';
        $schedule = $this->schedule->calculate($plan);
        if ($plan->status === 'submitted') {
            $this->schedule->assertSubmitAllowed($schedule);
        } else {
            $this->schedule->assertDraftAllowed($schedule);
        }

        $byId = collect($schedule['items'])->keyBy('id');
        foreach ($plan->agendas()->get()->values() as $index => $agenda) {
            $item = $byId->get($agenda->id);
            $agenda->update([
                'start_time' => $item['start_time'],
                'end_time' => $item['end_time'],
                'sort_order' => $index + 1,
                'updated_by' => $actor->id,
            ]);
        }
        $plan->update([
            'last_modified_by' => $actor->id,
            'lock_version' => ((int) $plan->lock_version) + 1,
        ]);
        $plan->logActivity('agenda_schedule_recalculated', [
            'total_minutes' => $schedule['total_minutes'],
            'remaining_minutes' => $schedule['remaining_minutes'],
        ]);
        if ($wasSubmitted && $actor->hasPrimaryRole('sales_coordinator')) {
            $plan->loadMissing('sales');
            $this->notifications->create(
                $plan->sales,
                'sales_daily_plan_corrected',
                'Daily Plan diperbarui Koordinator',
                $actor->name.' memperbarui planning Daily Plan '.$plan->plan_date->format('d/m/Y').'.',
                route('sales-daily-plans.index', ['date' => $plan->plan_date->toDateString()]),
                $plan,
            );
        }

        return $plan->fresh(['agendas']);
    }

    private function assertPlanMutationAllowed(SalesDailyPlan $plan, User $actor): void
    {
        abort_unless(app(SalesDailyPlanPolicy::class)->update($actor, $plan), 403);
    }

    private function assertAgendaBelongs(SalesDailyPlan $plan, ContentItem $agenda): void
    {
        if ((int) $agenda->daily_plan_id !== (int) $plan->id
            || $agenda->item_type !== 'agenda'
            || $agenda->agenda_type !== ContentItem::SALES_AGENDA_TYPE) {
            throw (new ModelNotFoundException)->setModel(ContentItem::class, [$agenda->id]);
        }
    }
}
