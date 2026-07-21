<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpsRedisMetricSample extends Model
{
    protected $fillable = [
        'ops',
        'clients',
        'memory_mb',
        'hit_rate',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'ops' => 'integer',
            'clients' => 'integer',
            'memory_mb' => 'float',
            'hit_rate' => 'float',
            'captured_at' => 'datetime',
        ];
    }
}
