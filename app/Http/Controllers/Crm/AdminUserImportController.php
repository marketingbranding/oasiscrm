<?php

namespace App\Http\Controllers\Crm;

use App\Exports\UserImportTemplateExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminUserImportPreviewRequest;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Models\UserImportBatch;
use App\Services\AccountAuditService;
use App\Services\OrganizationScopeService;
use App\Services\UserImportParser;
use App\Services\UserImportValidationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AdminUserImportController extends Controller
{
    public function __construct(
        private readonly UserImportParser $parser,
        private readonly UserImportValidationService $validator,
        private readonly AccountAuditService $audit,
    ) {}

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

    public function preview(AdminUserImportPreviewRequest $request): RedirectResponse
    {
        $this->authorize('viewAny', UserImportBatch::class);
        $file = $request->file('file');
        $rows = $this->validator->validate($this->parser->parse($file), $request->user());
        $counts = [
            'total_rows' => count($rows),
            'valid_rows' => collect($rows)->where('validation_status', 'valid')->count(),
            'warning_rows' => collect($rows)->where('validation_status', 'warning')->count(),
            'error_rows' => collect($rows)->where('validation_status', 'error')->count(),
        ];

        $batch = DB::transaction(function () use ($request, $file, $rows, $counts) {
            $filename = basename(str_replace('\\', '/', $file->getClientOriginalName()));
            $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename) ?: 'import-user.xlsx';
            $batch = UserImportBatch::create([
                'original_filename' => mb_substr($filename, 0, 255),
                'uploaded_by' => $request->user()->id,
                'status' => $counts['error_rows'] > 0
                    ? UserImportBatch::STATUS_VALIDATION_FAILED
                    : UserImportBatch::STATUS_PREVIEW_READY,
                ...$counts,
                'send_invitations' => $request->boolean('send_invitations'),
                'expires_at' => now()->addDay(),
            ]);

            foreach ($rows as $row) {
                $batch->rows()->create([
                    'row_number' => $row['row_number'],
                    'raw_data' => $row['raw_data'],
                    'normalized_data' => $row['normalized_data'],
                    'validation_status' => $row['validation_status'],
                    'errors' => $row['errors'],
                    'warnings' => $row['warnings'],
                ]);
            }

            $this->audit->logUserImportBatch('user_import_uploaded', $batch, $request->user(), $counts);
            $this->audit->logUserImportBatch('user_import_preview_generated', $batch, $request->user(), $counts);

            return $batch;
        });

        return redirect()->route('admin-users.import-batches.show', $batch);
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

        return view('crm.admin-users.import-batch-show', ['batch' => $user_import_batch->load(['uploader', 'rows'])]);
    }

    private function branchIds(User $actor): array
    {
        if ($actor->isSuperadmin() || $actor->hasPrimaryRole('pusat')) {
            return Branch::query()->where('is_active', true)->pluck('id')->all();
        }

        return app(OrganizationScopeService::class)->branchIds($actor);
    }
}
