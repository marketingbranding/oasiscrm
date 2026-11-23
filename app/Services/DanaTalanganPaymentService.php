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
            if ($evidence !== null) {
                $payment->evidence_path = $evidence->store('dana-talangan-payments', 'local');
            }
            $payment->save();
            $this->syncStatus($record->fresh(), $actor);

            return $payment->fresh();
        });
    }

    public function delete(DanaTalanganPayment $payment, User $actor): void
    {
        DB::transaction(function () use ($payment, $actor): void {
            $record = $payment->danaTalangan()->lockForUpdate()->firstOrFail();
            $path = $payment->evidence_path;
            $payment->delete();
            $this->syncStatus($record->fresh(), $actor);
            ActivityLog::query()->create(['causer_id' => $actor->id, 'subject_type' => DanaTalanganPayment::class, 'subject_id' => $payment->id, 'event' => 'deleted', 'description' => 'Pembayaran Dana Talangan dihapus.', 'properties' => ['before' => $payment->toArray()]]);
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }
        });
    }

    public function summary(DanaTalangan $record): array
    {
        $verified = (string) $record->payments()->where('is_verified', true)->sum('amount');
        $unverified = (string) $record->payments()->where('is_verified', false)->sum('amount');
        $nominal = (string) ($record->nominal ?? '0');
        $remaining = bcsub($nominal, $verified, 2);

        return ['verified' => $verified, 'unverified' => $unverified, 'remaining' => $remaining, 'overpaid' => bccomp($verified, $nominal, 2) > 0 ? bcsub($verified, $nominal, 2) : '0.00'];
    }

    private function syncStatus(DanaTalangan $record, User $actor): void
    {
        $summary = $this->summary($record);
        if ($record->status !== 'lunas' && bccomp($summary['verified'], (string) ($record->nominal ?? '0'), 2) >= 0) {
            $record->update(['status' => 'lunas']);
        } elseif ($record->status === 'lunas' && bccomp($summary['verified'], (string) ($record->nominal ?? '0'), 2) < 0) {
            $record->update(['status' => 'sanggup']);
        }
    }
}
