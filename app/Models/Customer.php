<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'phone', 'email'];

    public function applications(): HasMany
    {
        return $this->hasMany(ConsumerApplication::class);
    }

    public function legacyIdentities(): HasMany
    {
        return $this->hasMany(ConsumerLegacyIdentity::class);
    }
}
