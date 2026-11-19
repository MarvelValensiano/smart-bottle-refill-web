<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bottle extends Model
{
    protected $fillable = [
        'code',
        'name',
        'athlete_id',
        'empty_weight_g',
        'water_capacity_g',
    ];

    protected $casts = [
        'empty_weight_g' => 'float',
        'water_capacity_g' => 'float',
    ];

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    public function deviceSettings(): HasMany
    {
        return $this->hasMany(DeviceSetting::class);
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
