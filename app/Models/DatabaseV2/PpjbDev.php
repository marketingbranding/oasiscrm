<?php

namespace App\Models\DatabaseV2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PpjbDev extends Model
{
    use SoftDeletes;

    protected $table = 'db_v2_ppjb_dev';

    protected $fillable = [
        'branch_id', 'id_kavling', 'no_ktp', 'nama_konsumen',
        'tanggal_sp3k', 'tanggal_ttd_ppjb', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['tanggal_sp3k' => 'date', 'tanggal_ttd_ppjb' => 'date'];
    }
}
