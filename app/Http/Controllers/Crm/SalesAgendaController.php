<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\CompleteSalesAgendaRequest;
use App\Http\Requests\Crm\RescheduleSalesAgendaRequest;
use App\Http\Requests\Crm\StoreSalesAgendaRequest;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\User;
use App\Services\OptimisticLockService;
use App\Services\WorkspaceAccessService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SalesAgendaController extends Controller
{
    public function __construct(
        private readonly WorkspaceAccessService $workspaceAccess,
        private readonly OptimisticLockService $optimisticLock,
    ) {}

    public function store(StoreSalesAgendaRequest $request)
    {
        $data = $request->validated();
        [$owner, $project] = $this->resolveOwnerAndProject($request, (int) $data['owner_user_id'], (int) $data['project_id']);
        $duration = $this->duration($data['start_time'], $data['end_time']);

        $agenda = ContentItem::create([
            'branch_id' => $project->branch_id,
            'project_name' => $project->project_name,
            'sales_project_id' => $project->id,
            'item_type' => 'agenda',
            'visibility' => 'personal',
            'title' => $data['title'],
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'sales_activity_category' => $data['sales_activity_category'],
            'location' => $data['location'] ?? null,
            'start_date' => $data['scheduled_date'],
            'scheduled_date' => $data['scheduled_date'],
            'deadline_date' => $data['scheduled_date'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'duration_minutes' => $duration,
            'status' => 'planned',
            'priority' => 'medium',
            'notes' => $data['notes'] ?? null,
            'owner_user_id' => $owner->id,
            'created_by' => $request->user()->id,
        ]);
        $agenda->assignees()->sync([$owner->id]);

        return redirect()->route('sales-pocketbook.index', ['tab' => 'agenda'])
            ->with('success', 'Agenda sales berhasil ditambahkan.');
    }

    public function update(CompleteSalesAgendaRequest $request, ContentItem $agenda)
    {
        $this->authorizeAgenda($request, $agenda);
        $data = $request->validated();
        $result = $this->optimisticLock->execute($request, $agenda, $data['expected_updated_at'], function (ContentItem $current) use ($request, $data) {
            $this->authorizeAgenda($request, $current);
            abort_if(in_array($current->status, ['cancelled', 'rescheduled'], true), 422, 'Agenda ini tidak dapat diselesaikan.');
            $current->update([
                'activity_result' => $data['activity_result'],
                'status' => 'done',
                'completed_at' => $current->completed_at ?? now(),
                'updated_by' => $request->user()->id,
            ]);
            $current->logActivity('agenda_result_recorded', ['status' => 'done']);

            return $current;
        });

        if ($result instanceof Response) {
            return $result;
        }

        return redirect()->route('sales-pocketbook.index', ['tab' => 'agenda'])
            ->with('success', 'Hasil agenda berhasil disimpan.');
    }

    public function reschedule(RescheduleSalesAgendaRequest $request, ContentItem $agenda)
    {
        $this->authorizeAgenda($request, $agenda);
        $data = $request->validated();
        $result = $this->optimisticLock->execute($request, $agenda, $data['expected_updated_at'], function (ContentItem $current) use ($request, $data) {
            $this->authorizeAgenda($request, $current);
            abort_if($current->isFinished(), 422, 'Agenda yang sudah selesai tidak dapat dijadwalkan ulang.');
            $duration = $this->duration($data['start_time'], $data['end_time']);
            $current->update([
                'status' => 'rescheduled',
                'completed_at' => now(),
                'updated_by' => $request->user()->id,
            ]);

            $replacement = $current->replicate([
                'status', 'completed_at', 'activity_result', 'updated_by', 'created_at', 'updated_at',
            ]);
            $replacement->fill([
                'start_date' => $data['scheduled_date'],
                'scheduled_date' => $data['scheduled_date'],
                'deadline_date' => $data['scheduled_date'],
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'duration_minutes' => $duration,
                'status' => 'planned',
                'completed_at' => null,
                'activity_result' => null,
                'rescheduled_from_id' => $current->id,
                'created_by' => $request->user()->id,
                'updated_by' => null,
            ])->save();
            $replacement->assignees()->sync([$current->owner_user_id]);
            $current->logActivity('agenda_rescheduled', ['replacement_id' => $replacement->id]);

            return $replacement;
        });

        if ($result instanceof Response) {
            return $result;
        }

        return redirect()->route('sales-pocketbook.index', ['tab' => 'agenda'])
            ->with('success', 'Agenda berhasil dijadwalkan ulang.');
    }

    private function resolveOwnerAndProject(Request $request, int $ownerId, int $projectId): array
    {
        $actor = $request->user();
        $owner = User::query()->whereKey($ownerId)->where('is_active', true)
            ->whereHas('role', fn ($query) => $query->where('slug', 'sales'))->first();
        abort_unless($owner, 403);
        if ($actor->isSales()) {
            abort_unless($actor->is($owner), 403);
        }

        $project = LeadMaster::query()->whereKey($projectId)->where('is_active', true)
            ->whereHas('assignedUsers', fn ($query) => $query->whereKey($owner->id))->first();
        abort_unless($project, 403);
        abort_unless($this->workspaceAccess->canViewBranch($owner, $project->branch_id), 403);
        if (! $actor->isSales() && ! $actor->canViewAllBranches()) {
            abort_unless($this->workspaceAccess->canEditBranch($actor, $project->branch_id), 403);
        }

        return [$owner, $project];
    }

    private function authorizeAgenda(Request $request, ContentItem $agenda): void
    {
        abort_unless($agenda->item_type === 'agenda' && $agenda->agenda_type === ContentItem::SALES_AGENDA_TYPE, 404);
        $user = $request->user();
        abort_unless($user->isSuperadmin() || $user->hasPrimaryRole(['sales', 'manager', 'admin', 'pusat']), 403);
        if ($user->isSales()) {
            abort_unless((int) $agenda->owner_user_id === (int) $user->id, 403);
        } elseif (! $user->canViewAllBranches()) {
            abort_unless($this->workspaceAccess->canEditBranch($user, $agenda->branch_id), 403);
        }
    }

    private function duration(string $start, string $end): int
    {
        return Carbon::parse($start)->diffInMinutes(Carbon::parse($end), false);
    }
}
