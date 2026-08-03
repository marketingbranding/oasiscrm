<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesLeadAkadLink extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['akad_at' => 'datetime', 'metadata' => 'array'];
    }
}
