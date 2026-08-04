<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\SalesSheetIdentity;
use App\Models\User;
use App\Services\SalesLeadSheetOptionService;
use App\Services\SalesSheetIdentityService;
use App\Services\UserAdministrationService;
use App\Services\WorkspaceAccessService;
use Illuminate\Http\Request;

class SalesSheetIdentityController extends Controller
{
    public function edit(Request $request, User $admin_user, Branch $branch, SalesLeadSheetOptionService $options, UserAdministrationService $administration, WorkspaceAccessService $access)
    {
        $administration->assertCanManage($request->user(), $admin_user, 'users.update');
        abort_unless($access->canViewBranch($request->user(), $branch) && $admin_user->branches()->whereKey($branch->id)->exists(), 403);

        return view('crm.admin-users.sales-sheet-identity', [
            'user' => $admin_user,
            'branch' => $branch,
            'salesOptions' => $options->forBranch($branch)['sales'],
            'identity' => SalesSheetIdentity::query()->whereBelongsTo($branch)->whereBelongsTo($admin_user)->first(),
        ]);
    }

    public function update(Request $request, User $admin_user, Branch $branch, SalesSheetIdentityService $identities, UserAdministrationService $administration, WorkspaceAccessService $access)
    {
        $administration->assertCanManage($request->user(), $admin_user, 'users.update');
        abort_unless($access->canViewBranch($request->user(), $branch) && $admin_user->branches()->whereKey($branch->id)->exists(), 403);
        $data = $request->validate(['spreadsheet_value' => ['required', 'string', 'max:255']]);
        $identities->save($branch, $admin_user, $data['spreadsheet_value'], $request->user());

        return redirect()->route('admin-users.edit', $admin_user)->with('success', 'Identitas Sales PIC spreadsheet berhasil disimpan.');
    }
}
