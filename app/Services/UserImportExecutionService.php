<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Models\UserImportBatch;
use App\Models\UserImportRow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class UserImportExecutionService
{
    public const MAX_SYNCHRONOUS_INVITATIONS = 100;

    private const DIRECT_ACTIVATION_PASSWORD = 'password';

    public function __construct(
        private readonly UserImportValidationService $validator,
        private readonly UserInvitationService $invitations,
        private readonly UserProvisioningService $provisioning,
        private readonly BranchAssignmentService $branches,
        private readonly ProjectAssignmentService $projects,
        private readonly ReportingHierarchyService $hierarchy,
        private readonly SalesCoordinatorAssignmentService $salesCoordinators,
        private readonly AccountAuditService $audit,
    ) {}

    public function execute(
        int $batchId,
        User $actor,
        bool $sendInvitations,
        string $expectedUpdatedAt,
        string $activationMode = UserImportBatch::ACTIVATION_MODE_INVITATION,
    ): UserImportBatch {
        try {
            $outcome = DB::transaction(function () use ($batchId, $actor, $sendInvitations, $expectedUpdatedAt, $activationMode) {
                $batch = UserImportBatch::query()->lockForUpdate()->findOrFail($batchId);
                if ((int) $batch->uploaded_by !== (int) $actor->id && ! $actor->isSuperadmin()) {
                    return ['state' => 'unavailable', 'message' => 'Batch impor tidak dapat dikonfirmasi oleh pengguna ini.'];
                }
                if ($activationMode === UserImportBatch::ACTIVATION_MODE_DIRECT && ! $actor->isSuperadmin()) {
                    return ['state' => 'unavailable', 'message' => 'Hanya Super Admin yang dapat mengaktifkan pengguna secara langsung.'];
                }
                if ($batch->status !== UserImportBatch::STATUS_PREVIEW_READY || $batch->confirmed_at !== null) {
                    return ['state' => 'unavailable', 'message' => 'Batch impor sudah diproses atau tidak siap dikonfirmasi.'];
                }
                if ($batch->expires_at === null || $batch->expires_at->isPast()) {
                    return ['state' => 'unavailable', 'message' => 'Preview impor telah kedaluwarsa. Unggah ulang file untuk melanjutkan.'];
                }
                if (! $batch->updated_at->equalTo(Carbon::parse($expectedUpdatedAt))) {
                    return ['state' => 'unavailable', 'message' => 'Preview impor telah berubah. Muat ulang halaman sebelum mengonfirmasi.'];
                }

                $batch->update(['activation_mode' => $activationMode]);
                $activationMode = $batch->activation_mode;
                $directActivation = $activationMode === UserImportBatch::ACTIVATION_MODE_DIRECT;

                $storedRows = $batch->rows()->orderBy('row_number')->lockForUpdate()->get();
                $validatedRows = $this->validator->validate($storedRows->map(fn (UserImportRow $row) => [
                    'row_number' => $row->row_number,
                    'raw_data' => $row->raw_data,
                    'parser_errors' => $this->unsafeValueErrors($row->raw_data),
                ])->all(), $actor);
                $effectiveInvitations = $directActivation ? 0 : collect($validatedRows)->filter(fn (array $row) => $sendInvitations
                    || $row['normalized_data']['status'] === AccountStatus::Invited->value)->count();
                if ($effectiveInvitations > self::MAX_SYNCHRONOUS_INVITATIONS) {
                    foreach ($validatedRows as &$validatedRow) {
                        if ($sendInvitations || $validatedRow['normalized_data']['status'] === AccountStatus::Invited->value) {
                            $validatedRow['errors'][] = 'Maksimal 100 undangan dapat dikirim dalam satu batch.';
                            $validatedRow['validation_status'] = UserImportRow::VALIDATION_ERROR;
                        }
                    }
                    unset($validatedRow);
                }

                $rowsByNumber = $storedRows->keyBy('row_number');
                foreach ($validatedRows as $validatedRow) {
                    $rowsByNumber[$validatedRow['row_number']]->update([
                        'normalized_data' => $validatedRow['normalized_data'],
                        'validation_status' => $validatedRow['validation_status'],
                        'errors' => array_values(array_unique($validatedRow['errors'])),
                        'warnings' => array_values(array_unique($validatedRow['warnings'])),
                    ]);
                }
                $counts = $this->validationCounts($validatedRows);
                if ($counts['error_rows'] > 0) {
                    $batch->update([
                        'status' => UserImportBatch::STATUS_VALIDATION_FAILED,
                        ...$counts,
                        'skipped_rows' => $counts['total_rows'],
                    ]);

                    return ['state' => 'validation_failed', 'batch' => $batch];
                }

                $batch->update([
                    'status' => UserImportBatch::STATUS_CONFIRMED,
                    'send_invitations' => $sendInvitations,
                    'confirmed_at' => now(),
                    ...$counts,
                    'created_rows' => 0,
                    'invitation_sent_rows' => 0,
                    'invitation_failed_rows' => 0,
                    'skipped_rows' => 0,
                ]);
                $this->audit->logUserImportBatch('user_import_confirmed', $batch, $actor, $counts);
                $batch->update(['status' => UserImportBatch::STATUS_PROCESSING]);

                $usersByEmail = [];
                $credentials = [];
                $roles = $directActivation ? Role::query()->whereIn('id', collect($validatedRows)->pluck('normalized_data.role_id'))->pluck('name', 'id') : collect();
                $branchNames = $directActivation ? Branch::query()->whereIn('id', collect($validatedRows)->pluck('normalized_data.primary_branch_id'))->pluck('name', 'id') : collect();
                foreach ($batch->rows()->orderBy('row_number')->get() as $row) {
                    $data = $row->normalized_data;
                    $password = $directActivation ? self::DIRECT_ACTIVATION_PASSWORD : null;
                    $attributes = [
                        'name' => $data['name'],
                        'email' => $data['email'],
                        'role_id' => $data['role_id'],
                    ];
                    $user = $directActivation
                        ? $this->provisioning->createDirectlyActivated($attributes, $password, $actor)
                        : $this->invitations->createDraft($attributes, $actor);
                    $branchAssignments = collect($data['branch_ids'])->map(fn ($id) => ['branch_id' => (int) $id])->all();
                    $this->branches->assign($user, $branchAssignments, (int) $data['primary_branch_id'], $actor);
                    $projectAssignments = collect($data['project_ids'])->map(fn ($id) => [
                        'project_id' => (int) $id,
                        'is_active' => true,
                        'assignment_start_date' => today()->toDateString(),
                    ])->all();
                    $this->projects->assign($user, $projectAssignments, $data['primary_project_id'] ? (int) $data['primary_project_id'] : null, $actor);
                    $row->update([
                        'created_user_id' => $user->id,
                        'creation_status' => 'created',
                        'invitation_status' => 'not_requested',
                    ]);
                    $this->audit->logBulkUser(
                        $directActivation ? 'user_directly_activated_bulk' : 'user_created_bulk',
                        $user,
                        $actor,
                        $batch,
                        $row->id,
                    );
                    if ($directActivation) {
                        $credentials[] = [
                            'name' => $data['name'],
                            'email' => $data['email'],
                            'role' => $roles[$data['role_id']],
                            'primary_branch' => $branchNames[$data['primary_branch_id']],
                            'temporary_password' => $password,
                        ];
                    }
                    $usersByEmail[$data['email']] = $user;
                }

                foreach ($batch->rows()->orderBy('row_number')->get() as $row) {
                    $data = $row->normalized_data;
                    if ($data['sales_coordinator_email'] !== '') {
                        $coordinator = $usersByEmail[$data['sales_coordinator_email']]
                            ?? User::findOrFail($data['sales_coordinator_user_id']);
                        $this->salesCoordinators->assign(User::findOrFail($row->created_user_id), $coordinator);
                    }

                    if ($data['supervisor_email'] === '') {
                        continue;
                    }
                    $user = User::findOrFail($row->created_user_id);
                    if (isset($usersByEmail[$data['supervisor_email']])) {
                        $this->hierarchy->assignOnboardingSupervisor($user, $usersByEmail[$data['supervisor_email']], $batch, $actor, $row->id);
                    } else {
                        $this->hierarchy->assignSupervisor($user, (int) $data['supervisor_user_id'], $actor);
                        $this->audit->logBulkUser('user_supervisor_linked_bulk', $user, $actor, $batch, $row->id);
                    }
                }

                $batch->update([
                    'created_rows' => $batch->total_rows,
                    'credential_payload' => $directActivation ? $credentials : null,
                    'credential_expires_at' => $directActivation ? now()->addDay() : null,
                    'credential_downloaded_at' => null,
                    'status' => $directActivation ? UserImportBatch::STATUS_COMPLETED : UserImportBatch::STATUS_PROCESSING,
                    'completed_at' => $directActivation ? now() : null,
                ]);
                if ($directActivation) {
                    $this->audit->logUserImportBatch('user_import_direct_activation_completed', $batch, $actor, $this->resultCounts($batch));
                }

                return ['state' => $directActivation ? 'direct_completed' : 'created', 'batch' => $batch->fresh()];
            });
        } catch (Throwable $exception) {
            report($exception);
            $failedBatch = UserImportBatch::query()->find($batchId);
            if ($failedBatch !== null && $failedBatch->status !== UserImportBatch::STATUS_COMPLETED) {
                $failedBatch->update([
                    'status' => UserImportBatch::STATUS_FAILED,
                    'confirmed_at' => $failedBatch->confirmed_at ?? now(),
                    'created_rows' => 0,
                    'invitation_sent_rows' => 0,
                    'invitation_failed_rows' => 0,
                    'skipped_rows' => $failedBatch->total_rows,
                    'completed_at' => now(),
                ]);
                if (! ActivityLog::query()->where('subject_type', UserImportBatch::class)->where('subject_id', $batchId)
                    ->where('event', 'user_import_confirmed')->exists()) {
                    try {
                        $this->audit->logUserImportBatch('user_import_confirmed', $failedBatch, $actor, $this->resultCounts($failedBatch));
                    } catch (Throwable $auditException) {
                        report($auditException);
                    }
                }
            }
            UserImportRow::query()->where('batch_id', $batchId)->update([
                'creation_status' => 'failed',
                'invitation_status' => null,
                'created_user_id' => null,
                'errors' => json_encode(['Import gagal diproses. Tidak ada akun dari batch ini yang dibuat.']),
            ]);

            throw ValidationException::withMessages(['batch_id' => 'Import gagal diproses. Tidak ada akun yang dibuat.']);
        }

        if ($outcome['state'] === 'unavailable') {
            throw ValidationException::withMessages(['batch_id' => $outcome['message']]);
        }
        if ($outcome['state'] === 'validation_failed') {
            throw ValidationException::withMessages(['batch_id' => 'Data berubah sejak preview. Periksa hasil validasi terbaru sebelum melanjutkan.']);
        }

        /** @var UserImportBatch $batch */
        $batch = $outcome['batch'];
        if ($outcome['state'] === 'direct_completed') {
            return $batch;
        }

        $sent = 0;
        $failed = 0;
        foreach ($batch->rows()->orderBy('row_number')->get() as $row) {
            $effectiveInvited = $batch->send_invitations || $row->normalized_data['status'] === AccountStatus::Invited->value;
            if (! $effectiveInvited) {
                continue;
            }
            $user = User::findOrFail($row->created_user_id);
            try {
                $this->invitations->send($user, $actor);
                $row->update(['invitation_status' => 'sent']);
                $this->audit->logBulkUser('user_invitation_sent_bulk', $user, $actor, $batch, $row->id);
                $sent++;
            } catch (Throwable $exception) {
                report($exception);
                $row->update([
                    'invitation_status' => 'email_failed',
                    'errors' => array_values(array_unique([...($row->errors ?? []), 'Akun dibuat, tetapi email gagal dikirim.'])),
                ]);
                $this->audit->logBulkUser('user_invitation_failed_bulk', $user, $actor, $batch, $row->id);
                $failed++;
            }
        }

        $batch->update([
            'status' => UserImportBatch::STATUS_COMPLETED,
            'invitation_sent_rows' => $sent,
            'invitation_failed_rows' => $failed,
            'completed_at' => now(),
        ]);
        $batch->refresh();
        $this->audit->logUserImportBatch('user_import_completed', $batch, $actor, $this->resultCounts($batch));

        return $batch;
    }

    private function validationCounts(array $rows): array
    {
        $rows = collect($rows);

        return [
            'total_rows' => $rows->count(),
            'valid_rows' => $rows->where('validation_status', UserImportRow::VALIDATION_VALID)->count(),
            'warning_rows' => $rows->where('validation_status', UserImportRow::VALIDATION_WARNING)->count(),
            'error_rows' => $rows->where('validation_status', UserImportRow::VALIDATION_ERROR)->count(),
        ];
    }

    private function resultCounts(UserImportBatch $batch): array
    {
        return $batch->only([
            'total_rows', 'valid_rows', 'warning_rows', 'error_rows', 'created_rows',
            'invitation_sent_rows', 'invitation_failed_rows', 'skipped_rows',
        ]);
    }

    private function unsafeValueErrors(array $raw): array
    {
        $errors = [];
        foreach (array_values($raw) as $index => $value) {
            if (preg_match('/^[=+\-@]/u', ltrim((string) $value)) === 1) {
                $errors[] = 'Kolom '.chr(65 + $index).' mengandung formula atau nilai berawalan karakter yang tidak aman.';
            }
        }

        return $errors;
    }
}
