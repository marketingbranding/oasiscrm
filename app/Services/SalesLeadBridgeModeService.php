<?php

namespace App\Services;

use App\Enums\SalesLeadBridgeMode;
use App\Models\Branch;
use App\Models\SalesLeadBridgeSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SalesLeadBridgeModeService
{
    public function mode(Branch $branch): SalesLeadBridgeMode
    {
        $mode = $branch->bridgeSetting()->value('mode');

        return $mode instanceof SalesLeadBridgeMode
            ? $mode
            : ($mode !== null ? SalesLeadBridgeMode::from($mode) : SalesLeadBridgeMode::Off);
    }

    public function isPushEnabled(Branch $branch): bool
    {
        return config('services.google_sheets.sales_lead_sync_enabled') && $this->mode($branch)->pushEnabled();
    }

    public function isPullEnabled(Branch $branch): bool
    {
        return config('services.google_sheets.sales_lead_sync_enabled') && $this->mode($branch)->pullEnabled();
    }

    public function setMode(Branch $branch, SalesLeadBridgeMode $mode, ?User $actor = null): SalesLeadBridgeSetting
    {
        if ($mode !== SalesLeadBridgeMode::Off && ! config('services.google_sheets.sales_lead_sync_enabled')) {
            throw new \DomainException('Sinkronisasi Google Sheets Lead Sales sedang dinonaktifkan.');
        }

        $setting = SalesLeadBridgeSetting::query()->where('branch_id', $branch->id)->first();
        if ($mode !== SalesLeadBridgeMode::Off && $setting?->status !== 'success') {
            throw new \DomainException('Preflight bridge lead harus berhasil sebelum mode diaktifkan.');
        }

        return DB::transaction(fn () => SalesLeadBridgeSetting::query()->updateOrCreate(
            ['branch_id' => $branch->id],
            [
                'mode' => $mode,
                'status' => $mode === SalesLeadBridgeMode::Off ? 'disabled' : 'active',
                'enabled_by' => $mode === SalesLeadBridgeMode::Off ? null : $actor?->id,
                'enabled_at' => $mode === SalesLeadBridgeMode::Off ? null : now(),
            ],
        ));
    }
}
