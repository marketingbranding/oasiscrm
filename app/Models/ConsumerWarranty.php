<?php

namespace App\Models;

use Database\Factories\ConsumerWarrantyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumerWarranty extends Model
{
    /** @use HasFactory<ConsumerWarrantyFactory> */
    use HasFactory;

    protected $fillable = [
        'consumer_application_id', 'consumer_bast_record_id', 'status_komplain', 'tgl_sales_ke_sam',
        'tgl_sam_ke_sat', 'tgl_sat_ke_sam', 'tgl_sam_ke_sales', 'tgl_sales_ke_kons', 'detail_garansi',
        'tanggal_selesai', 'status_garansi', 'source', 'source_id', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'tgl_sales_ke_sam' => 'date', 'tgl_sam_ke_sat' => 'date', 'tgl_sat_ke_sam' => 'date',
            'tgl_sam_ke_sales' => 'date', 'tanggal_selesai' => 'date', 'metadata' => 'array',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ConsumerApplication::class, 'consumer_application_id');
    }

    public function bast(): BelongsTo
    {
        return $this->belongsTo(ConsumerBastRecord::class, 'consumer_bast_record_id');
    }
}
