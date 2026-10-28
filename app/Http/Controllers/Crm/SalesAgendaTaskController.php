<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreSalesAgendaTaskRequest;
use App\Http\Requests\Crm\UpdateSalesAgendaTaskRequest;
use App\Models\ContentItem;
use App\Models\SalesAgendaTask;
use App\Services\OptimisticLockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SalesAgendaTaskController extends Controller
{
    public function __construct(private readonly OptimisticLockService $locks) {}

    public function store(StoreSalesAgendaTaskRequest $request, ContentItem $agenda): RedirectResponse
    {
        $this->authorizeAgenda($agenda);
        $agenda->tasks()->create([
            'title' => $request->validated('title'),
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Tugas agenda berhasil ditambahkan.');
    }

    public function update(UpdateSalesAgendaTaskRequest $request, ContentItem $agenda, SalesAgendaTask $task): RedirectResponse|Response
    {
        $this->authorizeTask($agenda, $task);
        $data = $request->validated();
        $result = $this->locks->execute($request, $task, $data['expected_updated_at'], function (SalesAgendaTask $current) use ($data): SalesAgendaTask {
            $current->update([
                'is_completed' => (bool) $data['is_completed'],
                'completed_at' => $data['is_completed'] ? ($current->completed_at ?? now()) : null,
            ]);

            return $current;
        });

        return $result instanceof Response ? $result : back()->with('success', 'Tugas agenda diperbarui.');
    }

    public function destroy(Request $request, ContentItem $agenda, SalesAgendaTask $task): RedirectResponse
    {
        $this->authorizeTask($agenda, $task);
        abort_if($agenda->isFinished(), 422, 'Agenda yang sudah selesai tidak dapat mengubah tugas.');
        $task->delete();

        return back()->with('success', 'Tugas agenda dihapus.');
    }

    private function authorizeAgenda(ContentItem $agenda): void
    {
        abort_unless($agenda->item_type === 'agenda' && $agenda->agenda_type === ContentItem::SALES_AGENDA_TYPE, 404);
        $this->authorize('updateSalesAgenda', $agenda);
    }

    private function authorizeTask(ContentItem $agenda, SalesAgendaTask $task): void
    {
        $this->authorizeAgenda($agenda);
        abort_unless((int) $task->content_item_id === (int) $agenda->id, 404);
    }
}
