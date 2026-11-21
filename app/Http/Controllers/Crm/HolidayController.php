<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreHolidayRequest;
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
        Holiday::query()->create($request->validated());

        return to_route('admin.holidays.index')->with('success', 'Hari libur berhasil ditambahkan.');
    }

    public function destroy(Holiday $holiday): RedirectResponse
    {
        abort_unless($this->canManage(), 403);
        $holiday->delete();

        return to_route('admin.holidays.index')->with('success', 'Hari libur berhasil dihapus.');
    }

    private function canManage(): bool
    {
        return request()->user()?->isSuperadmin() === true
            && request()->user()?->hasPermission('system.settings_manage') === true;
    }
}
