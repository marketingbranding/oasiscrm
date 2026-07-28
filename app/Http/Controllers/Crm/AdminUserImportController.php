<?php

namespace App\Http\Controllers\Crm;

use App\Exports\UserImportTemplateExport;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Models\UserImportBatch;
use App\Services\OrganizationScopeService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AdminUserImportController extends Controller
{
    public function create(Request $request): View
    {
        $this->authorize('viewAny', UserImportBatch::class);

        return view('crm.admin-users.import');
    }

    public function template(Request $request): BinaryFileResponse
    {
        $this->authorize('viewAny', UserImportBatch::class);
        $branchIds = $this->branchIds($request->user());
        $roles = Role::query()->where('is_active', true)->whereIn('slug', UserImportTemplateExport::ROLE_SLUGS)->get();
        $branches = Branch::query()->where('is_active', true)->whereIn('id', $branchIds)->forDropdown()->get();
        $projects = LeadMaster::query()->with('branch')->where('is_active', true)->whereIn('branch_id', $branchIds)
            ->orderBy('branch_id')->orderBy('project_name')->get();

        return UserImportTemplateExport::download($roles, $branches, $projects);
    }

    public function history(Request $request): View
    {
        $this->authorize('viewAny', UserImportBatch::class);
        $batches = UserImportBatch::query()->with('uploader')
            ->when(! $request->user()->isSuperadmin(), fn ($query) => $query->where('uploaded_by', $request->user()->id))
            ->latest()->paginate(20);

        return view('crm.admin-users.import-history', compact('batches'));
    }

    public function show(UserImportBatch $user_import_batch): View
    {
        $this->authorize('view', $user_import_batch);

        return view('crm.admin-users.import-batch-show', ['batch' => $user_import_batch->load('uploader')]);
    }

    private function branchIds(User $actor): array
    {
        if ($actor->isSuperadmin() || $actor->hasPrimaryRole('pusat')) {
            return Branch::query()->where('is_active', true)->pluck('id')->all();
        }

        return app(OrganizationScopeService::class)->branchIds($actor);
    }
}
