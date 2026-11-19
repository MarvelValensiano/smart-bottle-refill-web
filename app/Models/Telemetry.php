<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Telemetry extends Model
{
    protected $table = 'telemetries';

    protected $fillable = [
        'device_id',
        'boot_id',
        'sequence',
        'uptime_ms',
        'sensor_ready',
        'raw_weight',
        'pump_on',
        'total_weight_g',
        'water_weight_g',
        'water_percent',
        'bottle_present',
        'calibration_factor',
        'tare_offset',
        'payload',
        'received_at',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'uptime_ms' => 'integer',
        'sensor_ready' => 'boolean',
        'raw_weight' => 'integer',
        'pump_on' => 'boolean',
        'total_weight_g' => 'float',
        'water_weight_g' => 'float',
        'water_percent' => 'float',
        'bottle_present' => 'boolean',
        'calibration_factor' => 'float',
        'tare_offset' => 'float',
        'payload' => 'array',
        'received_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}