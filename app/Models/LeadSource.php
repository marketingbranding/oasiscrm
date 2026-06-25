<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadSource extends Model
{
    protected $fillable = [
        'name',
        'is_active',
    ];
}
