<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\DanaTalangan;
use App\Models\DanaTalanganPayment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DanaTalanganPaymentService
{
    public function save(DanaTalangan $record, array $data, User $actor, ?UploadedFile $evidence = null, ?DanaTalanganPayment $payment = null): DanaTalanganPayment
    {
        return DB::transaction(function () use ($record, $data, $actor, $evidence, $payment): DanaTalanganPayment {
            $payment ??= new DanaTalanganPayment(['dana_talangan_id' => $record->id, 'created_by' => $actor->id]);
            $payment->fill([
                'paid_at' => $data['paid_at'],
                'amount' => $data['amount'],
                'note' => $data['note'] ?? null,
                'is_verified' => (bool) ($data['is_verified'] ?? true),
                'verified_by' => ($data['is_verified'] ?? true) ? $actor->id : null,
                'verified_at' => ($data['is_verified'] ?? true) ? now() : null,
            ]);
            $oldEvidencePath = $payment->exists ? $payment->evidence_path : null;
            if ($evidence !== null) {
                $payment->evidence_path = $evidence->store('dana-talangan-payments', 'local');
            }
            $payment->save();
            if ($evidence !== null && $oldEvidencePath !== null) {
                Storage::disk('local')->delete($oldEvidencePath);
            }
            $this->syncStatus($record->fresh(), $actor);

            return $payment->fresh();
        });
    }

    public function delete(DanaTalanganPayment $payment, User $actor): void
    {
        DB::transaction(function () use ($payment, $actor): void {
            $record = $payment->danaTalangan()->lockForUpdate()->firstOrFail();
            $payment->delete();
            $this->syncStatus($record->fresh(), $actor);
            ActivityLog::query()->create(['causer_id' => $actor->id, 'subject_type' => DanaTalanganPayment::class, 'subject_id' => $payment->id, 'event' => 'deleted', 'description' => 'Pembayaran Dana Talangan dihapus.', 'properties' => ['before' => $payment->toArray()]]);
        });
    }

    public function summary(DanaTalangan $record): array
    {
        $verified = $this->sumCents($record, true);
        $unverified = $this->sumCents($record, false);
        $nominal = $this->toCents($record->nominal);
        $remaining = max(0, $nominal - $verified);

        return [
            'verified' => $this->formatCents($verified),
            'unverified' => $this->formatCents($unverified),
            'remaining' => $this->formatCents($remaining),
            'overpaid' => $this->formatCents(max(0, $verified - $nominal)),
        ];
    }

    public function recalculateStatus(DanaTalangan $record): void
    {
        $this->syncStatus($record, null);
    }

    private function syncStatus(DanaTalangan $record, ?User $actor): void
    {
        $verified = $this->sumCents($record, true);
        $nominal = $this->toCents($record->nominal);

        if ($record->status_auto_lunas && $nominal > 0 && $verified < $nominal) {
            $record->update([
                'status' => $record->status_before_auto_lunas ?: 'sanggup',
                'status_auto_lunas' => false,
                'status_before_auto_lunas' => null,
            ]);

            return;
        }

        if (! $record->status_auto_lunas && $nominal > 0 && $verified >= $nominal && $record->status !== 'lunas') {
            $record->update([
                'status' => 'lunas',
                'status_auto_lunas' => true,
                'status_before_auto_lunas' => $record->status,
            ]);
        }
    }

    private function sumCents(DanaTalangan $record, bool $verified): int
    {
        return (int) $record->payments()->where('is_verified', $verified)->get()->sum(fn (DanaTalanganPayment $payment): int => $this->toCents($payment->amount));
    }

    private function toCents(mixed $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        [$whole, $fraction] = array_pad(explode('.', (string) $amount, 2), 2, '0');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function formatCents(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), abs($cents % 100));
    }
}
