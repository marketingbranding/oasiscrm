<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ConsumerBankProcess extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $process): void {
            if ($process->attempt_no === null) {
                $process->attempt_no = (int) self::query()
                    ->where('consumer_application_id', $process->consumer_application_id)
                    ->max('attempt_no') + 1;
            }

            if (blank($process->attempt_key)) {
                $transactionId = ConsumerApplication::query()->whereKey($process->consumer_application_id)->value('id_transaksi');
                $process->attempt_key = ($transactionId ?: 'TRX-APP-'.$process->consumer_application_id).'-BANK-'.str_pad((string) $process->attempt_no, 3, '0', STR_PAD_LEFT);
            }
        });

        static::updating(function (self $process): void {
            if ($process->isDirty(['attempt_no', 'attempt_key'])) {
                throw new LogicException('Identitas bank attempt bersifat immutable.');
            }
        });
    }

    protected $fillable = [
        'consumer_application_id',
        'attempt_no',
        'attempt_key',
        'tanggal_terima_bank',
        'id_berkas',
        'no_sp3k',
        'bank_name',
        'kc_unit',
        'tipe_pemberkasan',
        'request_plafond',
        'request_tenor',
        'approved_plafond',
        'approved_tenor',
        'response_type',
        'status',
        'revision_category',
        'revision_detail',
        'obstacle',
        'notes',
        'submitted_at',
        'verified_at',
        'sp3k_at',
        'rejected_at',
        'rejection_reason',
        'source',
        'source_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_terima_bank' => 'date',
            'request_plafond' => 'decimal:2',
            'approved_plafond' => 'decimal:2',
            'request_tenor' => 'integer',
            'approved_tenor' => 'integer',
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'sp3k_at' => 'datetime',
            'rejected_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ConsumerApplication::class, 'consumer_application_id');
    }
}
