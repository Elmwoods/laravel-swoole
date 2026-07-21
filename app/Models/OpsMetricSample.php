<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpsMetricSample extends Model
{
    protected $fillable = [
        'cpu_load',
        'load1',
        'memory_used_percent',
        'swap_used_percent',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'cpu_load' => 'float',
            'load1' => 'float',
            'memory_used_percent' => 'float',
            'swap_used_percent' => 'float',
            'captured_at' => 'datetime',
        ];
    }
}
