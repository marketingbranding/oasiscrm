<?php

namespace App\Models\DatabaseV2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bast extends Model
{
    use SoftDeletes;

    protected $table = 'db_v2_bast';

    protected $fillable = [
        'branch_id', 'id_kavling', 'no_ktp', 'nama_konsumen',
        'tanggal_bast', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['tanggal_bast' => 'date'];
    }
}
