<?php

namespace App\Models;

use App\Models\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DanaTalangan extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'oasis_sync_id',
        'sheet_name',
        'sheet_row_number',
        'remote_target_spreadsheet_id',
        'sync_status',
        'last_sync_error',
        'source_hash',
        'last_synced_payload_hash',
        'last_remote_payload_hash',
        'last_synced_field_hashes',
        'delivery_attempted_at',
        'delete_pending_at',
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
        'project_id',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'tgl_komitmen' => 'date',
            'pinjam_nama' => 'boolean',
            'konfirmasi_keuangan' => 'boolean',
            'umur' => 'integer',
            'last_synced_field_hashes' => 'array',
            'delivery_attempted_at' => 'datetime',
            'delete_pending_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(LeadMaster::class, 'project_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function reconciliationItems(): HasMany
    {
        return $this->hasMany(DanaTalanganReconciliationItem::class);
    }

    protected function activityLabel(): string
    {
        return $this->nama_konsumen.' (Dana Talangan)';
    }
}
