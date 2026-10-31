<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\CompleteSalesAgendaRequest;
use App\Http\Requests\Crm\RescheduleSalesAgendaRequest;
use App\Http\Requests\Crm\StoreSalesAgendaRequest;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\CoordinatorLeadTeamService;
use App\Services\CoordinatorSalesMonitoringService;
use App\Services\OptimisticLockService;
use App\Services\PromoOptionService;
use App\Services\SalesAgendaEvidenceUploadService;
use App\Services\SalesAgendaProjectResolver;
use App\Services\SalesDailyReminderService;
use App\Services\SalesPocketbookOnboardingService;
use App\Services\WorkspaceAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class SalesAgendaController extends Controller
{
    public function __construct(
        private readonly SalesAgendaProjectResolver $projectResolver,
        private readonly CoordinatorLeadTeamService $coordinatorTeams,
        private readonly CoordinatorSalesMonitoringService $coordinatorMonitoring,
        private readonly OptimisticLockService $optimisticLock,
        private readonly WorkspaceAccessService $workspaceAccess,
        private readonly PromoOptionService $promoOptions,
        private readonly SalesDailyReminderService $dailyReminder,
        private readonly SalesPocketbookOnboardingService $onboarding,
    ) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->isSales(), 403);
        if ($this->onboarding->shouldShow($request->user())) {
            return redirect()->route('sales-pocketbook.onboarding.show');
        }
        $project = $this->projectResolver->resolve($request->user());
        $tab = $request->query('tab') === 'leads' ? 'leads' : 'agenda';
        $request->validate([
            'work_date' => ['nullable', 'date_format:Y-m-d'],
            'lead_status' => ['nullable', Rule::in(['open', 'completed'])],
        ]);
        $agendaDate = CarbonImmutable::createFromFormat('Y-m-d', $request->query('work_date', today()->toDateString()), config('app.timezone'));
        $agendas = ContentItem::query()
            ->with(['branch', 'salesProject', 'evidence'])
            ->where('item_type', 'agenda')
            ->where('agenda_type', ContentItem::SALES_AGENDA_TYPE)
            ->where('owner_user_id', $request->user()->id)
            ->whereDate('scheduled_date', $agendaDate->toDateString())
            ->orderBy('start_time')
            ->latest('id')
            ->paginate(20, ['*'], 'agenda_page');
        $contextToday = CarbonImmutable::today(config('app.timezone'));
        $agendaGroups = collect([
            'yesterday' => ['label' => 'Kemarin', 'date' => $contextToday->subDay()],
            'today' => ['label' => 'Hari ini', 'date' => $contextToday],
            'planned' => ['label' => 'Rencana', 'date' => null],
        ])->mapWithKeys(function (array $context, string $key) use ($request, $contextToday): array {
            $query = ContentItem::query()
                ->with(['branch', 'salesProject', 'evidence'])
                ->where('item_type', 'agenda')
                ->where('agenda_type', ContentItem::SALES_AGENDA_TYPE)
                ->where('owner_user_id', $request->user()->id);

            if ($context['date']) {
                $query->whereDate('scheduled_date', $context['date']->toDateString());
            } else {
                $query->whereDate('scheduled_date', '>', $contextToday->toDateString());
            }

            return [$key => [
                'label' => $context['label'],
                'items' => $query->orderBy('scheduled_date')->orderBy('start_time')->latest('id')->limit(20)->get(),
            ]];
        });
        $leadStatus = $request->query('lead_status', 'open');
        $leadQuery = SalesLead::query()
            ->visibleTo($request->user())
            ->where('sales_user_id', $request->user()->id)
            ->with(['branch:id,name', 'project:id,project_name']);
        $openLeadCount = (clone $leadQuery)->whereNull('completed_at')->count();
        $completedLeadCount = (clone $leadQuery)->whereNotNull('completed_at')->count();
        $leads = $leadQuery
            ->when($leadStatus === 'completed', fn ($query) => $query->whereNotNull('completed_at'), fn ($query) => $query->whereNull('completed_at'))
            ->latest('lead_date')
            ->latest('id')
            ->paginate(20, ['*'], 'lead_page')
            ->withQueryString();
        $projects = $this->workspaceAccess->accessibleProjects($request->user())->where('is_active', true)->values();
        $defaultProjectId = old('project_id', $request->query('project_id', $project?->id));
        $cascadeProjects = $projects->map(fn (LeadMaster $item) => [
            'id' => (string) $item->id,
            'branch_id' => (string) $item->branch_id,
        ])->values();
        $leadOptionsEndpoint = route('sales-leads.options', ['branch' => 'BRANCH_ID']);
        $promos = $defaultProjectId
            ? $this->promoOptions->availableForBranchAndDate((int) $projects->firstWhere('id', (int) $defaultProjectId)?->branch_id, old('lead_date', $request->query('lead_date', today())))
            : collect([PromoOptionService::NO_PROMO]);
        $dailyReminder = $this->dailyReminder->state($request->user()) + [
            'leadInputUrl' => route('sales-agendas.index', ['tab' => 'leads']).'#lead-saya',
            'dismissUrl' => route('sales-reminders.dismiss'),
            'agendaInputUrl' => route('sales-agendas.index', ['tab' => 'agenda']).'#agenda-input',
            'missingResultUrl' => route('sales-agendas.index', ['tab' => 'agenda']).'#agenda-list',
        ];
        $canExport = $request->user()->hasPermission('sales_pocketbook.export_own');

        return view('crm.sales-pocketbook.sales-agenda', compact('project', 'agendas', 'agendaGroups', 'leads', 'leadStatus', 'openLeadCount', 'completedLeadCount', 'tab', 'projects', 'defaultProjectId', 'cascadeProjects', 'leadOptionsEndpoint', 'promos', 'dailyReminder', 'canExport', 'agendaDate'));
    }

    public function show(Request $request, ContentItem $agenda)
    {
        $this->authorize('view', $agenda);
        $agenda->load(['branch', 'salesProject', 'evidence', 'tasks.creator']);

        return view('crm.sales-pocketbook.sales-agenda-detail', compact('agenda'));
    }

    public function store(StoreSalesAgendaRequest $request, SalesAgendaEvidenceUploadService $uploads)
    {
        $data = $request->validated();
        $owner = $this->agendaOwner($request, $data);
        $project = $this->agendaProject($request, $owner, $data);
        abort_unless($project, 422, 'Proyek utama belum ditentukan. Hubungi admin untuk menetapkan proyek utama.');
        $prepared = $uploads->prepare($request->file('photos', []), 'photos');
        $result = filled($data['activity_result'] ?? null) ? $data['activity_result'] : null;

        try {
            DB::transaction(function () use ($request, $owner, $project, $data, $result, $prepared, $uploads) {
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
                    'scheduled_date' => $data['scheduled_date'],
                    'start_time' => $data['start_time'] ?? null,
                    'end_time' => $data['end_time'] ?? null,
                    'duration_minutes' => $this->durationMinutes($data['start_time'] ?? null, $data['end_time'] ?? null),
                    'status' => $result ? 'done' : 'planned',
                    'completed_at' => $result ? now() : null,
                    'activity_result' => $result,
                    'owner_user_id' => $owner->id,
                    'created_by' => $request->user()->id,
                ]);
                $agenda->assignees()->sync([$owner->id]);
                if ($result) {
                    $agenda->logActivity('agenda_result_recorded', ['status' => 'done']);
                }
                $uploads->persist($agenda, $prepared, $request->user());
            });
        } catch (\Throwable $exception) {
            $uploads->cleanup($prepared);
            throw $exception;
        }

        return $this->successRedirect($request, 'Agenda sales berhasil ditambahkan.');
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

        return $result instanceof Response
            ? $result
            : $this->successRedirect($request, 'Hasil agenda berhasil disimpan.');
    }

    public function reschedule(RescheduleSalesAgendaRequest $request, ContentItem $agenda)
    {
        $this->authorizeAgenda($request, $agenda);
        abort(403);
    }

    private function authorizeAgenda(Request $request, ContentItem $agenda): void
    {
        abort_unless($agenda->item_type === 'agenda' && $agenda->agenda_type === ContentItem::SALES_AGENDA_TYPE, 404);

        if ($request->user()->isSales()) {
            $this->authorize('updateSalesAgenda', $agenda);

            return;
        }

        abort_unless($request->user()->hasPrimaryRole('sales_coordinator')
            && $this->coordinatorMonitoring->canManageAgenda($request->user(), $agenda), 403);
    }

    private function agendaOwner(Request $request, array $data): User
    {
        if ($request->user()->isSales()) {
            return $request->user();
        }

        $owner = User::query()->findOrFail((int) $data['sales_user_id']);
        abort_unless($request->user()->hasPrimaryRole('sales_coordinator')
            && $this->coordinatorTeams->contains($request->user(), $owner), 403);

        return $owner;
    }

    private function agendaProject(Request $request, User $owner, array $data): ?LeadMaster
    {
        if ($request->user()->hasPrimaryRole('sales_coordinator')) {
            $project = $this->projectResolver->resolveAssigned($owner, (int) $data['project_id'], $data['scheduled_date']);
            abort_unless($project && $this->workspaceAccess->canAccessProject($request->user(), $project), 403);

            return $project;
        }

        return $this->projectResolver->resolve($owner, $data['scheduled_date']);
    }

    private function successRedirect(Request $request, string $message): RedirectResponse
    {
        if ($request->user()->hasPrimaryRole('sales_coordinator')) {
            return redirect()->route('sales-pocketbook.index', ['tab' => 'agenda'])->with('success', $message);
        }

        return redirect()->route('sales-agendas.index')->with('success', $message);
    }

    private function durationMinutes(?string $startTime, ?string $endTime): ?int
    {
        if (blank($startTime) || blank($endTime)) {
            return null;
        }

        $start = CarbonImmutable::createFromFormat('H:i', $startTime);
        $end = CarbonImmutable::createFromFormat('H:i', $endTime);

        return $start->diffInMinutes($end);
    }
}
