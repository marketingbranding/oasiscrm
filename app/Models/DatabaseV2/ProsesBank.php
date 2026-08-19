<?php

namespace App\Models\DatabaseV2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProsesBank extends Model
{
    use SoftDeletes;

    protected $table = 'db_v2_proses_bank';

    protected $fillable = [
        'branch_id', 'id_kavling', 'no_ktp', 'nama_konsumen',
        'no_sp3k', 'jenis_respon', 'approved_plafond', 'approved_tenor',
        'kategori_revisi', 'detail_revisi', 'kendala', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['approved_plafond' => 'decimal:2'];
    }
}
