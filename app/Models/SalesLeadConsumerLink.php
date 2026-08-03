<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesLeadConsumerLink extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['converted_at' => 'datetime', 'payload' => 'array', 'metadata' => 'array'];
    }
}
