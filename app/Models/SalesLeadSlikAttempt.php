<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesLeadSlikAttempt extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime', 'metadata' => 'array'];
    }
}
