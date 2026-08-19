<?php

namespace App\Models\DatabaseV2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pemberkasan extends Model
{
    use SoftDeletes;

    protected $table = 'db_v2_pemberkasan';

    protected $fillable = [
        'branch_id', 'id_kavling', 'no_ktp', 'nama_konsumen',
        'tanggal_terima_bank', 'bank', 'kc_unit', 'request_plafond',
        'request_tenor', 'tipe_pemberkasan', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_terima_bank' => 'date',
            'request_plafond' => 'decimal:2',
        ];
    }
}
