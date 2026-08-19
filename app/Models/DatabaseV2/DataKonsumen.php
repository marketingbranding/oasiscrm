<?php

namespace App\Models\DatabaseV2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DataKonsumen extends Model
{
    use SoftDeletes;

    protected $table = 'db_v2_data_konsumen';

    protected $fillable = [
        'branch_id', 'id_kavling', 'no_ktp', 'nama_konsumen', 'tanggal_lahir',
        'pekerjaan', 'detail_pekerjaan', 'alamat', 'kelurahan', 'kecamatan',
        'kabupaten_kota', 'no_hp', 'nama_kondar', 'no_hp_kondar',
        'status_cash', 'status_konsumen', 'keterangan', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['tanggal_lahir' => 'date'];
    }
}
