<?php

namespace App\Models\DatabaseV2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Akad extends Model
{
    use SoftDeletes;

    protected $table = 'db_v2_akad';

    protected $fillable = [
        'branch_id', 'id_kavling', 'no_ktp', 'nama_konsumen',
        'tanggal_akad', 'kualitas_akad', 'status_bangunan', 'status_dp_konsumen',
        'status_utilitas', 'status_konsumen', 'keterangan_terlambat', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['tanggal_akad' => 'date'];
    }
}
