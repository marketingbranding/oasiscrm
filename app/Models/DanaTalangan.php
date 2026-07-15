<?php

namespace App\Models;

use App\Models\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DanaTalangan extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'oasis_sync_id',
        'sheet_name',
        'sheet_row_number',
        'sync_status',
        'last_sync_error',
        'source_hash',
        'last_synced_at',
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
            'last_synced_at' => 'datetime',
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
        return $this->nama_konsumen.' (Dana Talangan)';
    }
}
