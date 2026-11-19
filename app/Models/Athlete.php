<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Athlete extends Model
{
    protected $fillable = [
        'code',
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function bottles(): HasMany
    {
        return $this->hasMany(Bottle::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(UsageSession::class);
    }

    public function refills(): HasMany
    {
        return $this->hasMany(Refill::class);
    }
}
