<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DanaTalangan extends Model
{
    protected $fillable = [
        'tanggal',
        'nama_konsumen',
        'kav',
        'project_name',
        'pinjam_nama',
        'pekerjaan',
        'status_perkawinan',
        'umur',
        'nama_marketing',
        'penyelesaian',
        'konfirmasi_keuangan',
        'branch_id',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'pinjam_nama' => 'boolean',
            'konfirmasi_keuangan' => 'boolean',
            'umur' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
