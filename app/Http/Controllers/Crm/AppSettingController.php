<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\UpdateAppSettingsRequest;
use App\Services\AppSettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AppSettingController extends Controller
{
    public function index(AppSettingService $settings): View
    {
        $settings->assertCanManage(request()->user());

        return view('crm.app-settings.index', [
            'definitions' => $settings->definitions(),
        ]);
    }

    public function update(UpdateAppSettingsRequest $request, AppSettingService $settings): RedirectResponse
    {
        $settings->update($request->user(), $request->validated()['settings'] ?? []);

        return to_route('admin.app-settings.index')->with('success', 'Pengaturan aturan berhasil disimpan.');
    }
}
