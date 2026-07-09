<?php

namespace App\Models;

use App\Models\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DanaTalangan extends Model
{
    use LogsActivity;
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
        'tgl_komitmen',
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
            'tgl_komitmen' => 'date',
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

    protected function activityLabel(): string
    {
        return $this->nama_konsumen . ' (Dana Talangan)';
    }
}
