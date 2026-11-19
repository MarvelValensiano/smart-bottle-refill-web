<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MqttMessage extends Model
{
    protected $fillable = [
        'device_id',
        'topic',
        'payload',
        'simulated',
    ];

    protected function casts(): array
    {
        return [
            'simulated' => 'boolean',
        ];
    }
}