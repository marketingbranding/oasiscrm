<?php

namespace App\Models\DatabaseV2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BiChecking extends Model
{
    use SoftDeletes;

    protected $table = 'db_v2_bi_checking';

    protected $fillable = [
        'branch_id', 'id_kavling', 'no_ktp', 'nama_konsumen',
        'tanggal_slik', 'hasil_slik', 'keterangan', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['tanggal_slik' => 'date'];
    }
}
