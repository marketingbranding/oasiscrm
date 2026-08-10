<?php

namespace App\Http\Controllers\Crm;

use App\Exports\SalesAgendaExport;
use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use Illuminate\Http\Request;

class SalesAgendaExportController extends Controller
{
    public function __invoke(Request $request)
    {
        abort_unless($request->user()->isSales(), 403);
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $agendas = ContentItem::query()
            ->with(['owner', 'branch', 'salesProject'])
            ->where('item_type', 'agenda')
            ->where('agenda_type', ContentItem::SALES_AGENDA_TYPE)
            ->where('owner_user_id', $request->user()->id)
            ->when($data['date_from'] ?? null, fn ($query, $date) => $query->whereDate('scheduled_date', '>=', $date))
            ->when($data['date_to'] ?? null, fn ($query, $date) => $query->whereDate('scheduled_date', '<=', $date))
            ->orderBy('scheduled_date')
            ->orderBy('id')
            ->get();

        return SalesAgendaExport::toBrowser($agendas, 'agenda-saya.xlsx');
    }
}
