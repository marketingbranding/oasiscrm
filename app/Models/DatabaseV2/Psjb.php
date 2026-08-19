<?php

namespace App\Models\DatabaseV2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Psjb extends Model
{
    use SoftDeletes;

    protected $table = 'db_v2_psjb';

    protected $fillable = [
        'branch_id', 'id_kavling', 'no_ktp', 'nama_konsumen',
        'tanggal_psjb', 'nama_koordinator', 'nama_sales', 'harga_unit',
        'tanggal_utj', 'utj', 'tanggal_dp_klt', 'dp_all_in',
        'nominal_cicilan', 'jumlah_cicilan', 'luas_klt', 'harga_klt_m',
        'harga_klt_total', 'cara_pembayaran', 'nama_promo', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_psjb' => 'date', 'tanggal_utj' => 'date', 'tanggal_dp_klt' => 'date',
            'harga_unit' => 'decimal:2', 'utj' => 'decimal:2', 'dp_all_in' => 'decimal:2',
            'nominal_cicilan' => 'decimal:2', 'luas_klt' => 'decimal:2',
            'harga_klt_m' => 'decimal:2', 'harga_klt_total' => 'decimal:2',
        ];
    }
}
