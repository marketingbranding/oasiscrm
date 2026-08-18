<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumerPsjb extends Model
{
    use HasFactory;

    protected $fillable = [
        'consumer_application_id', 'consumer_stage_event_id', 'id_kavling', 'id_kons', 'id_psjb',
        'tanggal_psjb', 'nama_koordinator', 'nama_sales', 'harga_unit', 'tanggal_utj', 'utj',
        'tanggal_dp_klt', 'dp_all_in', 'nominal_cicilan', 'jumlah_cicilan', 'luas_klt',
        'harga_klt_m', 'harga_klt_total', 'cara_pembayaran', 'promo_id', 'status', 'keterangan',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_psjb' => 'date', 'tanggal_utj' => 'date', 'tanggal_dp_klt' => 'date',
            'harga_unit' => 'decimal:2', 'utj' => 'decimal:2', 'dp_all_in' => 'decimal:2',
            'nominal_cicilan' => 'decimal:2', 'luas_klt' => 'decimal:2',
            'harga_klt_m' => 'decimal:2', 'harga_klt_total' => 'decimal:2',
            'jumlah_cicilan' => 'integer',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ConsumerApplication::class, 'consumer_application_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(ConsumerStageEvent::class, 'consumer_stage_event_id');
    }

    public function promo(): BelongsTo
    {
        return $this->belongsTo(Promo::class);
    }
}
