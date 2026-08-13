<?php

namespace App\Http\Controllers\Crm;

use App\Exports\UserImportCredentialExport;
use App\Exports\UserImportResultExport;
use App\Exports\UserImportTemplateExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminUserImportConfirmRequest;
use App\Http\Requests\AdminUserImportPreviewRequest;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Models\UserImportBatch;
use App\Services\AccountAuditService;
use App\Services\OrganizationScopeService;
use App\Services\UserImportExecutionService;
use App\Services\UserImportParser;
use App\Services\UserImportValidationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AdminUserImportController extends Controller
{
    public function __construct(
        private readonly UserImportParser $parser,
        private readonly UserImportValidationService $validator,
        private readonly UserImportExecutionService $execution,
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

        $batch = $user_import_batch->load(['uploader', 'rows.createdUser']);
        $canConfirm = $batch->status === UserImportBatch::STATUS_PREVIEW_READY
            && $batch->error_rows === 0
            && $batch->confirmed_at === null
            && $batch->expires_at?->isFuture();
        $invitedRows = $batch->rows->filter(fn ($row) => ($row->normalized_data['status'] ?? null) === 'invited')->count();
        $canDirectActivation = request()->user()->hasPrimaryRole('superadmin');
        $credentialDownloadUrl = $this->canDownloadCredentials($batch, request()->user())
            ? URL::temporarySignedRoute('admin-users.import-credentials', $batch->credential_expires_at, $batch)
            : null;

        return view('crm.admin-users.import-batch-show', compact('batch', 'canConfirm', 'invitedRows', 'canDirectActivation', 'credentialDownloadUrl'));
    }

    public function confirm(AdminUserImportConfirmRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $batch = UserImportBatch::findOrFail($data['batch_id']);
        $this->authorize('view', $batch);
        $this->execution->execute(
            $batch->id,
            $request->user(),
            $request->boolean('send_invitations'),
            $data['expected_updated_at'],
            $data['activation_mode'],
        );

        return redirect()->route('admin-users.import-batches.show', $batch)->with('success', 'Import pengguna selesai diproses.');
    }

    public function result(UserImportBatch $user_import_batch): BinaryFileResponse
    {
        $this->authorize('view', $user_import_batch);
        abort_unless(in_array($user_import_batch->status, [UserImportBatch::STATUS_COMPLETED, UserImportBatch::STATUS_FAILED], true), 404);

        return UserImportResultExport::download($user_import_batch);
    }

    public function credentials(Request $request, UserImportBatch $user_import_batch): BinaryFileResponse
    {
        $actor = $request->user();
        abort_unless($actor->hasPrimaryRole('superadmin') && $user_import_batch->uploaded_by === $actor->id, 404);
        abort_if($user_import_batch->credential_expires_at?->isPast(), 410, 'Tautan kredensial telah kedaluwarsa.');

        [$path, $batchId] = DB::transaction(function () use ($actor, $user_import_batch) {
            $batch = UserImportBatch::query()->lockForUpdate()->findOrFail($user_import_batch->id);
            abort_unless($this->canDownloadCredentials($batch, $actor), 404);
            $credentials = $batch->credential_payload;
            $path = UserImportCredentialExport::create($credentials);
            $batch->update([
                'credential_downloaded_at' => now(),
                'credential_payload' => null,
            ]);
            $this->audit->logUserImportBatch('user_import_credentials_downloaded', $batch, $actor, [
                'credential_rows' => count($credentials),
            ]);

            return [$path, $batch->id];
        });

        return UserImportCredentialExport::download($path, $batchId);
    }

    private function canDownloadCredentials(UserImportBatch $batch, User $actor): bool
    {
        return $actor->hasPrimaryRole('superadmin')
            && $batch->uploaded_by === $actor->id
            && $batch->status === UserImportBatch::STATUS_COMPLETED
            && $batch->activation_mode === 'direct'
            && $batch->credential_payload !== null
            && $batch->credential_downloaded_at === null
            && $batch->credential_expires_at?->isFuture();
    }

    private function branchIds(User $actor): array
    {
        if ($actor->isSuperadmin() || $actor->hasPrimaryRole('pusat')) {
            return Branch::query()->where('is_active', true)->pluck('id')->all();
        }

        return app(OrganizationScopeService::class)->branchIds($actor);
    }
}
