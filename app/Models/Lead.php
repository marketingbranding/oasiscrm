<?php

namespace App\Models;

use App\Models\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lead extends Model
{
    use LogsActivity;

    protected $fillable = [
        'id_lead',
        'branch_id',
        'id_promo',
        'tanggal_lead',
        'sumber',
        'platform',
        'campaign',
        'nama_konsumen',
        'no_hp',
        'proyek',
        'sales_pic',
        'status_lead',
        'keterangan',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_lead' => 'date',
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
        return $this->id_lead ?? 'Lead #' . $this->id;
    }

    public static function sourceAbbreviation(string $source): string
    {
        $map = [
            'Online' => 'ON',
            'Canvassing' => 'CA',
            'Lead Cabang' => 'LC',
            'Referral' => 'RE',
            'Walk-in' => 'WI',
            'Event Mandiri' => 'EM',
            'Kerjasama Event' => 'KE',
            'Event Internal' => 'EI',
            'Pameran' => 'PM',
            'Digital Campaign' => 'DC',
            'Referensi' => 'RF',
            'Media Sosial' => 'MS',
            'Telemarketing' => 'TM',
            'Website' => 'WB',
        ];
        return $map[$source] ?? 'XX';
    }

    public static function platformAbbreviation(string $platform): string
    {
        $map = [
            'Kosong' => 'KO',
            'Iklan Facebook' => 'IF',
            'Iklan Instagram' => 'IG',
            'Whatsapp' => 'WA',
            'Tiktok' => 'TK',
        ];
        return $map[$platform] ?? 'XX';
    }

    public static function generateIdLead(string $tanggalLead, string $sumber, string $platform): string
    {
        $datePart = \Carbon\Carbon::parse($tanggalLead)->format('ymd');
        $sourceCode = static::sourceAbbreviation($sumber);
        $platformCode = static::platformAbbreviation($platform);
        $prefix = "{$datePart}-{$sourceCode}-{$platformCode}-";

        $lastLead = static::where('id_lead', 'like', "{$prefix}%")
            ->orderBy('id_lead', 'desc')
            ->first();

        if ($lastLead) {
            $lastSeq = (int) substr($lastLead->id_lead, -2);
            $seq = str_pad($lastSeq + 1, 2, '0', STR_PAD_LEFT);
        } else {
            $seq = '01';
        }

        return $prefix . $seq;
    }
}
