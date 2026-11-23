<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UsageSession extends Model
{
    protected $fillable = [
        'session_code',
        'device_id',
        'athlete_id',
        'bottle_id',
        'status',
        'refill_permitted',
        'is_locked',
        'lock_reason',
        'bottle_empty_weight_g',
        'water_capacity_g',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'refill_permitted' => 'boolean',
        'is_locked' => 'boolean',
        'bottle_empty_weight_g' => 'float',
        'water_capacity_g' => 'float',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    public function bottle(): BelongsTo
    {
        return $this->belongsTo(Bottle::class);
    }

    public function refills(): HasMany
    {
        return $this->hasMany(Refill::class, 'session_id');
    }
}
