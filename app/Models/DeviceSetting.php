<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceSetting extends Model
{
    protected $fillable = [
        'device_id',
        'bottle_id',
        'calibration_factor',
        'tare_offset',
        'tared_at',
        'start_percent',
        'stop_percent',
        'zero_tolerance_g',
        'bottle_present_g',
        'bottle_removed_g',
        'stable_range_g',
        'stable_duration_ms',
        'sensor_timeout_ms',
        'no_gain_timeout_ms',
        'min_gain_g',
        'weight_drop_g',
        'retry_delay_ms',
        'auto_enabled',
    ];

    protected $casts = [
        'calibration_factor' => 'float',
        'tare_offset' => 'float',
        'tared_at' => 'datetime',
        'start_percent' => 'float',
        'stop_percent' => 'float',
        'zero_tolerance_g' => 'float',
        'bottle_present_g' => 'float',
        'bottle_removed_g' => 'float',
        'stable_range_g' => 'float',
        'stable_duration_ms' => 'integer',
        'sensor_timeout_ms' => 'integer',
        'no_gain_timeout_ms' => 'integer',
        'min_gain_g' => 'float',
        'weight_drop_g' => 'float',
        'retry_delay_ms' => 'integer',
        'auto_enabled' => 'boolean',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function bottle(): BelongsTo
    {
        return $this->belongsTo(Bottle::class);
    }
}