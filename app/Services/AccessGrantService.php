<?php

namespace App\Services;

use App\Models\AccessGrant;
use App\Models\ActivityLog;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccessGrantService
{
    public const MODULES = ['sales_leads', 'sales_daily_plans'];

    public function __construct(
        private readonly AppSettingResolver $settings,
        private readonly OrganizationGraphService $graph,
        private readonly CollaborationNotificationService $notifications,
    ) {}

    public function create(User $actor, array $data): AccessGrant
    {
        abort_unless($actor->isSuperadmin(), 403);
        $expiresAt = $data['expires_at'];
        $maxDays = (int) $this->settings->value('access_grants.max_days');
        if (! $expiresAt instanceof CarbonInterface || $expiresAt->lte(now()) || $expiresAt->gt(now()->addDays($maxDays))) {
            throw ValidationException::withMessages(['expires_at' => "Masa berlaku wajib di antara sekarang dan {$maxDays} hari."]);
        }
        if (($data['can_edit'] ?? false) && ! ($data['can_view'] ?? false)) {
            throw ValidationException::withMessages(['can_edit' => 'Akses ubah wajib disertai akses lihat.']);
        }
        if (($data['can_edit'] ?? false) && ! $data['grantee']->hasPrimaryRole('sales_coordinator')) {
            throw ValidationException::withMessages(['can_edit' => 'Akses ubah hanya dapat diberikan kepada Koordinator Sales.']);
        }

        $grant = DB::transaction(function () use ($actor, $data, $expiresAt): AccessGrant {
            $grant = AccessGrant::create([
                ...$data,
                'grantee_user_id' => $data['grantee']->id,
                'subject_user_id' => $data['subject']->id,
                'expires_at' => $expiresAt,
                'granted_by' => $actor->id,
            ]);
            ActivityLog::create(['causer_id' => $actor->id, 'subject_type' => AccessGrant::class, 'subject_id' => $grant->id, 'event' => 'access_grant_created', 'description' => 'Izin Akses Khusus diberikan.', 'properties' => ['grantee_user_id' => $grant->grantee_user_id, 'subject_user_id' => $grant->subject_user_id, 'module' => $grant->module]]);

            return $grant;
        });
        $this->notifications->create($grant->grantee, 'access_grant_created', 'Izin Akses Khusus diberikan', 'Anda mendapat Izin Akses Khusus untuk data tim Sales.');

        return $grant;
    }

    public function revoke(User $actor, AccessGrant $grant, string $reason): void
    {
        abort_unless($actor->isSuperadmin(), 403);
        DB::transaction(function () use ($actor, $grant, $reason): void {
            $grant->update(['revoked_at' => now(), 'revoked_by' => $actor->id, 'revocation_reason' => $reason]);
            ActivityLog::create(['causer_id' => $actor->id, 'subject_type' => AccessGrant::class, 'subject_id' => $grant->id, 'event' => 'access_grant_revoked', 'description' => 'Izin Akses Khusus dicabut.', 'properties' => ['reason' => $reason]]);
        });
        $this->notifications->create($grant->grantee, 'access_grant_revoked', 'Izin Akses Khusus dicabut', 'Izin Akses Khusus Anda telah dicabut.');
    }

    /** @return array<int, AccessGrant> */
    public function activeFor(User $grantee, string $module): array
    {
        return AccessGrant::query()->active()->where('grantee_user_id', $grantee->id)->whereIn('module', AccessGrant::grantModulesForScope($module))->get()->all();
    }

    /** @return array<int> */
    public function grantedUserIds(User $grantee, string $module, bool $edit = false): array
    {
        $ids = [];
        foreach ($this->activeFor($grantee, $module) as $grant) {
            if ($edit && ! $grant->can_edit) {
                continue;
            }
            if (! $edit && ! $grant->can_view) {
                continue;
            }
            $ids[] = (int) $grant->subject_user_id;
            if ($grant->include_descendants) {
                $ids = [...$ids, ...$this->graph->descendantIds($grant->subject, strict: true)];
            }
        }

        return array_values(array_unique($ids));
    }
}
