<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\CompleteSalesAgendaRequest;
use App\Http\Requests\Crm\RescheduleSalesAgendaRequest;
use App\Http\Requests\Crm\StoreSalesAgendaRequest;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\SalesLead;
use App\Services\OptimisticLockService;
use App\Services\PromoOptionService;
use App\Services\SalesAgendaProjectResolver;
use App\Services\SalesDailyReminderService;
use App\Services\WorkspaceAccessService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SalesAgendaController extends Controller
{
    public function __construct(
        private readonly SalesAgendaProjectResolver $projectResolver,
        private readonly OptimisticLockService $optimisticLock,
        private readonly WorkspaceAccessService $workspaceAccess,
        private readonly PromoOptionService $promoOptions,
        private readonly SalesDailyReminderService $dailyReminder,
    ) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->isSales(), 403);
        $project = $this->projectResolver->resolve($request->user());
        $tab = $request->query('tab') === 'leads' ? 'leads' : 'agenda';
        $agendas = ContentItem::query()
            ->with(['branch', 'salesProject', 'evidence'])
            ->where('item_type', 'agenda')
            ->where('agenda_type', ContentItem::SALES_AGENDA_TYPE)
            ->where('owner_user_id', $request->user()->id)
            ->latest('scheduled_date')
            ->latest('id')
            ->paginate(20, ['*'], 'agenda_page');
        $leads = SalesLead::query()
            ->visibleTo($request->user())
            ->where('sales_user_id', $request->user()->id)
            ->with(['branch:id,name', 'project:id,project_name'])
            ->latest('lead_date')
            ->latest('id')
            ->paginate(20, ['*'], 'lead_page');
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
            'agendaInputUrl' => route('sales-agendas.index', ['tab' => 'agenda']).'#agenda-baru',
            'missingResultUrl' => route('sales-agendas.index', ['tab' => 'agenda']).'#agenda-saya',
        ];

        return view('crm.sales-pocketbook.sales-agenda', compact('project', 'agendas', 'leads', 'tab', 'projects', 'defaultProjectId', 'cascadeProjects', 'leadOptionsEndpoint', 'promos', 'dailyReminder'));
    }

    public function store(StoreSalesAgendaRequest $request)
    {
        $data = $request->validated();
        $project = $this->projectResolver->resolve($request->user(), $data['scheduled_date']);
        abort_unless($project, 422, 'Proyek utama belum ditentukan. Hubungi admin untuk menetapkan proyek utama.');
        $result = filled($data['activity_result'] ?? null) ? $data['activity_result'] : null;

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
            'status' => $result ? 'done' : 'planned',
            'completed_at' => $result ? now() : null,
            'activity_result' => $result,
            'owner_user_id' => $request->user()->id,
            'created_by' => $request->user()->id,
        ]);
        $agenda->assignees()->sync([$request->user()->id]);

        return redirect()->route('sales-agendas.index')->with('success', 'Agenda sales berhasil ditambahkan.');
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
            : redirect()->route('sales-agendas.index')->with('success', 'Hasil agenda berhasil disimpan.');
    }

    public function reschedule(RescheduleSalesAgendaRequest $request, ContentItem $agenda)
    {
        $this->authorizeAgenda($request, $agenda);
        abort(403);
    }

    private function authorizeAgenda(Request $request, ContentItem $agenda): void
    {
        abort_unless($agenda->item_type === 'agenda' && $agenda->agenda_type === ContentItem::SALES_AGENDA_TYPE, 404);
        abort_unless($request->user()->isSales() && (int) $agenda->owner_user_id === (int) $request->user()->id, 403);
    }
}
