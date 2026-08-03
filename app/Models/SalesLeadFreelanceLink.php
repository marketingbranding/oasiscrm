<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesLeadFreelanceLink extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['converted_at' => 'datetime', 'metadata' => 'array'];
    }
}
