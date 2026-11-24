<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Services\TeamHistoryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class TeamHistoryController extends Controller
{
    public function __construct(private readonly TeamHistoryService $history) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()->hasPermission('sales_pocketbook.view_team') || $request->user()->hasPermission('sales_pocketbook.view_branch'), 403);

        $filters = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'type' => ['nullable', 'in:lead_stage,lead_status,lead_transfer,lead_note,agenda,agenda_report'],
        ]);
        $from = CarbonImmutable::parse($filters['date_from'] ?? now()->subDays(30)->toDateString())->startOfDay();
        $to = CarbonImmutable::parse($filters['date_to'] ?? now()->toDateString())->endOfDay();
        if ($from->diffInDays($to) > 92) {
            throw ValidationException::withMessages(['date_to' => 'Rentang tanggal maksimal 92 hari.']);
        }

        return view('crm.team-history.index', $this->history->index($request->user(), $filters));
    }
}
