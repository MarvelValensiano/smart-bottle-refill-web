<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceEvent extends Model
{
    protected $fillable = [
        'event_id',
        'device_id',
        'refill_id',
        'type',
        'severity',
        'message',
        'context',
        'occurred_at',
        'resolved_at',
    ];

    protected $casts = [
        'context' => 'array',
        'occurred_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function refill(): BelongsTo
    {
        return $this->belongsTo(Refill::class);
    }
}
