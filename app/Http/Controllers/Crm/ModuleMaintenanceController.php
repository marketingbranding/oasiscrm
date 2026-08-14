<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\ModuleMaintenanceRequest;
use App\Services\ModuleMaintenanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ModuleMaintenanceController extends Controller
{
    public function enable(string $module, ModuleMaintenanceRequest $request, ModuleMaintenanceService $maintenance): RedirectResponse
    {
        $maintenance->enable($module, $request->user(), $request->validated());

        return back()->with('success', 'Pemeliharaan modul berhasil diaktifkan.');
    }

    public function update(string $module, ModuleMaintenanceRequest $request, ModuleMaintenanceService $maintenance): RedirectResponse
    {
        $maintenance->update($module, $request->user(), $request->validated());

        return back()->with('success', 'Pemeliharaan modul berhasil diperbarui.');
    }

    public function disable(string $module, Request $request, ModuleMaintenanceService $maintenance): RedirectResponse
    {
        $maintenance->disable($module, $request->user());

        return back()->with('success', 'Pemeliharaan modul berhasil dinonaktifkan.');
    }
}
