<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreHolidayRequest;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Holiday;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class HolidayController extends Controller
{
    public function index(): View
    {
        abort_unless($this->canManage(), 403);

        return view('crm.holidays.index', [
            'holidays' => Holiday::query()->with('branch')->orderBy('date')->get(),
            'branches' => Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreHolidayRequest $request): RedirectResponse
    {
        $holiday = Holiday::query()->create($request->validated());
        $holiday->load('branch:id,name');

        ActivityLog::query()->create([
            'causer_id' => $request->user()->id,
            'subject_type' => Holiday::class,
            'subject_id' => $holiday->id,
            'event' => 'holiday_created',
            'description' => 'Hari libur ditambahkan.',
            'properties' => [
                'actor_id' => $request->user()->id,
                'date' => $holiday->date->toDateString(),
                'name' => $holiday->name,
                'branch_id' => $holiday->branch_id,
                'branch_name' => $holiday->branch?->name,
            ],
        ]);

        return to_route('admin.holidays.index')->with('success', 'Hari libur berhasil ditambahkan.');
    }

    public function destroy(Holiday $holiday): RedirectResponse
    {
        abort_unless($this->canManage(), 403);
        $request = request();
        $holiday->load('branch:id,name');

        ActivityLog::query()->create([
            'causer_id' => $request->user()->id,
            'subject_type' => Holiday::class,
            'subject_id' => $holiday->id,
            'event' => 'holiday_deleted',
            'description' => 'Hari libur dihapus.',
            'properties' => [
                'actor_id' => $request->user()->id,
                'date' => $holiday->date->toDateString(),
                'name' => $holiday->name,
                'branch_id' => $holiday->branch_id,
                'branch_name' => $holiday->branch?->name,
            ],
        ]);
        $holiday->delete();

        return to_route('admin.holidays.index')->with('success', 'Hari libur berhasil dihapus.');
    }

    private function canManage(): bool
    {
        return request()->user()?->isSuperadmin() === true
            && request()->user()?->hasPermission('system.settings_manage') === true;
    }
}
