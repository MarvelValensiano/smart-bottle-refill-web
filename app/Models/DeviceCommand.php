<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceCommand extends Model
{
    protected $fillable = [
        'command_id',
        'device_id',
        'refill_id',
        'target_boot_id',
        'command_sequence',
        'based_on_sequence',
        'type',
        'payload',
        'status',
        'lease_ms',
        'expires_at',
        'published_at',
        'acknowledged_at',
        'ack_payload',
        'error_message',
    ];

    protected $casts = [
        'command_sequence' => 'integer',
        'based_on_sequence' => 'integer',
        'payload' => 'array',
        'ack_payload' => 'array',
        'lease_ms' => 'integer',
        'expires_at' => 'datetime',
        'published_at' => 'datetime',
        'acknowledged_at' => 'datetime',
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
