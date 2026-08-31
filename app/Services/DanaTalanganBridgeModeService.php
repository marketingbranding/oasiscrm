<?php

namespace App\Services;

use App\Enums\DanaTalanganBridgeMode;
use App\Enums\DanaTalanganBridgeStatus;
use App\Models\DanaTalanganBridgeSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DanaTalanganBridgeModeService
{
    public function spreadsheetId(): string
    {
        $spreadsheetId = trim((string) config('services.google_sheets.dana_talangan_spreadsheet_id'));
        if ($spreadsheetId === '') {
            throw new \DomainException('DANA_TALANGAN_SHEET_ID belum dikonfigurasi.');
        }

        return $spreadsheetId;
    }

    public function mode(): DanaTalanganBridgeMode
    {
        $mode = DanaTalanganBridgeSetting::query()->where('spreadsheet_id', $this->spreadsheetId())->value('mode');

        return $mode instanceof DanaTalanganBridgeMode
            ? $mode
            : ($mode !== null ? DanaTalanganBridgeMode::from($mode) : DanaTalanganBridgeMode::Off);
    }

    public function isPushEnabled(): bool
    {
        return config('services.google_sheets.dana_talangan_bridge_enabled') && $this->mode()->pushEnabled();
    }

    public function isPullEnabled(): bool
    {
        return config('services.google_sheets.dana_talangan_bridge_enabled') && $this->mode()->pullEnabled();
    }

    public function setting(): ?DanaTalanganBridgeSetting
    {
        return DanaTalanganBridgeSetting::query()->where('spreadsheet_id', $this->spreadsheetId())->first();
    }

    public function setMode(DanaTalanganBridgeMode $mode, ?User $actor = null): DanaTalanganBridgeSetting
    {
        if ($mode !== DanaTalanganBridgeMode::Off && ! config('services.google_sheets.dana_talangan_bridge_enabled')) {
            throw new \DomainException('Bridge Dana Talangan sedang dinonaktifkan.');
        }
        $setting = $this->setting();
        if ($mode !== DanaTalanganBridgeMode::Off && $setting?->status !== DanaTalanganBridgeStatus::Success) {
            throw new \DomainException('Preflight bridge Dana Talangan harus berhasil sebelum mode diaktifkan.');
        }

        return DB::transaction(fn () => DanaTalanganBridgeSetting::query()->updateOrCreate(
            ['spreadsheet_id' => $this->spreadsheetId()],
            [
                'mode' => $mode,
                'status' => $mode === DanaTalanganBridgeMode::Off ? 'disabled' : 'active',
                'enabled_by' => $mode === DanaTalanganBridgeMode::Off ? null : $actor?->id,
                'enabled_at' => $mode === DanaTalanganBridgeMode::Off ? null : now(),
            ],
        ));
    }
}
