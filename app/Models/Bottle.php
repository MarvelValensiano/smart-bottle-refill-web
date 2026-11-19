<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bottle extends Model
{
    protected $fillable = [
        'code',
        'name',
        'empty_weight_g',
        'water_capacity_g',
    ];

    protected $casts = [
        'empty_weight_g' => 'float',
        'water_capacity_g' => 'float',
    ];

    public function deviceSettings(): HasMany
    {
        return $this->hasMany(DeviceSetting::class);
    }
}