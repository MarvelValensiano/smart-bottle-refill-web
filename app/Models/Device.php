<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Device extends Model
{
    protected $fillable = [
        'code',
        'name',
        'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    public function setting(): HasOne
    {
        return $this->hasOne(DeviceSetting::class);
    }

    public function telemetries(): HasMany
    {
        return $this->hasMany(Telemetry::class);
    }
}