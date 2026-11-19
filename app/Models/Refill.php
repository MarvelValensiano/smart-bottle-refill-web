<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Refill extends Model
{
    protected $fillable = [
        'refill_id',
        'device_id',
        'bottle_id',
        'athlete_id',
        'session_id',
        'status',
        'bottle_empty_weight_g',
        'water_capacity_g',
        'target_percent',
        'target_total_weight_g',
        'start_total_weight_g',
        'end_total_weight_g',
        'added_water_g',
        'retry_count',
        'started_at',
        'finished_at',
        'pump_off_confirmed_at',
        'finish_reason',
        'is_success_counted',
    ];

    protected $casts = [
        'target_percent' => 'float',
        'target_total_weight_g' => 'float',
        'start_total_weight_g' => 'float',
        'end_total_weight_g' => 'float',
        'added_water_g' => 'float',
        'retry_count' => 'integer',
        'is_success_counted' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'pump_off_confirmed_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function bottle(): BelongsTo
    {
        return $this->belongsTo(Bottle::class);
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(UsageSession::class, 'session_id');
    }

    public function commands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class, 'refill_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeviceEvent::class, 'refill_id');
    }
}
