<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\DisableOperationalMaintenanceRequest;
use App\Http\Requests\Crm\EnableOperationalMaintenanceRequest;
use App\Services\ModuleMaintenanceService;
use App\Services\OperationalMaintenanceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class OperationalMaintenanceController extends Controller
{
    public function index(OperationalMaintenanceService $maintenance, ModuleMaintenanceService $modules): View
    {
        return view('crm.operational-maintenance.index', [
            'setting' => $maintenance->currentConfiguration(),
            'moduleStatuses' => $modules->statuses(),
        ]);
    }

    public function enable(
        EnableOperationalMaintenanceRequest $request,
        OperationalMaintenanceService $maintenance,
    ): RedirectResponse {
        try {
            $maintenance->enable(
                $request->user(),
                $request->safe()->only(['title', 'message', 'estimated_end_at']),
                $request->integer('lock_version'),
            );
        } catch (AuthorizationException|ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Operational maintenance could not be enabled.', [
                'operation' => 'operational_maintenance_enable',
                'exception' => $exception::class,
            ]);

            return back()->withInput()->with('error', 'Pemeliharaan tidak dapat diaktifkan. Coba lagi atau periksa kondisi sistem.');
        }

        return to_route('admin.maintenance.index')->with('success', 'Pemeliharaan OASIS berhasil diaktifkan.');
    }

    public function disable(
        DisableOperationalMaintenanceRequest $request,
        OperationalMaintenanceService $maintenance,
    ): RedirectResponse {
        try {
            $maintenance->disable($request->user(), $request->integer('lock_version'));
        } catch (AuthorizationException|ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Operational maintenance could not be disabled.', [
                'operation' => 'operational_maintenance_disable',
                'exception' => $exception::class,
            ]);

            return back()->withInput()->with('error', 'Pemeliharaan tidak dapat dinonaktifkan. Coba lagi atau periksa kondisi sistem.');
        }

        return to_route('admin.maintenance.index')->with('success', 'Pemeliharaan OASIS berhasil dinonaktifkan.');
    }
}
